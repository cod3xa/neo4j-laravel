<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit;

use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\UnmanagedTransactionInterface;
use Laudis\Neo4j\Databags\Neo4jError;
use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Exception\Neo4jException;
use Laudis\Neo4j\Types\CypherList;
use Mockery;
use Mockery\MockInterface;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use Neo4j\Neo4jLaravel\Neo4jServiceProvider;
use Orchestra\Testbench\TestCase;

final class TransactionRetryTest extends TestCase
{
    /** @var ClientInterface&MockInterface */
    private ClientInterface $client;

    private Neo4jConnection $connection;

    protected function getPackageProviders($app): array
    {
        return [Neo4jServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(ClientInterface::class);
        $this->connection = new Neo4jConnection($this->client, 'neo4j', '', ['name' => 'testing']);
        $this->connection->setEventDispatcher($this->app['events']);
    }

    public function test_transient_error_is_retried_in_a_fresh_transaction(): void
    {
        $failed = $this->transactionThatFailsWith('Neo.TransientError.Transaction.DeadlockDetected');

        $summary = null;
        $succeeded = Mockery::mock(UnmanagedTransactionInterface::class);
        $succeeded->shouldReceive('run')->once()->andReturn(new SummarizedResult($summary));
        $succeeded->shouldReceive('commit')->once()->andReturn(new CypherList());

        $this->client->shouldReceive('beginTransaction')->twice()->andReturn($failed, $succeeded);

        $attempts = 0;
        $result = $this->connection->transaction(function (Neo4jConnection $connection) use (&$attempts): string {
            $attempts++;
            $connection->statement('CREATE (:Account {name: $name})', ['name' => 'Ada']);

            return 'ok';
        }, 3);

        self::assertSame('ok', $result);
        self::assertSame(2, $attempts);
        self::assertSame(0, $this->connection->transactionLevel());
    }

    public function test_non_transient_error_is_not_retried(): void
    {
        $failed = $this->transactionThatFailsWith('Neo.ClientError.Statement.SyntaxError');

        $this->client->shouldReceive('beginTransaction')->once()->andReturn($failed);

        $attempts = 0;

        try {
            $this->connection->transaction(function (Neo4jConnection $connection) use (&$attempts): void {
                $attempts++;
                $connection->statement('CREATE (:Account');
            }, 3);
            self::fail('Expected exception was not thrown');
        } catch (Neo4jException $e) {
            self::assertSame('Neo.ClientError.Statement.SyntaxError', $e->getNeo4jCode());
        }

        self::assertSame(1, $attempts);
        self::assertSame(0, $this->connection->transactionLevel());
    }

    public function test_rollback_skips_transactions_neo4j_already_terminated(): void
    {
        $tx = Mockery::mock(UnmanagedTransactionInterface::class);
        $tx->shouldReceive('isFinished')->andReturn(true);
        $tx->shouldNotReceive('rollback');

        $this->client->shouldReceive('beginTransaction')->once()->andReturn($tx);

        $this->connection->beginTransaction();
        $this->connection->rollBack();

        self::assertSame(0, $this->connection->transactionLevel());
    }

    private function transactionThatFailsWith(string $code): UnmanagedTransactionInterface
    {
        $error = new Neo4jException([Neo4jError::fromMessageAndCode($code, 'simulated')]);

        $tx = Mockery::mock(UnmanagedTransactionInterface::class);
        $tx->shouldReceive('run')->once()->andThrow($error);
        $tx->shouldReceive('isFinished')->andReturn(true);
        $tx->shouldNotReceive('commit');

        return $tx;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
