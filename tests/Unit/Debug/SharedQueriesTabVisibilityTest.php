<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit\Debug;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Fruitcake\LaravelDebugbar\ServiceProvider as DebugbarServiceProvider;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Databags\SummarizedResult;
use Mockery;
use Neo4j\Neo4jLaravel\Neo4jServiceProvider;
use Neo4j\Neo4jLaravel\Tests\Support\StubsConnectionSession;
use Orchestra\Testbench\TestCase;
use ReflectionMethod;

/**
 * End-to-end: Cypher executions appear in Laravel Debugbar's shared Queries tab.
 */
class SharedQueriesTabVisibilityTest extends TestCase
{
    use StubsConnectionSession;

    protected function setUp(): void
    {
        if (! class_exists(DebugbarServiceProvider::class)) {
            $this->markTestSkipped('barryvdh/laravel-debugbar is not installed');
        }

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            Neo4jServiceProvider::class,
            DebugbarServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', true);
        $app['config']->set('debugbar.enabled', true);
        $app['config']->set('debugbar.force_allow_enable', true);
        $app['config']->set('debugbar.collectors.db', true);
        $app['config']->set('database.default', 'neo4j');
        $app['config']->set('database.connections.neo4j', [
            'driver' => 'neo4j',
            'url' => 'bolt://localhost:7687',
            'username' => 'neo4j',
            'password' => 'x',
            'database' => 'neo4j',
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/__neo4j_debugbar_probe', function () {
            $summary = null;
            $result = new SummarizedResult($summary);

            $client = Mockery::mock(ClientInterface::class);
            $session = $this->stubConnectionSession($client, 'neo4j');
            $session->shouldReceive('readTransaction')->once()->andReturn($result);
            app()->instance(\Laudis\Neo4j\Client::class, $client);
            app()->instance(ClientInterface::class, $client);
            app('db')->purge('neo4j');

            app('db')->connection('neo4j')->read(
                'MATCH (n:PanelProbe) RETURN n LIMIT $limit',
                ['limit' => 1]
            );

            return response('<html><head><title>probe</title></head><body>ok</body></html>', 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        });
    }

    public function test_queries_tab_is_registered_without_cypher_collector(): void
    {
        /** @var LaravelDebugbar $debugbar */
        $debugbar = $this->app->make('debugbar');
        $debugbar->enable();
        $debugbar->boot();

        $this->assertTrue($debugbar->hasCollector('queries'));
        $this->assertFalse($debugbar->hasCollector('neo4j'));

        $js = $this->debugbarControlsJs($debugbar);
        $this->assertStringContainsString('addTab("queries"', $js);
        $this->assertStringNotContainsString('Cypher Queries', $js);
        $this->assertStringNotContainsString('addTab("neo4j"', $js);
    }

    public function test_cypher_appears_in_shared_queries_collector_dataset(): void
    {
        /** @var LaravelDebugbar $debugbar */
        $debugbar = $this->app->make('debugbar');
        $debugbar->enable();
        $debugbar->boot();

        $summary = null;
        $result = new SummarizedResult($summary);
        $client = Mockery::mock(ClientInterface::class);
        $session = $this->stubConnectionSession($client, 'neo4j');
        $session->shouldReceive('readTransaction')->once()->andReturn($result);
        $this->app->instance(\Laudis\Neo4j\Client::class, $client);
        $this->app->instance(ClientInterface::class, $client);
        $this->app->make('db')->purge('neo4j');

        $cypher = 'MATCH (n:PanelProbe) RETURN n LIMIT $limit';
        $this->app->make('db')->connection('neo4j')->read($cypher, ['limit' => 1]);

        $dataset = $debugbar->getData();
        $this->assertArrayHasKey('queries', $dataset);
        $this->assertArrayNotHasKey('neo4j', $dataset);
        $this->assertGreaterThanOrEqual(1, $dataset['queries']['nb_statements']);

        $queryStatements = array_values(array_filter(
            $dataset['queries']['statements'],
            static fn (array $row): bool => ($row['sql'] ?? '') === $cypher
                || str_contains((string) ($row['sql'] ?? ''), 'PanelProbe')
        ));
        $this->assertNotEmpty($queryStatements, 'Cypher query missing from shared Queries tab dataset');
        $this->assertSame('neo4j', $queryStatements[0]['connection']);
    }

    public function test_failed_cypher_appears_with_error_annotation_in_queries_tab(): void
    {
        /** @var LaravelDebugbar $debugbar */
        $debugbar = $this->app->make('debugbar');
        $debugbar->enable();
        $debugbar->boot();

        $client = Mockery::mock(ClientInterface::class);
        $session = $this->stubConnectionSession($client, 'neo4j');
        $session->shouldReceive('writeTransaction')
            ->once()
            ->andThrow(new \RuntimeException('syntax error here'));
        $this->app->instance(\Laudis\Neo4j\Client::class, $client);
        $this->app->instance(ClientInterface::class, $client);
        $this->app->make('db')->purge('neo4j');

        $cypher = 'THIS IS NOT VALID CYPHER';

        try {
            $this->app->make('db')->connection('neo4j')->write($cypher, []);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('syntax error here', $e->getMessage());
        }

        $dataset = $debugbar->getData();
        $matched = array_values(array_filter(
            $dataset['queries']['statements'] ?? [],
            static fn (array $row): bool => str_contains((string) ($row['sql'] ?? ''), 'Neo4j error: syntax error here')
        ));

        $this->assertNotEmpty($matched, 'Failed Cypher missing Neo4j error annotation in Queries tab');
        $this->assertStringContainsString($cypher, (string) $matched[0]['sql']);
    }

    public function test_http_response_includes_debugbar_queries_tab_with_cypher(): void
    {
        /** @var LaravelDebugbar $debugbar */
        $debugbar = $this->app->make('debugbar');
        $debugbar->enable();

        $response = $this->get('/__neo4j_debugbar_probe');
        $response->assertOk();

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('phpdebugbar', $html);
        $this->assertStringContainsString('addTab("queries"', $html);
        $this->assertStringNotContainsString('Cypher Queries', $html);
        $this->assertStringNotContainsString('addTab("neo4j"', $html);

        $dataset = $debugbar->getData();
        $this->assertArrayHasKey('queries', $dataset);
        $this->assertGreaterThanOrEqual(1, $dataset['queries']['nb_statements']);
    }

    private function debugbarControlsJs(LaravelDebugbar $debugbar): string
    {
        $renderer = $debugbar->getJavascriptRenderer();
        $method = new ReflectionMethod($renderer, 'getJsControlsDefinitionCode');
        $method->setAccessible(true);

        return (string) $method->invoke($renderer, 'phpdebugbar');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
