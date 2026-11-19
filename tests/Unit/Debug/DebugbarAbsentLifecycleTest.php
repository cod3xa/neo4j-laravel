<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit\Debug;

use Illuminate\Database\Events\QueryExecuted;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Databags\SummarizedResult;
use Mockery;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use Neo4j\Neo4jLaravel\Neo4jServiceProvider;
use Neo4j\Neo4jLaravel\Tests\Support\StubsConnectionSession;
use Orchestra\Testbench\TestCase;

/**
 * Neo4j works without Laravel Debugbar; QueryExecuted still fires for listeners.
 */
class DebugbarAbsentLifecycleTest extends TestCase
{
    use StubsConnectionSession;

    protected function getPackageProviders($app): array
    {
        return [Neo4jServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'neo4j');
        $app['config']->set('database.connections.neo4j', [
            'driver' => 'neo4j',
            'url' => 'bolt://localhost:7687',
            'username' => 'neo4j',
            'password' => 'x',
            'database' => 'neo4j',
        ]);
    }

    public function test_does_not_require_debugbar_binding(): void
    {
        $this->assertFalse($this->app->bound('debugbar'));
        $this->assertFalse(class_exists(\Neo4j\Neo4jLaravel\Debug\Neo4jQueryCollector::class));
        $this->assertFalse(class_exists(\Neo4j\Neo4jLaravel\Debug\Neo4jDebugServiceProvider::class));
    }

    public function test_neo4j_connection_works_without_debugbar(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $session = $this->stubConnectionSession($client, 'neo4j');
        $summary = null;
        $result = new SummarizedResult($summary);
        $session->shouldReceive('readTransaction')->once()->andReturn($result);
        $this->app->instance(\Laudis\Neo4j\Client::class, $client);
        $this->app->instance(ClientInterface::class, $client);
        $this->app->make('db')->purge('neo4j');

        $received = null;
        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$received): void {
            $received = $event;
        });

        /** @var Neo4jConnection $connection */
        $connection = $this->app->make('db')->connection('neo4j');
        $this->assertInstanceOf(Neo4jConnection::class, $connection);
        $connection->enableQueryLog();

        $connection->read('MATCH (n) RETURN n', []);

        $this->assertCount(1, $connection->getQueryLog());
        $this->assertSame('MATCH (n) RETURN n', $connection->getQueryLog()[0]['cypher']);
        $this->assertInstanceOf(QueryExecuted::class, $received);
        $this->assertSame('MATCH (n) RETURN n', $received->sql);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
