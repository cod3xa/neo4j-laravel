<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;
use Neo4j\Neo4jLaravel\Tests\TestCase;
use RuntimeException;

final class Neo4jTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection()->statement('MATCH (n:TxAccount) DETACH DELETE n');
        $this->connection()->statement('MATCH (n:TxProject) DETACH DELETE n');
    }

    public function testTransactionRollsBackGraphRelationships(): void
    {
        $project = TxProject::create(['name' => 'neo4j-laravel']);

        try {
            DB::connection('neo4j')->transaction(function () use ($project): void {
                $account = TxAccount::create(['name' => 'Ada']);
                $account->projects()->attach($project->id, ['role' => 'maintainer']);

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(0, TxAccount::count());
        self::assertSame(1, TxProject::count());
        self::assertSame(0, $this->connection()->table('TxProject')->matchRelationship('<WORKS_ON', 'TxAccount')->count());
    }

    public function testTransactionCommitsEloquentWrites(): void
    {
        $result = DB::connection('neo4j')->transaction(function (): string {
            TxAccount::create(['name' => 'Ada']);
            TxAccount::create(['name' => 'Grace']);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(2, TxAccount::count());
        self::assertSame(0, $this->connection()->transactionLevel());
    }

    public function testTransactionRollsBackEloquentWritesOnException(): void
    {
        try {
            DB::connection('neo4j')->transaction(function (): void {
                TxAccount::create(['name' => 'Ada']);

                throw new RuntimeException('payment failed');
            });
            self::fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            self::assertSame('payment failed', $e->getMessage());
        }

        self::assertSame(0, TxAccount::count());
        self::assertSame(0, $this->connection()->transactionLevel());
    }

    public function testManualRollBackDiscardsQueryBuilderWrites(): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();
        $connection->table('TxAccount')->insert(['name' => 'Ada']);

        self::assertSame(1, $connection->transactionLevel());
        self::assertSame(1, $connection->table('TxAccount')->count(), 'reads inside the transaction see its writes');

        $connection->rollBack();

        self::assertSame(0, $connection->transactionLevel());
        self::assertSame(0, $connection->table('TxAccount')->count());
    }

    public function testCommitThroughReturnedTransactionObjectUpdatesLevels(): void
    {
        $connection = $this->connection();

        $tx = $connection->beginTransaction();
        TxAccount::create(['name' => 'Ada']);
        $tx->commit();

        self::assertTrue($tx->isCommitted());
        self::assertSame(0, $connection->transactionLevel());
        self::assertSame(1, TxAccount::count());
    }

    public function testNestedTransactionsShareOneNeo4jTransaction(): void
    {
        $connection = $this->connection();

        $connection->transaction(function (Neo4jConnection $connection): void {
            TxAccount::create(['name' => 'Ada']);

            $connection->transaction(function (Neo4jConnection $connection): void {
                self::assertSame(2, $connection->transactionLevel());
                TxAccount::create(['name' => 'Grace']);
            });

            self::assertSame(1, $connection->transactionLevel());
        });

        self::assertSame(2, TxAccount::count());
    }

    public function testFailedNestedTransactionRollsBackTheWholeTransaction(): void
    {
        $connection = $this->connection();

        try {
            $connection->transaction(function (Neo4jConnection $connection): void {
                TxAccount::create(['name' => 'Ada']);

                try {
                    $connection->transaction(function (): void {
                        TxAccount::create(['name' => 'Grace']);

                        throw new RuntimeException('inner failed');
                    });
                } catch (RuntimeException) {
                    // Swallowing the inner failure must not let the outer transaction carry on.
                }

                TxAccount::create(['name' => 'Linus']);
            });
            self::fail('Expected exception was not thrown');
        } catch (LogicException $e) {
            self::assertStringContainsString('rolled back by a nested transaction', $e->getMessage());
        }

        self::assertSame(0, $connection->transactionLevel());
        self::assertSame(0, TxAccount::count());
    }

    public function testAfterCommitCallbacksRunOnlyOnCommit(): void
    {
        $calls = [];

        DB::connection('neo4j')->transaction(function () use (&$calls): void {
            TxAccount::create(['name' => 'Ada']);
            DB::connection('neo4j')->afterCommit(function () use (&$calls): void {
                $calls[] = 'committed';
            });

            self::assertSame([], $calls);
        });

        try {
            DB::connection('neo4j')->transaction(function () use (&$calls): void {
                DB::connection('neo4j')->afterCommit(function () use (&$calls): void {
                    $calls[] = 'rolled back';
                });

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(['committed'], $calls);
    }

    public function testTransactionEventsAreDispatched(): void
    {
        $events = [];
        Event::listen(
            [TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class],
            function (object $event) use (&$events): void {
                $events[] = $event::class;
            }
        );

        DB::connection('neo4j')->transaction(fn () => TxAccount::create(['name' => 'Ada']));

        $connection = $this->connection();
        $connection->beginTransaction();
        $connection->rollBack();

        self::assertSame([
            TransactionBeginning::class,
            TransactionCommitted::class,
            TransactionBeginning::class,
            TransactionRolledBack::class,
        ], $events);
    }

    private function connection(): Neo4jConnection
    {
        $connection = DB::connection('neo4j');
        assert($connection instanceof Neo4jConnection);

        return $connection;
    }
}

class TxAccount extends Neo4jModel
{
    protected $table = 'TxAccount';

    protected $guarded = [];

    public function projects(): MatchRelationship
    {
        return $this->matchRelationship(TxProject::class, 'WORKS_ON>');
    }
}

class TxProject extends Neo4jModel
{
    protected $table = 'TxProject';

    protected $guarded = [];
}
