<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Tests\TestCase;

final class Neo4jUpsertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::connection('neo4j')->statement('MATCH (n) WHERE n:UpsertPerson OR n:UpsertTeam DETACH DELETE n');
    }

    protected function tearDown(): void
    {
        DB::connection('neo4j')->statement('MATCH (n) WHERE n:UpsertPerson OR n:UpsertTeam DETACH DELETE n');

        parent::tearDown();
    }

    public function testUpsertCreatesMissingNodesAndUpdatesOnlyTheUpdateColumns(): void
    {
        $connection = DB::connection('neo4j');

        $connection->table('UpsertPerson')->upsert([
            ['email' => 'ann@example.com', 'name' => 'Ann', 'age' => 30, 'active' => true],
            ['email' => 'bob@example.com', 'name' => 'Bob', 'age' => 40, 'active' => false],
        ], ['email'], ['name']);

        $connection->statement(
            'MATCH (p:UpsertPerson {email: $email}) CREATE (p)-[:MEMBER_OF]->(:UpsertTeam {name: $team})',
            ['email' => 'ann@example.com', 'team' => 'Core']
        );

        $connection->table('UpsertPerson')->upsert([
            ['email' => 'ann@example.com', 'name' => 'Ann Smith', 'age' => 31, 'active' => false],
            ['email' => 'cat@example.com', 'name' => 'Cat', 'age' => 25, 'active' => true],
        ], ['email'], ['name']);

        $people = $connection->table('UpsertPerson')->orderBy('email')->get();

        self::assertCount(3, $people);
        self::assertSame(['Ann Smith', 30, true], [$people[0]['name'], $people[0]['age'], $people[0]['active']]);
        self::assertSame(['Bob', 40, false], [$people[1]['name'], $people[1]['age'], $people[1]['active']]);
        self::assertSame(['Cat', 25, true], [$people[2]['name'], $people[2]['age'], $people[2]['active']]);

        $memberships = $connection->select(
            'MATCH (:UpsertPerson {email: $email})-[:MEMBER_OF]->(t:UpsertTeam) RETURN t.name AS team',
            ['email' => 'ann@example.com']
        );
        self::assertSame('Core', $memberships[0]->team);
    }

    public function testUpsertSendsLargeBatchesAsOneStatement(): void
    {
        $connection = DB::connection('neo4j');
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = ['email' => "user{$i}@example.com", 'name' => "User {$i}"];
        }

        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $connection->table('UpsertPerson')->upsert($rows, ['email']);
        $connection->table('UpsertPerson')->upsert($rows, ['email']);
        $log = $connection->getQueryLog();
        $connection->disableQueryLog();

        self::assertCount(2, $log);
        self::assertSame(500, $connection->table('UpsertPerson')->count());
    }

    public function testEloquentUpsertKeepsCreatedAtAndUpdatesTheRest(): void
    {
        UpsertPerson::upsert([['email' => 'ann@example.com', 'name' => 'Ann']], ['email'], ['name']);
        $created = UpsertPerson::where('email', 'ann@example.com')->firstOrFail();

        UpsertPerson::upsert([['email' => 'ann@example.com', 'name' => 'Ann Smith']], ['email'], ['name']);
        $updated = UpsertPerson::where('email', 'ann@example.com')->firstOrFail();

        self::assertSame(1, UpsertPerson::count());
        self::assertSame('Ann Smith', $updated->name);
        self::assertNotNull($created->created_at);
        self::assertEquals($created->created_at, $updated->created_at);
        self::assertNotNull($updated->updated_at);
    }
}

final class UpsertPerson extends Neo4jModel
{
    protected $table = 'UpsertPerson';

    protected $guarded = [];
}
