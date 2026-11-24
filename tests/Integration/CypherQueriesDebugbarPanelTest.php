<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Illuminate\Support\Facades\DB;
use Neo4j\Neo4jLaravel\Tests\TestCase;
use ReflectionMethod;

/**
 * Live Neo4j + Laravel Debugbar: Cypher appears in the shared Queries tab.
 */
class CypherQueriesDebugbarPanelTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.debug', true);
        $app['config']->set('debugbar.enabled', true);
        $app['config']->set('debugbar.collectors.db', true);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/__cypher_debugbar_live', function () {
            DB::connection('neo4j')->write(
                'CREATE (n:CypherPanelLive {name: $name}) RETURN n',
                ['name' => 'live-panel']
            );

            return response(
                '<html><head><title>cypher-panel</title></head><body>live</body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            );
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        /** @var LaravelDebugbar $debugbar */
        $debugbar = $this->app->make('debugbar');
        $debugbar->enable();
        $debugbar->boot();
        DB::connection('neo4j')->enableQueryLog();
    }

    public function test_live_query_appears_in_queries_tab_dataset(): void
    {
        /** @var LaravelDebugbar $debugbar */
        $debugbar = $this->app->make('debugbar');
        $this->assertTrue($debugbar->hasCollector('queries'));
        $this->assertFalse($debugbar->hasCollector('neo4j'));

        $cypher = 'CREATE (n:CypherPanelLive {name: $name}) RETURN n';
        DB::connection('neo4j')->write($cypher, ['name' => 'dataset']);

        $dataset = $debugbar->getData();
        $this->assertArrayHasKey('queries', $dataset);
        $this->assertArrayNotHasKey('neo4j', $dataset);

        $matched = array_values(array_filter(
            $dataset['queries']['statements'],
            static fn (array $row): bool => str_contains((string) ($row['sql'] ?? ''), 'CypherPanelLive')
        ));
        $this->assertNotEmpty($matched);
        $this->assertSame('neo4j', $matched[0]['connection']);

        $js = $this->controlsJs($debugbar);
        $this->assertStringContainsString('addTab("queries"', $js);
        $this->assertStringNotContainsString('Cypher Queries', $js);
        $this->assertStringNotContainsString('addTab("neo4j"', $js);
    }

    public function test_http_html_response_injects_debugbar_with_cypher_in_queries_tab(): void
    {
        /** @var LaravelDebugbar $debugbar */
        $debugbar = $this->app->make('debugbar');

        $response = $this->get('/__cypher_debugbar_live');
        $response->assertOk();

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('phpdebugbar', $html);
        $this->assertStringContainsString('addTab("queries"', $html);
        $this->assertStringNotContainsString('Cypher Queries', $html);
        $this->assertStringContainsString('CypherPanelLive', $html);

        $dataset = $debugbar->getData();
        $this->assertArrayHasKey('queries', $dataset);
        $this->assertGreaterThanOrEqual(1, $dataset['queries']['nb_statements']);
    }

    private function controlsJs(LaravelDebugbar $debugbar): string
    {
        $method = new ReflectionMethod($debugbar->getJavascriptRenderer(), 'getJsControlsDefinitionCode');
        $method->setAccessible(true);

        return (string) $method->invoke($debugbar->getJavascriptRenderer(), 'phpdebugbar');
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('neo4j')->write('MATCH (n:CypherPanelLive) DELETE n');
        } catch (\Throwable) {
            // Ignore cleanup failures so test failures stay primary.
        }

        parent::tearDown();
    }
}
