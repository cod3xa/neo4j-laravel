<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit\Decorators;

use Illuminate\Database\Events\QueryExecuted;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\DriverInterface;
use Laudis\Neo4j\Contracts\SessionInterface;
use Laudis\Neo4j\Contracts\UnmanagedTransactionInterface;
use Laudis\Neo4j\Databags\SessionConfiguration;
use Laudis\Neo4j\Databags\SummarizedResult;
use Mockery;
use Mockery\MockInterface;
use Neo4j\Neo4jLaravel\Decorators\LaravelNeo4jClient;
use Neo4j\Neo4jLaravel\Decorators\LaravelNeo4jDriver;
use Neo4j\Neo4jLaravel\Decorators\LaravelNeo4jSession;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use Neo4j\Neo4jLaravel\Neo4jServiceProvider;
use Neo4j\Neo4jLaravel\Tests\Support\StubsConnectionSession;
use Orchestra\Testbench\TestCase;

class LaravelNeo4jDecoratorsTest extends TestCase
{
    use StubsConnectionSession;

    private Neo4jConnection $connection;

    /** @var ClientInterface&MockInterface */
    private ClientInterface $innerClient;

    /** @var SessionInterface&MockInterface */
    private SessionInterface $innerSession;

    /** @var list<QueryExecuted> */
    private array $executed = [];

    protected function getPackageProviders($app): array
    {
        return [Neo4jServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->innerClient = Mockery::mock(ClientInterface::class);
        $this->innerSession = $this->stubConnectionSession($this->innerClient, 'neo4j');
        $this->executed = [];

        $this->connection = new Neo4jConnection(
            $this->innerClient,
            'neo4j',
            '',
            ['name' => 'neo4j']
        );
        $this->connection->setEventDispatcher($this->app['events']);

        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            $this->executed[] = $event;
        });
    }

    public function test_get_client_returns_capturing_decorator(): void
    {
        $client = $this->connection->getClient();

        $this->assertInstanceOf(LaravelNeo4jClient::class, $client);
        $this->assertSame($client, $this->connection->getClient());
    }

    public function test_client_run_dispatches_query_executed(): void
    {
        $summary = null;
        $result = new SummarizedResult($summary);
        $cypher = 'MATCH (n:DecoratorClient) RETURN n';
        $params = ['id' => 1];

        $this->innerClient->shouldReceive('run')
            ->once()
            ->with($cypher, $params, 'neo4j')
            ->andReturn($result);

        $actual = $this->connection->getClient()->run($cypher, $params);

        $this->assertSame($result, $actual);
        $this->assertCount(1, $this->executed);
        $this->assertSame($cypher, $this->executed[0]->sql);
        $this->assertSame($params, $this->executed[0]->bindings);
    }

    public function test_session_run_dispatches_query_executed(): void
    {
        $summary = null;
        $result = new SummarizedResult($summary);
        $cypher = 'MATCH (n:DecoratorSession) RETURN n';

        $innerSession = Mockery::mock(SessionInterface::class);
        $innerSession->shouldReceive('run')
            ->once()
            ->with($cypher, [], null)
            ->andReturn($result);

        $session = new LaravelNeo4jSession($innerSession, $this->connection);
        $actual = $session->run($cypher);

        $this->assertSame($result, $actual);
        $this->assertCount(1, $this->executed);
        $this->assertSame($cypher, $this->executed[0]->sql);
    }

    public function test_driver_create_session_returns_capturing_session(): void
    {
        $innerSession = Mockery::mock(SessionInterface::class);
        $innerDriver = Mockery::mock(DriverInterface::class);
        $innerDriver->shouldReceive('createSession')
            ->once()
            ->with(Mockery::type(SessionConfiguration::class))
            ->andReturn($innerSession);

        $driver = new LaravelNeo4jDriver($innerDriver, $this->connection);
        $session = $driver->createSession();

        $this->assertInstanceOf(LaravelNeo4jSession::class, $session);
    }

    public function test_client_get_driver_returns_capturing_driver(): void
    {
        $innerDriver = Mockery::mock(DriverInterface::class);
        $this->innerClient->shouldReceive('getDriver')
            ->once()
            ->with('neo4j')
            ->andReturn($innerDriver);

        $driver = $this->connection->getClient()->getDriver('neo4j');

        $this->assertInstanceOf(LaravelNeo4jDriver::class, $driver);
    }

    public function test_connection_write_does_not_double_capture_via_raw_client(): void
    {
        $summary = null;
        $result = new SummarizedResult($summary);
        $cypher = 'CREATE (n:NoDouble) RETURN n';

        $this->innerSession->shouldReceive('writeTransaction')
            ->once()
            ->andReturnUsing(function (callable $handler) use ($result, $cypher) {
                $tx = Mockery::mock(UnmanagedTransactionInterface::class);
                $tx->shouldReceive('run')->once()->with($cypher, [])->andReturn($result);

                return $handler($tx);
            });

        $this->connection->write($cypher, []);

        $this->assertCount(1, $this->executed);
        $this->assertSame($cypher, $this->executed[0]->sql);
    }

    public function test_container_session_is_capturing_decorator(): void
    {
        $summary = null;
        $result = new SummarizedResult($summary);

        $innerSession = Mockery::mock(SessionInterface::class);
        $innerSession->shouldReceive('run')
            ->once()
            ->with('RETURN 1', [], null)
            ->andReturn($result);

        $innerDriver = Mockery::mock(DriverInterface::class);
        $innerDriver->shouldReceive('createSession')->andReturn($innerSession);

        $innerClient = Mockery::mock(ClientInterface::class);
        $innerClient->shouldReceive('getDriver')->andReturn($innerDriver);

        $this->app['config']->set('database.default', 'neo4j');
        $this->app['config']->set('database.connections.neo4j', [
            'driver' => 'neo4j',
            'url' => 'bolt://localhost:7687',
            'username' => 'neo4j',
            'password' => 'x',
            'database' => 'neo4j',
        ]);

        $this->app->instance(\Laudis\Neo4j\Client::class, $innerClient);
        $this->app->make('db')->purge('neo4j');

        // Force connection (and decorated ClientInterface) to use our mock client.
        $this->app->make('db')->connection('neo4j');

        $this->executed = [];

        $session = $this->app->make(SessionInterface::class);
        $this->assertInstanceOf(LaravelNeo4jSession::class, $session);

        $session->run('RETURN 1');
        $this->assertCount(1, $this->executed);
        $this->assertSame('RETURN 1', $this->executed[0]->sql);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
