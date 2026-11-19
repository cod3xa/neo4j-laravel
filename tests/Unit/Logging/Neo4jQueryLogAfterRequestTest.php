<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit\Logging;

use Laudis\Neo4j\Contracts\ClientInterface;
use Mockery;
use Neo4j\Neo4jLaravel\Logging\Neo4jQueryLogAfterRequest;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use Neo4j\Neo4jLaravel\Neo4jServiceProvider;
use Orchestra\Testbench\TestCase;

class Neo4jQueryLogAfterRequestTest extends TestCase
{
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

    public function test_flushes_to_default_log_when_channel_empty(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'neo4j_laravel_default_log_');
        $this->assertNotFalse($path);

        $this->app['config']->set('neo4j-laravel.query_log_channel', '');
        $this->app['config']->set('logging.default', 'test_default');
        $this->app['config']->set('logging.channels.test_default', [
            'driver' => 'single',
            'path' => $path,
            'level' => 'debug',
        ]);
        $this->app->forgetInstance('log');

        $client = Mockery::mock(ClientInterface::class);
        $this->app->instance(ClientInterface::class, $client);

        $connection = $this->app->make('db')->connection('neo4j');
        $this->assertInstanceOf(Neo4jConnection::class, $connection);
        $connection->enableQueryLog();
        $connection->logQuery('MATCH (n) RETURN n', ['k' => 'v'], 1.5);

        Neo4jQueryLogAfterRequest::flush($this->app);

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        $this->assertStringContainsString('MATCH (n) RETURN n', $contents);
        $this->assertSame([], $connection->getQueryLog());

        @unlink($path);
    }

    public function test_flushes_to_named_channel_when_configured(): void
    {
        $pathDefault = tempnam(sys_get_temp_dir(), 'neo4j_laravel_def_');
        $pathNeo4j = tempnam(sys_get_temp_dir(), 'neo4j_laravel_chan_');
        $this->assertNotFalse($pathDefault);
        $this->assertNotFalse($pathNeo4j);

        $this->app['config']->set('neo4j-laravel.query_log_channel', 'neo4j_queries');
        $this->app['config']->set('logging.default', 'test_default');
        $this->app['config']->set('logging.channels.test_default', [
            'driver' => 'single',
            'path' => $pathDefault,
            'level' => 'debug',
        ]);
        $this->app['config']->set('logging.channels.neo4j_queries', [
            'driver' => 'single',
            'path' => $pathNeo4j,
            'level' => 'debug',
        ]);
        $this->app->forgetInstance('log');

        $client = Mockery::mock(ClientInterface::class);
        $this->app->instance(ClientInterface::class, $client);

        $connection = $this->app->make('db')->connection('neo4j');
        $this->assertInstanceOf(Neo4jConnection::class, $connection);
        $connection->enableQueryLog();
        $connection->logQuery('CREATE (n)', [], 0.1);

        Neo4jQueryLogAfterRequest::flush($this->app);

        $this->assertStringContainsString('CREATE (n)', (string) file_get_contents($pathNeo4j));
        $this->assertSame('', trim((string) file_get_contents($pathDefault)));
        $this->assertSame([], $connection->getQueryLog());

        @unlink($pathDefault);
        @unlink($pathNeo4j);
    }

    public function test_uses_default_when_channel_name_not_in_logging_config(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'neo4j_laravel_fallback_');
        $this->assertNotFalse($path);

        $this->app['config']->set('neo4j-laravel.query_log_channel', 'missing_channel');
        $this->app['config']->set('logging.default', 'test_default');
        $this->app['config']->set('logging.channels.test_default', [
            'driver' => 'single',
            'path' => $path,
            'level' => 'debug',
        ]);
        $this->app->forgetInstance('log');

        $client = Mockery::mock(ClientInterface::class);
        $this->app->instance(ClientInterface::class, $client);

        $connection = $this->app->make('db')->connection('neo4j');
        $this->assertInstanceOf(Neo4jConnection::class, $connection);
        $connection->enableQueryLog();
        $connection->logQuery('RETURN 1', [], null);

        Neo4jQueryLogAfterRequest::flush($this->app);

        $this->assertStringContainsString('RETURN 1', (string) file_get_contents($path));
        $this->assertSame([], $connection->getQueryLog());

        @unlink($path);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
