<?php

namespace Neo4j\Neo4jLaravel\Decorators;

use Laudis\Neo4j\Contracts\DriverInterface;
use Laudis\Neo4j\Contracts\SessionInterface;
use Laudis\Neo4j\Databags\ServerInfo;
use Laudis\Neo4j\Databags\SessionConfiguration;
use Neo4j\Neo4jLaravel\Neo4jConnection;

/**
 * Wraps a Neo4j driver so sessions participate in Laravel query capture.
 *
 * @internal
 */
final class LaravelNeo4jDriver implements DriverInterface
{
    public function __construct(
        private readonly DriverInterface $driver,
        private readonly Neo4jConnection $connection
    ) {
    }

    public function createSession(?SessionConfiguration $config = null): SessionInterface
    {
        return new LaravelNeo4jSession(
            $this->driver->createSession($config ?? $this->connection->sessionConfiguration()),
            $this->connection
        );
    }

    public function verifyConnectivity(?SessionConfiguration $config = null): bool
    {
        return $this->driver->verifyConnectivity($config);
    }

    public function getServerInfo(?SessionConfiguration $config = null): ServerInfo
    {
        return $this->driver->getServerInfo($config);
    }

    public function closeConnections(): void
    {
        $this->driver->closeConnections();
    }
}
