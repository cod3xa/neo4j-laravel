<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit\Schema;

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
use Neo4j\Neo4jLaravel\Schema\Neo4jSchemaBuilder;
use Neo4j\Neo4jLaravel\Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\UriInterface;
use RuntimeException;

final class Neo4jSchemaBuilderTest extends TestCase
{
    /** @var list<string> */
    private array $statements = [];

    public function testNodeBuildsUniqueAndIndexStatements(): void
    {
        $builder = $this->connection()->getSchemaBuilder();
        self::assertInstanceOf(Neo4jSchemaBuilder::class, $builder);

        $builder->node('Person', function ($node): void {
            $node->unique('email');
            $node->index('name');
        });

        self::assertSame([
            'CREATE CONSTRAINT person_email_unique IF NOT EXISTS FOR (n:Person) REQUIRE n.email IS UNIQUE',
            'CREATE INDEX person_name_index IF NOT EXISTS FOR (n:Person) ON (n.name)',
        ], $this->statements);
    }

    public function testRelationshipBuildsIndexStatement(): void
    {
        $this->connection()->getSchemaBuilder()->relationship('ACTED_IN', function ($relationship): void {
            $relationship->index('role');
        });

        self::assertSame([
            'CREATE INDEX acted_in_role_index IF NOT EXISTS FOR ()-[r:ACTED_IN]-() ON (r.role)',
        ], $this->statements);
    }

    public function testCreateTableIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Schema::create() is not supported for Neo4j');

        $this->connection()->getSchemaBuilder()->create('people', function (): void {
        });
    }

    private function connection(): Neo4jConnection
    {
        $this->statements = [];

        /** @var TransactionInterface&MockObject $tx */
        $tx = $this->createMock(TransactionInterface::class);
        $tx->method('run')->willReturnCallback(function (string $cypher): SummarizedResult {
            $this->statements[] = $cypher;
            $counters = new SummaryCounters();
            $summary = new ResultSummary(
                $counters,
                new DatabaseInfo('neo4j'),
                new CypherList(),
                null,
                null,
                new Statement($cypher, []),
                QueryTypeEnum::fromCounters($counters),
                0,
                0,
                new ServerInfo($this->createMock(UriInterface::class), ConnectionProtocol::BOLT_V5(), 'test'),
            );

            return new SummarizedResult($summary);
        });

        /** @var ClientInterface&MockObject $client */
        $client = $this->createMock(ClientInterface::class);
        $client->method('writeTransaction')->willReturnCallback(static fn (callable $handler): mixed => $handler($tx));

        $connection = new Neo4jConnection($client, 'neo4j', '', [
            'name' => 'neo4j',
            'driver' => 'neo4j',
        ]);
        $connection->setEventDispatcher($this->app['events']);

        return $connection;
    }
}
