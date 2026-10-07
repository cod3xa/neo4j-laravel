<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;
use Neo4j\Neo4jLaravel\Tests\TestCase;

final class Neo4jBulkRelationshipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::connection('neo4j')->statement('MATCH (n) WHERE n:BulkJob OR n:BulkQueue DETACH DELETE n');
    }

    protected function tearDown(): void
    {
        DB::connection('neo4j')->statement('MATCH (n) WHERE n:BulkJob OR n:BulkQueue DETACH DELETE n');

        parent::tearDown();
    }

    public function testUpsertRelationshipsCreatesOnceAndUpdatesProperties(): void
    {
        $this->createNodes(['A', 'B'], ['redis', 'sqs']);
        $connection = DB::connection('neo4j');

        $created = $connection->table('BulkJob')->upsertRelationships('USES>', 'BulkQueue', [
            ['from' => ['key' => 'A'], 'to' => ['key' => 'redis'], 'properties' => ['queue' => 'default']],
            ['from' => ['key' => 'B'], 'to' => ['key' => 'sqs'], 'properties' => ['queue' => 'default']],
            ['from' => ['key' => 'A'], 'to' => ['key' => 'missing'], 'properties' => ['queue' => 'default']],
        ]);
        $connection->table('BulkJob')->upsertRelationships('USES>', 'BulkQueue', [
            ['from' => ['key' => 'A'], 'to' => ['key' => 'redis'], 'properties' => ['queue' => 'mail']],
        ]);

        self::assertSame(4, $created);
        self::assertSame(['A>redis:mail', 'B>sqs:default'], $this->edges());
    }

    public function testInsertRelationshipsCreatesEveryRow(): void
    {
        $this->createNodes(['A'], ['redis', 'sqs']);

        DB::connection('neo4j')->table('BulkJob')->insertRelationships('USES>', 'BulkQueue', [
            ['from' => ['key' => 'A'], 'to' => ['key' => 'redis']],
            ['from' => ['key' => 'A'], 'to' => ['key' => 'sqs']],
        ]);

        self::assertSame(['A>redis:', 'A>sqs:'], $this->edges());
    }

    public function testSyncRelationshipsReplacesEdgesWithoutDeletingNodes(): void
    {
        $this->createNodes(['A', 'B', 'C'], ['redis', 'sqs', 'sync']);
        $connection = DB::connection('neo4j');
        $connection->statement(<<<'CYPHER'
            MATCH (a:BulkJob {key: 'A'}), (b:BulkJob {key: 'B'}), (c:BulkJob {key: 'C'}),
                  (redis:BulkQueue {key: 'redis'}), (sqs:BulkQueue {key: 'sqs'})
            CREATE (a)-[:USES {since: 1}]->(redis), (a)-[:USES]->(sqs), (b)-[:USES]->(redis), (c)-[:USES]->(sqs)
            CYPHER);

        $connection->table('BulkJob')->syncRelationships('USES>', 'BulkQueue', [
            ['from' => ['key' => 'A'], 'to' => ['key' => 'redis']],
            ['from' => ['key' => 'A'], 'to' => ['key' => 'sync']],
            ['from' => ['key' => 'B'], 'to' => ['key' => 'sqs']],
        ]);

        self::assertSame(['A>redis:', 'A>sync:', 'B>sqs:', 'C>sqs:'], $this->edges());
        self::assertSame(1, $connection->select("MATCH (:BulkJob {key: 'A'})-[r:USES]->(:BulkQueue {key: 'redis'}) RETURN r.since AS since")[0]->since);
        self::assertSame(3, $connection->table('BulkJob')->count());
        self::assertSame(3, $connection->table('BulkQueue')->count());
    }

    public function testDeleteRelationshipsKeepsNodes(): void
    {
        $this->createNodes(['A', 'B'], ['redis', 'sqs']);
        $connection = DB::connection('neo4j');
        $connection->table('BulkJob')->insertRelationships('USES>', 'BulkQueue', [
            ['from' => ['key' => 'A'], 'to' => ['key' => 'redis']],
            ['from' => ['key' => 'A'], 'to' => ['key' => 'sqs']],
            ['from' => ['key' => 'B'], 'to' => ['key' => 'sqs']],
        ]);

        self::assertSame(1, $connection->table('BulkJob')->deleteRelationships('USES>', 'BulkQueue', [['from' => ['key' => 'A'], 'to' => ['key' => 'sqs']]]));
        self::assertSame(['A>redis:', 'B>sqs:'], $this->edges());

        self::assertSame(1, $connection->table('BulkJob')->deleteRelationships('USES>', 'BulkQueue', [['from' => ['key' => 'B']]]));
        self::assertSame(['A>redis:'], $this->edges());
        self::assertSame(2, $connection->table('BulkJob')->count());
    }

    public function testLargeBatchesRunAsOneStatement(): void
    {
        $jobs = array_map(static fn (int $i): string => "job{$i}", range(1, 500));
        $this->createNodes($jobs, ['redis']);
        $connection = DB::connection('neo4j');

        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $connection->table('BulkJob')->upsertRelationships('USES>', 'BulkQueue', array_map(
            static fn (string $job): array => ['from' => ['key' => $job], 'to' => ['key' => 'redis']],
            $jobs
        ));
        $log = $connection->getQueryLog();
        $connection->disableQueryLog();

        self::assertCount(1, $log);
        self::assertSame(500, $connection->select('MATCH (:BulkJob)-[r:USES]->(:BulkQueue) RETURN count(r) AS c')[0]->c);
    }

    public function testEloquentAttachSyncAndDetach(): void
    {
        $job = BulkJob::create(['key' => 'A']);
        $redis = BulkQueue::create(['key' => 'redis']);
        $sqs = BulkQueue::create(['key' => 'sqs']);
        $sync = BulkQueue::create(['key' => 'sync']);

        $connection = DB::connection('neo4j');
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $job->queues()->attach([$redis->id, $sqs->id], ['queue' => 'default']);
        self::assertCount(1, $connection->getQueryLog());
        $connection->disableQueryLog();
        self::assertSame(['A>redis:default', 'A>sqs:default'], $this->edges());

        $job->queues()->sync([$sqs->id, $sync->id]);
        self::assertSame(['A>sqs:default', 'A>sync:'], $this->edges());

        $job->queues()->detach($sqs->id);
        self::assertSame(['A>sync:'], $this->edges());

        $job->queues()->attach($redis->id);
        $job->queues()->sync([]);
        self::assertSame([], $this->edges());

        $job->queues()->attach($redis->id);
        self::assertSame(1, $job->queues()->detach());
        self::assertSame(1, BulkJob::count());
        self::assertSame(3, BulkQueue::count());
    }

    /**
     * @param  list<string>  $jobs
     * @param  list<string>  $queues
     */
    private function createNodes(array $jobs, array $queues): void
    {
        $connection = DB::connection('neo4j');
        $connection->table('BulkJob')->insert(array_map(static fn (string $key): array => ['key' => $key], $jobs));
        $connection->table('BulkQueue')->insert(array_map(static fn (string $key): array => ['key' => $key], $queues));
    }

    /**
     * @return list<string>
     */
    private function edges(): array
    {
        $rows = DB::connection('neo4j')->select(
            'MATCH (j:BulkJob)-[r:USES]->(q:BulkQueue) RETURN j.key AS job, q.key AS queue, r.queue AS name ORDER BY job, queue'
        );

        return array_map(static fn (object $row): string => "{$row->job}>{$row->queue}:{$row->name}", $rows);
    }
}

final class BulkJob extends Neo4jModel
{
    protected $table = 'BulkJob';

    protected $guarded = [];

    public function queues(): MatchRelationship
    {
        return $this->matchRelationship(BulkQueue::class, 'USES>');
    }
}

final class BulkQueue extends Neo4jModel
{
    protected $table = 'BulkQueue';

    protected $guarded = [];
}
