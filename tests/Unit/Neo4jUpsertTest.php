<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Processors\Processor;
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
use Neo4j\Neo4jLaravel\Neo4jQueryBuilder;
use Neo4j\Neo4jLaravel\Neo4jQueryGrammar;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;

final class Neo4jUpsertTest extends TestCase
{
    /** @var list<array{cypher: string, params: array<string, mixed>}> */
    private array $statements = [];

    public function testCompilesUpsertAsSingleUnwindMerge(): void
    {
        $grammar = new Neo4jQueryGrammar();
        $builder = $this->builder()->from('Person');
        $rows = [['email' => 'a@example.com', 'name' => 'Ann', 'age' => 30]];

        self::assertSame(
            'UNWIND $rows AS row MERGE (n:Person {email: row.email}) '
                .'ON CREATE SET n.name = row.name, n.age = row.age '
                .'ON MATCH SET n.name = row.name',
            $grammar->compileUpsert($builder, $rows, ['email'], ['name'])
        );
        self::assertSame(
            'UNWIND $rows AS row MERGE (n:Person {email: row.email}) '
                .'ON CREATE SET n.name = row.name, n.age = row.age '
                .'ON MATCH SET n.name = row.name, n.age = row.age',
            $grammar->compileUpsert($builder, $rows, ['email'], ['email', 'name', 'age'])
        );
    }

    public function testCompilesCompositeKeysAndStringKeyedUpdates(): void
    {
        $grammar = new Neo4jQueryGrammar();
        $builder = $this->builder()->from('Visit');
        $rows = [['user' => 'u1', 'page' => '/home', 'count' => 1]];

        self::assertSame(
            'UNWIND $rows AS row MERGE (n:Visit {user: row.user, page: row.page}) '
                .'ON CREATE SET n.count = row.count '
                .'ON MATCH SET n.count = n.count + 1, n.seen = $u0',
            $grammar->compileUpsert($builder, $rows, ['user', 'page'], [
                'count' => new Expression('n.count + 1'),
                'seen' => true,
            ])
        );
    }

    public function testCompilesMergeOnlyWhenEveryColumnIsUnique(): void
    {
        self::assertSame(
            'UNWIND $rows AS row MERGE (n:Tag {name: row.name})',
            (new Neo4jQueryGrammar())->compileUpsert($this->builder()->from('Tag'), [['name' => 'php']], ['name'], ['name'])
        );
    }

    public function testRejectsInvalidPropertyNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Neo4j identifier');

        (new Neo4jQueryGrammar())->compileUpsert($this->builder()->from('Person'), [['email' => 'a', 'na-me' => 'b']], ['email'], ['na-me']);
    }

    public function testUpsertSendsAllRowsAsOneListParameter(): void
    {
        $builder = $this->recordingConnection(nodesCreated: 1, propertiesSet: 5)->table('Person');

        $affected = $builder->upsert([
            ['email' => 'a@example.com', 'name' => 'Ann', 'active' => true],
            ['active' => false, 'name' => 'Bob', 'email' => 'b@example.com'],
        ], 'email', ['name', 'active']);

        self::assertSame(6, $affected);
        self::assertCount(1, $this->statements);
        self::assertSame(
            'UNWIND $rows AS row MERGE (n:Person {email: row.email}) '
                .'ON CREATE SET n.name = row.name, n.active = row.active '
                .'ON MATCH SET n.name = row.name, n.active = row.active',
            $this->statements[0]['cypher']
        );
        self::assertSame(['rows' => [
            ['email' => 'a@example.com', 'name' => 'Ann', 'active' => true],
            ['active' => false, 'name' => 'Bob', 'email' => 'b@example.com'],
        ]], $this->statements[0]['params']);
    }

    public function testUpsertDefaultsToUpdatingEveryColumnAndPreparesNestedValues(): void
    {
        $builder = $this->recordingConnection()->table('Person');

        $builder->upsert(['email' => 'a@example.com', 'born' => new \DateTimeImmutable('2026-01-02 03:04:05')], ['email']);

        self::assertSame(
            'UNWIND $rows AS row MERGE (n:Person {email: row.email}) '
                .'ON CREATE SET n.born = row.born ON MATCH SET n.born = row.born',
            $this->statements[0]['cypher']
        );
        self::assertSame(['rows' => [['email' => 'a@example.com', 'born' => '2026-01-02 03:04:05']]], $this->statements[0]['params']);
    }

    public function testUpsertBindsStringKeyedUpdateValues(): void
    {
        $builder = $this->recordingConnection()->table('Person');

        $builder->upsert([['email' => 'a@example.com', 'name' => 'Ann']], ['email'], ['name', 'verified' => true]);

        self::assertSame(true, $this->statements[0]['params']['u0']);
        self::assertStringEndsWith('ON MATCH SET n.name = row.name, n.verified = $u0', $this->statements[0]['cypher']);
    }

    public function testUpsertWithoutRowsRunsNothing(): void
    {
        self::assertSame(0, $this->recordingConnection()->table('Person')->upsert([], ['email']));
        self::assertSame([], $this->statements);
    }

    public function testUpsertWithEmptyUpdateFallsBackToInsert(): void
    {
        $this->recordingConnection()->table('Person')->upsert([['email' => 'a@example.com']], ['email'], []);

        self::assertSame('CREATE (n0:Person {email: $p0})', $this->statements[0]['cypher']);
    }

    public function testUpsertRejectsRowsWithDifferentProperties(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rows must all have the same properties');

        $this->recordingConnection()->table('Person')->upsert([
            ['email' => 'a@example.com', 'name' => 'Ann'],
            ['email' => 'b@example.com'],
        ], ['email']);
    }

    public function testUpsertRejectsMissingUniqueByProperty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('uniqueBy property [email] is missing');

        $this->recordingConnection()->table('Person')->upsert([['name' => 'Ann']], ['email']);
    }

    public function testUpsertRequiresUniqueBy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one uniqueBy property');

        $this->recordingConnection()->table('Person')->upsert([['name' => 'Ann']], []);
    }

    public function testUpsertRejectsRawExpressionsInRows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('row values cannot be raw expressions ([name])');

        $this->recordingConnection()->table('Person')->upsert([['email' => 'a', 'name' => new Expression('1')]], ['email']);
    }

    private function builder(): Neo4jQueryBuilder
    {
        return new Neo4jQueryBuilder(
            $this->createMock(ConnectionInterface::class),
            new Neo4jQueryGrammar(),
            new Processor()
        );
    }

    private function recordingConnection(int $nodesCreated = 0, int $propertiesSet = 0): Neo4jConnection
    {
        $tx = $this->createMock(TransactionInterface::class);
        $tx->method('run')->willReturnCallback(function (string $cypher, iterable $params = []) use ($nodesCreated, $propertiesSet): SummarizedResult {
            $this->statements[] = ['cypher' => $cypher, 'params' => [...$params]];
            $counters = new SummaryCounters(nodesCreated: $nodesCreated, propertiesSet: $propertiesSet);
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
