<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit\Migrations;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
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
use Mockery;
use Neo4j\Neo4jLaravel\Migrations\ConnectionAwareMigrationRepository;
use Neo4j\Neo4jLaravel\Migrations\Neo4jMigrationRepository;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use Neo4j\Neo4jLaravel\Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\UriInterface;

final class Neo4jMigrationRepositoryTest extends TestCase
{
    /** @var list<string> */
    private array $statements = [];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testConnectionAwareDelegatesToNeo4jRepositoryForNeo4jDriver(): void
    {
        $relational = Mockery::mock(MigrationRepositoryInterface::class);
        $neo4j = Mockery::mock(MigrationRepositoryInterface::class);
        $neo4j->shouldReceive('setSource')->once()->with('neo4j');
        $relational->shouldReceive('setSource')->once()->with('neo4j');
        $neo4j->shouldReceive('getRan')->once()->andReturn(['a']);

        $client = $this->createMock(ClientInterface::class);
        $connection = new Neo4jConnection($client, 'neo4j', '', [
            'name' => 'neo4j',
            'driver' => 'neo4j',
        ]);

        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with('neo4j')->andReturn($connection);

        $aware = new ConnectionAwareMigrationRepository($relational, $neo4j, $resolver);
        $aware->setSource('neo4j');

        self::assertSame(['a'], $aware->getRan());
    }

    public function testCreateRepositoryCypherUsesDedicatedLabel(): void
    {
        $connection = $this->recordingConnection();

        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);

        $repository = new Neo4jMigrationRepository($resolver);
        $repository->setSource('neo4j');
        $repository->createRepository();

        self::assertSame([
            'CREATE CONSTRAINT _laravel_migration_migration_unique IF NOT EXISTS FOR (m:_LaravelMigration) REQUIRE m.migration IS UNIQUE',
        ], $this->statements);
    }

    private function recordingConnection(): Neo4jConnection
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
