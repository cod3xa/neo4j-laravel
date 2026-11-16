<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

use Barryvdh\Debugbar\LaravelDebugbar;
use Illuminate\Support\Facades\DB;
use Neo4j\Neo4jLaravel\Tests\TestCase;

class Neo4jDebugbarTest extends TestCase
{
    private LaravelDebugbar $debugbar;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('debugbar.enabled', true);
        $app['config']->set('debugbar.collectors.db', true);
    }

    protected function setUp(): void
    {
        if (! class_exists(LaravelDebugbar::class)) {
            $this->markTestSkipped('barryvdh/laravel-debugbar is not installed (Laravel 13 CI).');
        }

        parent::setUp();

        $this->debugbar = $this->app->make(LaravelDebugbar::class);
        $this->debugbar->enable();
        $this->debugbar->boot();

        $this->assertTrue($this->debugbar->hasCollector('queries'));
        $this->assertFalse($this->debugbar->hasCollector('neo4j'));

        DB::connection('neo4j')->enableQueryLog();
    }

    public function test_it_logs_write_queries(): void
    {
        DB::connection('neo4j')->write(
            'CREATE (n:TestNode {name: $name}) RETURN n',
            ['name' => 'Test Node']
        );

        $statement = $this->findQueryStatement('TestNode');
        $this->assertNotNull($statement);
        $this->assertStringContainsString('CREATE (n:TestNode', $statement['sql']);
        $this->assertSame('neo4j', $statement['connection']);
    }

    public function test_it_logs_read_queries(): void
    {
        DB::connection('neo4j')->write(
            'CREATE (n:TestNode {name: $name})',
            ['name' => 'Test Node']
        );

        $this->resetQueriesCollector();

        DB::connection('neo4j')->read(
            'MATCH (n:TestNode {name: $name}) RETURN n',
            ['name' => 'Test Node']
        );

        $statement = $this->findQueryStatement('MATCH (n:TestNode');
        $this->assertNotNull($statement);
        $this->assertStringContainsString('MATCH (n:TestNode', $statement['sql']);
    }

    public function test_it_logs_multiple_queries(): void
    {
        $this->resetQueriesCollector();
        $connection = DB::connection('neo4j');

        $connection->write('CREATE (n:TestNode {name: $name})', ['name' => 'Node 1']);
        $connection->write('CREATE (n:TestNode {name: $name})', ['name' => 'Node 2']);
        $connection->read('MATCH (n:TestNode) RETURN n');

        $neo4jStatements = $this->neo4jStatements();
        $this->assertGreaterThanOrEqual(3, count($neo4jStatements));
    }

    public function test_it_logs_query_time(): void
    {
        $this->resetQueriesCollector();

        DB::connection('neo4j')->write('
            UNWIND range(1, 1000) AS i
            CREATE (n:TestNode {value: i})
        ');

        $statement = $this->findQueryStatement('UNWIND range');
        $this->assertNotNull($statement);
        $this->assertGreaterThan(0, $statement['duration']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function neo4jStatements(): array
    {
        $dataset = $this->debugbar->getData();
        $statements = $dataset['queries']['statements'] ?? [];

        return array_values(array_filter(
            $statements,
            static fn (array $row): bool => ($row['connection'] ?? '') === 'neo4j'
                || str_contains((string) ($row['sql'] ?? ''), 'TestNode')
                || str_contains((string) ($row['sql'] ?? ''), 'UNWIND')
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findQueryStatement(string $needle): ?array
    {
        foreach ($this->neo4jStatements() as $statement) {
            if (str_contains((string) ($statement['sql'] ?? ''), $needle)) {
                return $statement;
            }
        }

        return null;
    }

    private function resetQueriesCollector(): void
    {
        if (method_exists($this->debugbar['queries'], 'reset')) {
            $this->debugbar['queries']->reset();
        }
    }

    protected function tearDown(): void
    {
        DB::connection('neo4j')->write('MATCH (n:TestNode) DELETE n');

        parent::tearDown();
    }
}
