<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use Neo4j\Neo4jLaravel\Neo4jServiceProvider;
use Orchestra\Testbench\TestCase;

class UseDatabaseTest extends TestCase
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

    public function testCanSwitchDatabase(): void
    {
        /** @var Neo4jConnection $connection */
        $connection = DB::connection('neo4j');

        $this->assertEquals('neo4j', $connection->getDatabaseName());

        $connection->useDatabase('other-db');

        $this->assertEquals('other-db', $connection->getDatabaseName());
        $this->assertSame('other-db', $connection->sessionConfiguration()->getDatabase());
    }

    public function testDriverAliasMatchesConnectionName(): void
    {
        /** @var Neo4jConnection $connection */
        $connection = DB::connection('neo4j');

        $this->assertSame('neo4j', $connection->driverAlias());
    }
}
