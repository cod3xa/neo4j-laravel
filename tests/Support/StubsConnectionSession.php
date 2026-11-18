<?php

namespace Neo4j\Neo4jLaravel\Tests\Support;

use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\DriverInterface;
use Laudis\Neo4j\Contracts\SessionInterface;
use Mockery;
use Mockery\MockInterface;

trait StubsConnectionSession
{
    /**
     * @param  ClientInterface&MockInterface  $client
     * @return SessionInterface&MockInterface
     */
    protected function stubConnectionSession(ClientInterface $client, string $alias = 'testing'): SessionInterface
    {
        /** @var SessionInterface&MockInterface $session */
        $session = Mockery::mock(SessionInterface::class);
        /** @var DriverInterface&MockInterface $driver */
        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('createSession')->andReturn($session)->byDefault();
        $client->shouldReceive('getDriver')->with($alias)->andReturn($driver)->byDefault();

        return $session;
    }
}
