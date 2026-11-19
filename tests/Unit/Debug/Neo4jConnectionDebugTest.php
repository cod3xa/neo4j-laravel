<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit\Debug;

use Illuminate\Database\Events\QueryExecuted;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\SessionInterface;
use Mockery;
use Mockery\MockInterface;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use Neo4j\Neo4jLaravel\Neo4jServiceProvider;
use Neo4j\Neo4jLaravel\Tests\Support\StubsConnectionSession;
use Orchestra\Testbench\TestCase;

class Neo4jConnectionDebugTest extends TestCase
{
    use StubsConnectionSession;

    private Neo4jConnection $connection;

    /** @var ClientInterface&MockInterface */
    private ClientInterface $client;

    /** @var SessionInterface&MockInterface */
    private SessionInterface $session;

    /** @var list<QueryExecuted> */
    private array $executed = [];

    protected function getPackageProviders($app): array
    {
        return [Neo4jServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(ClientInterface::class);
        $this->session = $this->stubConnectionSession($this->client, 'testing');
        $this->executed = [];

        $this->connection = new Neo4jConnection(
            $this->client,
            'neo4j',
            '',
            ['name' => 'testing']
        );
        $this->connection->enableQueryLog();
        $this->connection->setEventDispatcher($this->app['events']);

        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            $this->executed[] = $event;
        });
    }

    public function test_log_query_dispatches_query_executed_for_shared_queries_tab(): void
    {
        $query = 'MATCH (n:SharedTab) RETURN n';
        $bindings = ['x' => 1];

        $this->connection->logQuery($query, $bindings, 12.5);

        $this->assertCount(1, $this->executed);
        $this->assertSame($query, $this->executed[0]->sql);
        $this->assertSame($bindings, $this->executed[0]->bindings);
        $this->assertSame(12.5, $this->executed[0]->time);
        $this->assertSame($this->connection, $this->executed[0]->connection);
        $this->assertSame(12.5, $this->connection->totalQueryDuration());
    }

    public function test_log_query_failure_annotates_query_executed_but_keeps_clean_log(): void
    {
        $query = 'MATCH (n) RETURN n';
        $bindings = ['id' => 1];
        $exception = new \RuntimeException("boom\nline2");

        $this->connection->logQuery($query, $bindings, 3.2, false, $exception);

        $this->assertCount(1, $this->executed);
        $this->assertSame(
            $query . "\n/* Neo4j error: boom line2 */",
            $this->executed[0]->sql
        );
        $this->assertSame($bindings, $this->executed[0]->bindings);
        $this->assertSame(3.2, $this->executed[0]->time);

        $log = $this->connection->getQueryLog()[0];
        $this->assertSame($query, $log['cypher']);
        $this->assertSame($query, $log['query']);
        $this->assertSame('error', $log['status']);
        $this->assertSame("boom\nline2", $log['error_message']);
    }

    public function test_failed_query_executed_truncates_long_error_message(): void
    {
        $query = 'RETURN 1';
        $long = str_repeat('x', 400);

        $this->connection->logQuery($query, [], 1.0, false, new \RuntimeException($long));

        $this->assertMatchesRegularExpression(
            '/^RETURN 1\n\/\* Neo4j error: x{297}\.\.\. \*\/$/',
            $this->executed[0]->sql
        );
        $this->assertSame($query, $this->connection->getQueryLog()[0]['cypher']);
    }

    public function test_log_query_writes_connection_query_log(): void
    {
        $query = 'MATCH (n:Test) RETURN n';
        $bindings = ['param' => 'value'];

        $this->connection->logQuery($query, $bindings, 0.1);

        $log = $this->connection->getQueryLog()[0];
        $this->assertSame($query, $log['cypher']);
        $this->assertSame($bindings, $log['params']);
        $this->assertSame(0.1, $log['time']);
        $this->assertSame('testing', $log['connection_name']);
        $this->assertSame('ok', $log['status']);
    }

    public function test_log_query_with_null_duration(): void
    {
        $this->connection->logQuery('MATCH (n:Test) RETURN n', ['param' => 'value']);

        $this->assertNull($this->connection->getQueryLog()[0]['time']);
        $this->assertNull($this->executed[0]->time);
    }

    public function test_run_query_callback_dispatches_query_executed(): void
    {
        $query = 'MATCH (n:Test) RETURN n';
        $bindings = ['param' => 'value'];

        $this->session->shouldReceive('readTransaction')
            ->once()
            ->andReturn(['result']);

        $this->connection->select($query, $bindings);

        $this->assertCount(1, $this->executed);
        $this->assertSame($query, $this->executed[0]->sql);
        $this->assertSame($bindings, $this->executed[0]->bindings);
        $this->assertIsFloat($this->executed[0]->time);
    }

    public function test_run_query_callback_logs_on_exception(): void
    {
        $query = 'MATCH (n:Test) RETURN n';
        $bindings = ['param' => 'value'];
        $exception = new \RuntimeException('Test exception');

        $this->session->shouldReceive('readTransaction')
            ->once()
            ->andThrow($exception);

        try {
            $this->connection->select($query, $bindings);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($exception, $e);
        }

        $this->assertCount(1, $this->executed);
        $this->assertStringStartsWith($query, $this->executed[0]->sql);
        $this->assertStringContainsString('/* Neo4j error: Test exception */', $this->executed[0]->sql);
        $log = $this->connection->getQueryLog()[0];
        $this->assertSame($query, $log['cypher']);
        $this->assertSame('error', $log['status']);
        $this->assertFalse($log['successful']);
        $this->assertSame('Test exception', $log['error_message']);
    }

    public function test_write_failure_is_logged_and_exception_propagates(): void
    {
        $query = 'CREATE (n:Test) RETURN n';
        $bindings = ['name' => 'x'];
        $exception = new \RuntimeException('write failed');

        $this->session->shouldReceive('writeTransaction')
            ->once()
            ->andThrow($exception);

        try {
            $this->connection->write($query, $bindings);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($exception, $e);
        }

        $this->assertCount(1, $this->executed);
        $this->assertStringStartsWith($query, $this->executed[0]->sql);
        $this->assertStringContainsString('/* Neo4j error: write failed */', $this->executed[0]->sql);
        $log = $this->connection->getQueryLog()[0];
        $this->assertSame($query, $log['cypher']);
        $this->assertSame($bindings, $log['bindings']);
        $this->assertSame('error', $log['status']);
        $this->assertSame('write failed', $log['error_message']);
    }

    public function test_transaction_run_failure_is_logged_and_exception_propagates(): void
    {
        $query = 'MATCH (n) RETURN n';
        $bindings = ['id' => 1];
        $exception = new \RuntimeException('tx run failed');

        $inner = Mockery::mock(\Laudis\Neo4j\Contracts\UnmanagedTransactionInterface::class);
        $inner->shouldReceive('run')
            ->once()
            ->with($query, $bindings)
            ->andThrow($exception);

        $this->session->shouldReceive('beginTransaction')
            ->once()
            ->andReturn($inner);

        $tx = $this->connection->beginTransaction();

        try {
            $tx->run($query, $bindings);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($exception, $e);
        }

        $this->assertCount(1, $this->executed);
        $this->assertStringStartsWith($query, $this->executed[0]->sql);
        $this->assertStringContainsString('/* Neo4j error: tx run failed */', $this->executed[0]->sql);
        $log = $this->connection->getQueryLog()[0];
        $this->assertSame($query, $log['cypher']);
        $this->assertSame('error', $log['status']);
        $this->assertSame('tx run failed', $log['error_message']);
    }

    public function test_write_dispatches_query_executed(): void
    {
        $query = 'CREATE (n:Test {name: $name}) RETURN n';
        $bindings = ['name' => 'Ada'];
        $summary = null;
        $result = new \Laudis\Neo4j\Databags\SummarizedResult($summary);

        $this->session->shouldReceive('writeTransaction')
            ->once()
            ->andReturn($result);

        $this->connection->write($query, $bindings);

        $this->assertCount(1, $this->executed);
        $this->assertSame($query, $this->executed[0]->sql);
        $this->assertSame($bindings, $this->executed[0]->bindings);
        $this->assertSame('ok', $this->connection->getQueryLog()[0]['status']);
    }

    public function test_run_cypher_captures_through_shared_execution_path(): void
    {
        $query = 'RETURN $value AS v';
        $bindings = ['value' => 42];
        $summary = null;
        $result = new \Laudis\Neo4j\Databags\SummarizedResult($summary);

        $this->session->shouldReceive('run')
            ->once()
            ->with($query, $bindings, null)
            ->andReturn($result);

        $this->connection->runCypher($query, $bindings);

        $this->assertCount(1, $this->executed);
        $this->assertSame($query, $this->executed[0]->sql);
        $this->assertSame($bindings, $this->executed[0]->bindings);
        $this->assertIsFloat($this->executed[0]->time);
    }

    public function test_query_executed_still_fires_when_query_log_disabled(): void
    {
        $this->connection->disableQueryLog();

        $this->session->shouldReceive('readTransaction')
            ->once()
            ->andReturn(['result']);

        $this->connection->select('MATCH (n) RETURN n', []);

        $this->assertSame([], $this->connection->getQueryLog());
        $this->assertCount(1, $this->executed);
        $this->assertSame('MATCH (n) RETURN n', $this->executed[0]->sql);
    }

    public function test_transaction_run_goes_through_capture_without_duplicate(): void
    {
        $query = 'MATCH (n) RETURN n';
        $bindings = ['id' => 1];
        $summary = null;
        $result = new \Laudis\Neo4j\Databags\SummarizedResult($summary);

        $inner = Mockery::mock(\Laudis\Neo4j\Contracts\UnmanagedTransactionInterface::class);
        $inner->shouldReceive('run')
            ->once()
            ->with($query, $bindings)
            ->andReturn($result);

        $this->session->shouldReceive('beginTransaction')
            ->once()
            ->andReturn($inner);

        $tx = $this->connection->beginTransaction();
        $this->assertInstanceOf(\Neo4j\Neo4jLaravel\Debug\CapturingUnmanagedTransaction::class, $tx);

        $tx->run($query, $bindings);

        $this->assertCount(1, $this->executed);
        $this->assertSame($query, $this->executed[0]->sql);
        $this->assertSame($bindings, $this->executed[0]->bindings);
    }

    public function test_run_cypher_inside_transaction_captures_once(): void
    {
        $query = 'CREATE (n:T {v: $v}) RETURN n';
        $bindings = ['v' => 2];
        $summary = null;
        $result = new \Laudis\Neo4j\Databags\SummarizedResult($summary);

        $inner = Mockery::mock(\Laudis\Neo4j\Contracts\UnmanagedTransactionInterface::class);
        $inner->shouldReceive('run')
            ->once()
            ->with($query, $bindings)
            ->andReturn($result);

        $this->session->shouldReceive('beginTransaction')
            ->once()
            ->andReturn($inner);

        $this->connection->beginTransaction();
        $this->connection->runCypher($query, $bindings);

        $this->assertCount(1, $this->executed);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
