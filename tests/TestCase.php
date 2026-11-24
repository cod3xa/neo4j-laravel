<?php

namespace Neo4j\Neo4jLaravel\Tests;

use Neo4j\Neo4jLaravel\Neo4jServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        $providers = [
            Neo4jServiceProvider::class,
        ];

        // Optional: fruitcake/laravel-debugbar (v4+) or legacy barryvdh/laravel-debugbar.
        if (class_exists(\Fruitcake\LaravelDebugbar\ServiceProvider::class)) {
            $providers[] = \Fruitcake\LaravelDebugbar\ServiceProvider::class;
        } elseif (class_exists(\Barryvdh\Debugbar\ServiceProvider::class)) {
            $providers[] = \Barryvdh\Debugbar\ServiceProvider::class;
        }

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'neo4j');
        $app['config']->set('database.connections.neo4j', [
            'driver' => 'neo4j',
            'url' => sprintf(
                'bolt://%s:%s',
                env('NEO4J_HOST', 'neo4j'),
                env('NEO4J_PORT', '7687')
            ),
            'username' => env('NEO4J_USERNAME', 'neo4j'),
            'password' => env('NEO4J_PASSWORD', 'testtest'),
            'database' => env('NEO4J_DATABASE', 'neo4j'),
        ]);

        $app['config']->set('debugbar.enabled', true);
        // fruitcake/laravel-debugbar v4 disables itself in the "testing" env unless forced.
        $app['config']->set('debugbar.force_allow_enable', true);
    }
}
