<?php

namespace Neo4j\Neo4jLaravel;

use Illuminate\Contracts\Container\BindingResolutionException;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Common\Uri;
use Laudis\Neo4j\Contracts\AuthenticateInterface;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Databags\DriverConfiguration;
use Laudis\Neo4j\Databags\SessionConfiguration;
use Laudis\Neo4j\Databags\SslConfiguration;
use Laudis\Neo4j\Databags\TransactionConfiguration;
use Laudis\Neo4j\Enum\SslMode;
use Psr\Log\LoggerInterface;

final class ClientFactory
{
    public function __construct(
        private readonly ?array $driverConfig,
        private readonly ?array $sessionConfiguration,
        private readonly ?array $transactionConfiguration,
        private readonly array $connections,
        private readonly ?string $logLevel,
        private readonly ?LoggerInterface $logger,
        private readonly ?string $defaultDriver = null
    ) {
    }

    public function create(): ClientInterface
    {
        $builder = ClientBuilder::create();

        if ($this->logger !== null && $this->logLevel !== null) {
            $builder = $builder->withDefaultDriverConfiguration(
                DriverConfiguration::default()->withLogger($this->logLevel, $this->logger)
            );
        }

        // laudis Client keeps a single default session/driver/transaction config for
        // every alias. Apply those only from the chosen default driver; each Laravel
        // Neo4jConnection still opens sessions with its own database via createSession().
        $defaultAlias = $this->defaultDriver;
        $defaultConnection = null;

        foreach ($this->connections as $connection) {
            $uri = Uri::create($connection['uri']);
            $auth = $this->createAuth($connection);

            $builder = $builder->withDriver(
                $connection['alias'],
                (string) $uri,
                $auth
            );

            if ($defaultAlias === null) {
                $defaultAlias = $connection['alias'];
            }

            if ($connection['alias'] === $defaultAlias) {
                $defaultConnection = $connection;
            }
        }

        if ($defaultConnection !== null) {
            $driverConfig = $this->createDriverConfig($defaultConnection);
            $sessionConfig = $this->createSessionConfig($defaultConnection);
            $transactionConfig = $this->createTransactionConfig($defaultConnection);

            if ($driverConfig !== null) {
                $builder = $builder->withDefaultDriverConfiguration($driverConfig);
            }

            if ($sessionConfig !== null) {
                $builder = $builder->withDefaultSessionConfiguration($sessionConfig);
            }

            if ($transactionConfig !== null) {
                $builder = $builder->withDefaultTransactionConfiguration($transactionConfig);
            }
        }

        if ($defaultAlias !== null) {
            $builder = $builder->withDefaultDriver($defaultAlias);
        }

        return $builder->build();
    }

    private function createAuth(array $connection): AuthenticateInterface
    {
        return isset($connection['authentication'])
            ? $this->createAuthFromConfig($connection['authentication'])
            : Authenticate::basic($connection['username'], $connection['password']);
    }

    private function createAuthFromConfig(array $config): \Laudis\Neo4j\Authentication\BasicAuth|\Laudis\Neo4j\Authentication\KerberosAuth|\Laudis\Neo4j\Authentication\OpenIDConnectAuth|\Laudis\Neo4j\Authentication\NoAuth
    {
        return match ($config['scheme']) {
            'basic' => Authenticate::basic($config['username'], $config['password']),
            'kerberos' => Authenticate::kerberos($config['ticket']),
            'oidc' => Authenticate::oidc($config['token']),
            'none' => Authenticate::disabled(),
            default => throw new BindingResolutionException("Unsupported authentication scheme: {$config['scheme']}"),
        };
    }

    private function createDriverConfig(array $connection): ?DriverConfiguration
    {
        $config = array_merge($this->driverConfig ?? [], $connection['driver_config'] ?? []);

        if (empty($config)) {
            return null;
        }

        $driverConfig = DriverConfiguration::default();

        if (isset($config['max_pool_size'])) {
            $driverConfig = $driverConfig->withMaxPoolSize($config['max_pool_size']);
        }

        if (isset($config['connection_timeout'])) {
            $driverConfig = $driverConfig->withAcquireConnectionTimeout($config['connection_timeout']);
        }

        if (isset($config['ssl'])) {
            $driverConfig = $driverConfig->withSslConfiguration(
                $this->createSslConfig($config['ssl'])
            );
        }

        return $driverConfig;
    }

    private function createSslConfig(array $config): SslConfiguration
    {
        $mode = match ($config['mode'] ?? 'from_url') {
            'enable' => SslMode::ENABLE(),
            'enable_with_self_signed' => SslMode::ENABLE_WITH_SELF_SIGNED(),
            'disable' => SslMode::DISABLE(),
            'from_url' => SslMode::FROM_URL(),
            default => throw new BindingResolutionException("Unsupported SSL mode: " . ($config['mode'] ?? 'unknown')),
        };

        return SslConfiguration::create($mode, $config['verify_peer'] ?? true);
    }

    private function createSessionConfig(array $connection): ?SessionConfiguration
    {
        $config = array_merge($this->sessionConfiguration ?? [], $connection['session_config'] ?? []);

        if (empty($config)) {
            return null;
        }

        $sessionConfig = SessionConfiguration::default();

        if (isset($config['database'])) {
            $sessionConfig = $sessionConfig->withDatabase($config['database']);
        }

        return $sessionConfig;
    }

    private function createTransactionConfig(array $connection): ?TransactionConfiguration
    {
        $config = array_merge($this->transactionConfiguration ?? [], $connection['transaction_config'] ?? []);

        if (empty($config)) {
            return null;
        }

        $transactionConfig = TransactionConfiguration::default();

        if (isset($config['timeout'])) {
            $transactionConfig = $transactionConfig->withTimeout($config['timeout']);
        }

        return $transactionConfig;
    }
}
