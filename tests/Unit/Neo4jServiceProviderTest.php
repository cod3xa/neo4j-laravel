<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit;

use Illuminate\Contracts\Container\BindingResolutionException;
use Laudis\Neo4j\Contracts\ClientInterface;
use Neo4j\Neo4jLaravel\Neo4jServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

class Neo4jServiceProviderTest extends OrchestraTestCase
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
            'password' => 'password',
            'database' => 'neo4j',
        ]);
    }

    public function testServiceProviderRegistersClient(): void
    {
        $client = $this->app->make(ClientInterface::class);
        $this->assertInstanceOf(ClientInterface::class, $client);
    }

    public function testServiceProviderRequiresValidDriver(): void
    {
        $this->app['config']->set('database.connections.neo4j.driver', 'invalid');

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('No Neo4j database connections are configured');

        $this->app->make(ClientInterface::class);
    }

    public function testServiceProviderAllowsSqlDefaultWithNeo4jSecondary(): void
    {
        $this->app['config']->set('database.default', 'sqlite');
        $this->app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $client = $this->app->make(ClientInterface::class);
        $this->assertInstanceOf(ClientInterface::class, $client);
        $this->assertTrue($client->hasDriver('neo4j'));
    }

    public function testServiceProviderRequiresValidUrl(): void
    {
        $this->app['config']->set('database.connections.neo4j.url', null);

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Missing required URL or host/port configuration for Neo4j connection');

        $this->app->make(ClientInterface::class);
    }

    public function testServiceProviderHandlesHostAndPort(): void
    {
        $this->app['config']->set('database.connections.neo4j', [
            'driver' => 'neo4j',
            'host' => 'localhost',
            'port' => 7687,
            'username' => 'neo4j',
            'password' => 'password',
            'database' => 'neo4j',
        ]);

        $client = $this->app->make(ClientInterface::class);
        $this->assertInstanceOf(ClientInterface::class, $client);
    }

    public function testServiceProviderHandlesMultipleConnections(): void
    {
        $this->app['config']->set('database.connections.neo4j_secondary', [
            'driver' => 'neo4j',
            'url' => 'bolt://localhost:7688',
            'username' => 'neo4j',
            'password' => 'password',
            'database' => 'neo4j',
        ]);

        $client = $this->app->make(ClientInterface::class);
        $this->assertInstanceOf(ClientInterface::class, $client);
    }

    public function testServiceProviderHandlesSslConfiguration(): void
    {
        $this->app['config']->set('database.connections.neo4j.ssl', [
            'mode' => 'enable',
            'verify_peer' => false,
        ]);

        $client = $this->app->make(ClientInterface::class);
        $this->assertInstanceOf(ClientInterface::class, $client);
    }

    public function testServiceProviderHandlesConnectionConfiguration(): void
    {
        $this->app['config']->set('database.connections.neo4j.connection', [
            'max_pool_size' => 50,
            'timeout' => 15,
        ]);

        $client = $this->app->make(ClientInterface::class);
        $this->assertInstanceOf(ClientInterface::class, $client);
    }

    public function testServiceProviderHandlesTransactionConfiguration(): void
    {
        $this->app['config']->set('database.connections.neo4j.transaction', [
            'timeout' => 45,
        ]);

        $client = $this->app->make(ClientInterface::class);
        $this->assertInstanceOf(ClientInterface::class, $client);
    }

    public function testServiceProviderHandlesKerberosAuth(): void
    {
        $this->app['config']->set('database.connections.neo4j', [
            'driver' => 'neo4j',
            'url' => 'bolt://localhost:7687',
            'auth_scheme' => 'kerberos',
            'ticket' => 'kerberos-ticket',
            'database' => 'neo4j',
        ]);

        $client = $this->app->make(ClientInterface::class);
        $this->assertInstanceOf(ClientInterface::class, $client);
    }

    public function testServiceProviderHandlesOidcAuth(): void
    {
        $this->app['config']->set('database.connections.neo4j', [
            'driver' => 'neo4j',
            'url' => 'bolt://localhost:7687',
            'auth_scheme' => 'oidc',
            'token' => 'oidc-token',
            'database' => 'neo4j',
        ]);

        $client = $this->app->make(ClientInterface::class);
        $this->assertInstanceOf(ClientInterface::class, $client);
    }

    public function testServiceProviderHandlesNoAuth(): void
    {
        $this->app['config']->set('database.connections.neo4j', [
            'driver' => 'neo4j',
            'url' => 'bolt://localhost:7687',
            'auth_scheme' => 'none',
            'database' => 'neo4j',
        ]);

        $client = $this->app->make(ClientInterface::class);
        $this->assertInstanceOf(ClientInterface::class, $client);
    }
}
