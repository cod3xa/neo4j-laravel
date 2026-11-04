<?php

namespace Neo4j\Neo4jLaravel;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Laudis\Neo4j\Client;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\DriverInterface;
use Laudis\Neo4j\Contracts\SessionInterface;
use Laudis\Neo4j\Contracts\TransactionInterface;
use Neo4j\Neo4jLaravel\Logging\Neo4jQueryLogAfterRequest;
use Neo4j\Neo4jLaravel\Migrations\ConnectionAwareMigrationRepository;
use Neo4j\Neo4jLaravel\Migrations\Neo4jMigrationRepository;

/**
 * @api
 */
final class Neo4jServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__) . '/config/neo4j-laravel.php',
            'neo4j-laravel'
        );

        $this->app->singleton(Client::class, function (Application $app): ClientInterface {
            $config = $app->make('config');
            $defaultConnection = $config->get('database.default');
            $connections = $config->get('database.connections');

            $factoryConnections = [];
            $defaultFound = false;

            foreach ($connections as $name => $connection) {
                if (isset($connection['driver']) && $connection['driver'] === 'neo4j') {
                    $this->validateConnection($name, $connection);

                    // Convert Laravel connection format to factory format
                    $factoryConnections[] = $this->formatConnectionForFactory($name, $connection);

                    // Check if default connection is configured
                    if ($name === $defaultConnection) {
                        $defaultFound = true;
                    }
                }
            }

            if (! $defaultFound) {
                throw new BindingResolutionException("Default Neo4j connection '$defaultConnection' is not configured or invalid");
            }

            $factory = new ClientFactory(
                null,
                null,
                null,
                $factoryConnections,
                null,
                null,
                $defaultConnection
            );

            return $factory->create();
        });

        $this->app->singleton(ClientInterface::class, function (Application $app): ClientInterface {
            $connection = DB::connection($app->make('config')->get('database.default'));
            assert($connection instanceof Neo4jConnection);

            return $connection->getClient();
        });

        $this->app->singleton(DriverInterface::class, function (Application $app): DriverInterface {
            return $app->make(ClientInterface::class)->getDriver(
                $app->make('config')->get('database.default')
            );
        });

        $this->app->bind(SessionInterface::class, function (Application $app): SessionInterface {
            return $app->make(DriverInterface::class)->createSession();
        });

        $this->app->bind(TransactionInterface::class, function (Application $app): TransactionInterface {
            return $app->make(SessionInterface::class)->beginTransaction();
        });

        $manager = $this->app->make('db');
        $manager->extend('neo4j', function (array $config, string $name) {
            $client = $this->app->make(Client::class);

            $config['name'] = $name;

            return new Neo4jConnection($client, $config['database'] ?? 'neo4j', '', $config);
        });

        $this->app->singleton('db.connection.neo4j', function (Application $app): Neo4jConnection {
            // Use the raw factory client — ClientInterface resolves to the
            // decorated client from this connection (would be circular).
            $client = $app->make(Client::class);
            $config = $app->make('config')->get('database.connections.neo4j');

            return new Neo4jConnection($client, $config['database'] ?? 'neo4j', '', $config);
        });

        $this->registerMigrationRepository();
    }

    public function boot(): void
    {
        $this->app->terminating(function (): void {
            Neo4jQueryLogAfterRequest::flush($this->app);
        });
    }

    /**
     * Wrap Laravel's migration.repository so Neo4j connections use query-builder history.
     *
     * Uses extend() so Migrator receives the wrapped repository (replacing the
     * instance in afterResolving would leave Migrator holding the SQL repository).
     */
    private function registerMigrationRepository(): void
    {
        $this->app->singleton(Neo4jMigrationRepository::class, function (Application $app): Neo4jMigrationRepository {
            return new Neo4jMigrationRepository($app->make('db'));
        });

        $this->app->extend('migration.repository', function (MigrationRepositoryInterface $repository, Application $app) {
            if ($repository instanceof ConnectionAwareMigrationRepository) {
                return $repository;
            }

            return new ConnectionAwareMigrationRepository(
                $repository,
                $app->make(Neo4jMigrationRepository::class),
                $app->make('db')
            );
        });
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function formatConnectionForFactory(string $name, array $connection): array
    {
        $url = $connection['url'] ?? sprintf(
            'bolt://%s:%s',
            $connection['host'] ?? 'localhost',
            $connection['port'] ?? 7687
        );

        $formattedConnection = [
            'alias' => $name,
            'uri' => $url,
            'username' => $connection['username'] ?? '',
            'password' => $connection['password'] ?? '',
        ];

        if (isset($connection['database'])) {
            $formattedConnection['session_config'] = [
                'database' => $connection['database'],
            ];
        }

        if (isset($connection['auth_scheme'])) {
            $formattedConnection['authentication'] = [
                'scheme' => $connection['auth_scheme'],
            ];

            if ($connection['auth_scheme'] === 'none') {
                // No additional configuration needed
            } elseif ($connection['auth_scheme'] === 'kerberos') {
                if (! isset($connection['ticket'])) {
                    throw new BindingResolutionException('Missing ticket for Kerberos authentication');
                }
                $formattedConnection['authentication']['ticket'] = $connection['ticket'];
            } elseif ($connection['auth_scheme'] === 'oidc') {
                $formattedConnection['authentication']['token'] = $connection['auth_token'] ?? $connection['token'] ?? '';
            } elseif ($connection['auth_scheme'] === 'basic') {
                $formattedConnection['authentication']['username'] = $connection['username'] ?? '';
                $formattedConnection['authentication']['password'] = $connection['password'] ?? '';
            } else {
                throw new BindingResolutionException('Unsupported authentication scheme: ' . $connection['auth_scheme']);
            }
        } else {
            // Default to basic auth if no scheme specified
            $formattedConnection['authentication'] = [
                'scheme' => 'basic',
                'username' => $connection['username'] ?? '',
                'password' => $connection['password'] ?? '',
            ];
        }

        if (isset($connection['connection']) || isset($connection['ssl'])) {
            $driverConfig = [];

            if (isset($connection['connection']['max_pool_size'])) {
                $driverConfig['max_pool_size'] = $connection['connection']['max_pool_size'];
            }

            if (isset($connection['connection']['timeout'])) {
                $driverConfig['connection_timeout'] = $connection['connection']['timeout'];
            }

            if (isset($connection['ssl'])) {
                $driverConfig['ssl'] = $connection['ssl'];
            }

            if (! empty($driverConfig)) {
                $formattedConnection['driver_config'] = $driverConfig;
            }
        }

        return $formattedConnection;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validateConnection(string $name, array $config): void
    {
        if (! isset($config['driver']) || $config['driver'] !== 'neo4j') {
            throw new BindingResolutionException(
                sprintf(
                    'Invalid database driver: %s',
                    $config['driver'] ?? 'none'
                )
            );
        }

        if (! isset($config['url']) && (! isset($config['host']) || ! isset($config['port']))) {
            throw new BindingResolutionException("Missing required URL or host/port configuration for Neo4j connection: {$name}");
        }
    }
}
