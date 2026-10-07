<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit;

use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\TransactionInterface;
use Laudis\Neo4j\Databags\DatabaseInfo;
use Laudis\Neo4j\Databags\ResultSummary;
use Laudis\Neo4j\Databags\ServerInfo;
use Laudis\Neo4j\Databags\Statement;
use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Databags\SummaryCounters;
use Laudis\Neo4j\Enum\ConnectionProtocol;
use Laudis\Neo4j\Enum\QueryTypeEnum;
use Laudis\Neo4j\Types\CypherList;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;
use RuntimeException;

final class Neo4jBulkRelationshipTest extends TestCase
{
    /** @var list<array{cypher: string, params: array<string, mixed>}> */
    private array $statements = [];

    public function testInsertRelationshipsCreatesEveryRowInOneStatement(): void
    {
        $affected = $this->connection(relationshipsCreated: 2, propertiesSet: 1)->table('Person')->insertRelationships('ACTED_IN>', 'Movie', [
            ['from' => ['id' => 1], 'to' => ['id' => 2], 'properties' => ['roles' => ['Neo']]],
            ['from' => ['id' => 1], 'to' => ['id' => 3], 'properties' => ['roles' => ['John']]],
        ]);

        self::assertSame(3, $affected);
        self::assertCount(1, $this->statements);
        self::assertSame(
            'UNWIND $rows AS row MATCH (n:Person {id: row.from.id}) MATCH (related:Movie {id: row.to.id}) '
                .'CREATE (n)-[rel:ACTED_IN {roles: row.properties.roles}]->(related)',
            $this->statements[0]['cypher']
        );
        self::assertSame(['rows' => [
            ['from' => ['id' => 1], 'to' => ['id' => 2], 'properties' => ['roles' => ['Neo']]],
            ['from' => ['id' => 1], 'to' => ['id' => 3], 'properties' => ['roles' => ['John']]],
        ]], $this->statements[0]['params']);
    }

    public function testUpsertRelationshipsMergesAndSetsProperties(): void
    {
        $this->connection()->table('Job')->upsertRelationships('USES_CONNECTION>', 'QueueConnection', [
            ['from' => ['key' => 'SendInvoice'], 'to' => ['key' => 'redis'], 'properties' => ['queue' => 'mail', 'at' => new \DateTimeImmutable('2026-01-02 03:04:05')]],
        ]);

        self::assertSame(
            'UNWIND $rows AS row MATCH (n:Job {key: row.from.key}) MATCH (related:QueueConnection {key: row.to.key}) '
                .'MERGE (n)-[rel:USES_CONNECTION]->(related) SET rel.at = row.properties.at, rel.queue = row.properties.queue',
            $this->statements[0]['cypher']
        );
        self::assertSame('2026-01-02 03:04:05', $this->statements[0]['params']['rows'][0]['properties']['at']);
    }

    public function testIncomingDirectionAndCompositeKeys(): void
    {
        $this->connection()->table('Movie')->upsertRelationships('<ACTED_IN', 'Person', [
            ['from' => ['title' => 'Matrix', 'year' => 1999], 'to' => ['name' => 'Keanu']],
        ]);

        self::assertSame(
            'UNWIND $rows AS row MATCH (n:Movie {title: row.from.title, year: row.from.year}) '
                .'MATCH (related:Person {name: row.to.name}) MERGE (n)<-[rel:ACTED_IN]-(related)',
            $this->statements[0]['cypher']
        );
    }

    public function testSyncRelationshipsGroupsRowsPerFromNode(): void
    {
        $this->connection()->table('Job')->syncRelationships('USES_CONNECTION>', 'QueueConnection', [
            ['from' => ['key' => 'A'], 'to' => ['key' => 'redis']],
            ['from' => ['key' => 'B'], 'to' => ['key' => 'sqs']],
            ['from' => ['key' => 'A'], 'to' => ['key' => 'sqs']],
        ]);

        self::assertSame(
            'UNWIND $rows AS row MATCH (n:Job {key: row.from.key}) '
                .'OPTIONAL MATCH (n)-[stale:USES_CONNECTION]->(other:QueueConnection) '
                .'WHERE NOT any(target IN row.targets WHERE other.key = target.to.key) DELETE stale '
                .'WITH DISTINCT n, row UNWIND row.targets AS target '
                .'MATCH (related:QueueConnection {key: target.to.key}) MERGE (n)-[rel:USES_CONNECTION]->(related)',
            $this->statements[0]['cypher']
        );
        self::assertSame(['rows' => [
            ['from' => ['key' => 'A'], 'targets' => [['to' => ['key' => 'redis']], ['to' => ['key' => 'sqs']]]],
            ['from' => ['key' => 'B'], 'targets' => [['to' => ['key' => 'sqs']]]],
        ]], $this->statements[0]['params']);
    }

    public function testSyncRelationshipsSetsPropertiesFromEachTarget(): void
    {
        $this->connection()->table('Job')->syncRelationships('USES_CONNECTION>', 'QueueConnection', [
            ['from' => ['key' => 'A'], 'to' => ['key' => 'redis'], 'properties' => ['queue' => 'mail']],
        ]);

        self::assertStringEndsWith(
            'MERGE (n)-[rel:USES_CONNECTION]->(related) SET rel.queue = target.properties.queue',
            $this->statements[0]['cypher']
        );
    }

    public function testDeleteRelationshipsToGivenNodesOrToAll(): void
    {
        $connection = $this->connection(relationshipsDeleted: 2);

        $deleted = $connection->table('Person')->deleteRelationships('ACTED_IN>', 'Movie', [
            ['from' => ['id' => 1], 'to' => ['id' => 2]],
        ]);
        $connection->table('Person')->deleteRelationships('ACTED_IN>', 'Movie', [['from' => ['id' => 1]]]);

        self::assertSame(2, $deleted);
        self::assertSame(
            'UNWIND $rows AS row MATCH (n:Person {id: row.from.id}) MATCH (n)-[rel:ACTED_IN]->(related:Movie {id: row.to.id}) DELETE rel',
            $this->statements[0]['cypher']
        );
        self::assertSame(
            'UNWIND $rows AS row MATCH (n:Person {id: row.from.id}) MATCH (n)-[rel:ACTED_IN]->(related:Movie) DELETE rel',
            $this->statements[1]['cypher']
        );
    }

    public function testEmptyRowsRunNothing(): void
    {
        $builder = $this->connection()->table('Person');

        self::assertSame(0, $builder->insertRelationships('ACTED_IN>', 'Movie', []));
        self::assertSame(0, $builder->syncRelationships('ACTED_IN>', 'Movie', []));
        self::assertSame([], $this->statements);
    }

    public function testRejectsUndirectedTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('upsertRelationships() requires a directed type');

        $this->connection()->table('Person')->upsertRelationships('ACTED_IN', 'Movie', [['from' => ['id' => 1], 'to' => ['id' => 2]]]);
    }

    public function testRejectsRowsWithDifferentKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rows must all use the same from, to and properties keys');

        $this->connection()->table('Person')->insertRelationships('ACTED_IN>', 'Movie', [
            ['from' => ['id' => 1], 'to' => ['id' => 2], 'properties' => ['roles' => ['Neo']]],
            ['from' => ['id' => 1], 'to' => ['id' => 3]],
        ]);
    }

    public function testRejectsMissingToMap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('syncRelationships() rows require a non-empty to property map');

        $this->connection()->table('Person')->syncRelationships('ACTED_IN>', 'Movie', [['from' => ['id' => 1]]]);
    }

    public function testRejectsUnknownRowKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rows must be arrays with from, to and properties keys only');

        $this->connection()->table('Person')->insertRelationships('ACTED_IN>', 'Movie', [['from' => ['id' => 1], 'target' => ['id' => 2]]]);
    }

    public function testRejectsRawExpressions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('values cannot be raw expressions (to.id)');

        $this->connection()->table('Person')->insertRelationships('ACTED_IN>', 'Movie', [['from' => ['id' => 1], 'to' => ['id' => new Expression('1')]]]);
    }

    public function testRejectsPropertiesOnDelete(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('deleteRelationships() rows do not accept properties');

        $this->connection()->table('Person')->deleteRelationships('ACTED_IN>', 'Movie', [['from' => ['id' => 1], 'properties' => ['x' => 1]]]);
    }

    public function testRejectsInvalidPropertyNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Neo4j identifier');

        $this->connection()->table('Person')->insertRelationships('ACTED_IN>', 'Movie', [['from' => ['i-d' => 1], 'to' => ['id' => 2]]]);
    }

    public function testCannotBeCombinedWithMatchRelationship(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('insertRelationships() cannot be combined with matchRelationship()');

        $this->connection()->table('Person')->matchRelationship('KNOWS>', 'Person')
            ->insertRelationships('ACTED_IN>', 'Movie', [['from' => ['id' => 1], 'to' => ['id' => 2]]]);
    }

    private function connection(int $relationshipsCreated = 0, int $relationshipsDeleted = 0, int $propertiesSet = 0): Neo4jConnection
    {
        $tx = $this->createMock(TransactionInterface::class);
        $tx->method('run')->willReturnCallback(function (string $cypher, iterable $params = []) use ($relationshipsCreated, $relationshipsDeleted, $propertiesSet): SummarizedResult {
            $this->statements[] = ['cypher' => $cypher, 'params' => [...$params]];
            $counters = new SummaryCounters(
                propertiesSet: $propertiesSet,
                relationshipsCreated: $relationshipsCreated,
                relationshipsDeleted: $relationshipsDeleted,
            );
            $summary = new ResultSummary(
                $counters,
                new DatabaseInfo('neo4j'),
                new CypherList(),
                null,
                null,
                new Statement($cypher, [...$params]),
                QueryTypeEnum::fromCounters($counters),
                0,
                0,
                new ServerInfo($this->createMock(UriInterface::class), ConnectionProtocol::BOLT_V5(), 'test'),
            );

            return new SummarizedResult($summary);
        });

        $client = $this->createMock(ClientInterface::class);
        $client->method('writeTransaction')->willReturnCallback(static fn (callable $handler): mixed => $handler($tx));

        return new Neo4jConnection($client);
    }
}
