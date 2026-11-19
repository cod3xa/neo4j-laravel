<?php

namespace Neo4j\Neo4jLaravel;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Laudis\Neo4j\Client;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\DriverInterface;
use Laudis\Neo4j\Contracts\SessionInterface;
use Neo4j\Neo4jLaravel\Logging\Neo4jQueryLogAfterRequest;

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
            $connections = $config->get('database.connections', []);

            $factoryConnections = [];
            $neo4jAliases = [];

            foreach ($connections as $name => $connection) {
                if (($connection['driver'] ?? null) !== 'neo4j') {
                    continue;
                }

                $this->validateConnection($name, $connection);
                $factoryConnections[] = $this->formatConnectionForFactory($name, $connection);
                $neo4jAliases[] = $name;
            }

            if ($factoryConnections === []) {
                throw new BindingResolutionException('No Neo4j database connections are configured');
            }

            $defaultConnection = $config->get('database.default');
            $factoryDefault = in_array($defaultConnection, $neo4jAliases, true)
                ? $defaultConnection
                : $neo4jAliases[0];

            $factory = new ClientFactory(
                null,
                null,
                null,
                $factoryConnections,
                null,
                null,
                $factoryDefault
            );

            return $factory->create();
        });

        $this->app->singleton(ClientInterface::class, function (Application $app): ClientInterface {
            return $this->resolveNeo4jConnection($app)->getClient();
        });

        $this->app->singleton(DriverInterface::class, function (Application $app): DriverInterface {
            $connection = $this->resolveNeo4jConnection($app);

            return $app->make(ClientInterface::class)->getDriver($connection->driverAlias());
        });

        $this->app->bind(SessionInterface::class, function (Application $app): SessionInterface {
            $connection = $this->resolveNeo4jConnection($app);

            return $connection->getClient()->getDriver($connection->driverAlias())->createSession();
        });

        // Do not bind TransactionInterface to beginTransaction() — resolving it
        // from the container would open (and leak) unmanaged transactions.
        // Use DB::connection(...)->transaction() / beginTransaction(), or inject
        // ClientInterface / SessionInterface instead.

        $manager = $this->app->make('db');
        $manager->extend('neo4j', function (array $config, string $name) {
            $client = $this->app->make(Client::class);

            $config['name'] = $name;

            return new Neo4jConnection($client, $config['database'] ?? 'neo4j', '', $config);
        });
    }

    public function boot(): void
    {
        $this->app->terminating(function (): void {
            Neo4jQueryLogAfterRequest::flush($this->app);
        });
    }

    private function resolveNeo4jConnection(Application $app): Neo4jConnection
    {
        $config = $app->make('config');
        $default = $config->get('database.default');
        $connection = DB::connection($default);

        if ($connection instanceof Neo4jConnection) {
            return $connection;
        }

        $connections = $config->get('database.connections', []);
        $alias = null;

        if (($connections['neo4j']['driver'] ?? null) === 'neo4j') {
            $alias = 'neo4j';
        } else {
            foreach ($connections as $name => $candidate) {
                if (($candidate['driver'] ?? null) === 'neo4j') {
                    $alias = $name;
                    break;
                }
            }
        }

        if ($alias === null) {
            throw new BindingResolutionException('No Neo4j database connections are configured');
        }

        $connection = DB::connection($alias);
        assert($connection instanceof Neo4jConnection);

        return $connection;
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

        if (isset($connection['transaction']['timeout'])) {
            $formattedConnection['transaction_config'] = [
                'timeout' => $connection['transaction']['timeout'],
            ];
        } elseif (isset($connection['transaction_config']['timeout'])) {
            $formattedConnection['transaction_config'] = [
                'timeout' => $connection['transaction_config']['timeout'],
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
