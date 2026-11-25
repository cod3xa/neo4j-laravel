<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

use Illuminate\Support\Facades\Schema;
use Neo4j\Neo4jLaravel\Tests\TestCase;

final class Neo4jSchemaMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $schema = Schema::connection('neo4j');
        $schema->dropConstraint('person_email_unique');
        $schema->dropIndex('person_name_index');
        $schema->dropIndex('acted_in_role_index');
    }

    protected function tearDown(): void
    {
        $schema = Schema::connection('neo4j');
        $schema->dropConstraint('person_email_unique');
        $schema->dropIndex('person_name_index');
        $schema->dropIndex('acted_in_role_index');

        parent::tearDown();
    }

    public function testNodeAndRelationshipSchemaCreateIndexesWithoutCreatingData(): void
    {
        Schema::connection('neo4j')->node('Person', function ($node): void {
            $node->unique('email');
            $node->index('name');
        });

        Schema::connection('neo4j')->relationship('ACTED_IN', function ($relationship): void {
            $relationship->index('role');
        });

        $names = array_map(
            static fn (object $row): string => (string) $row->name,
            Schema::connection('neo4j')->getConnection()->select(
                'SHOW CONSTRAINTS YIELD name RETURN name AS name'
            )
        );
        self::assertContains('person_email_unique', $names);

        $indexNames = array_map(
            static fn (object $row): string => (string) $row->name,
            Schema::connection('neo4j')->getConnection()->select(
                'SHOW INDEXES YIELD name RETURN name AS name'
            )
        );
        self::assertContains('person_name_index', $indexNames);
        self::assertContains('acted_in_role_index', $indexNames);

        // Schema definitions must not create application nodes.
        $count = Schema::connection('neo4j')->getConnection()
            ->select('MATCH (n:Person) RETURN count(n) AS c')[0]->c;
        self::assertSame(0, $count);
    }

    public function testDropRemovesSchemaObjectsOnly(): void
    {
        $connection = Schema::connection('neo4j')->getConnection();
        $connection->statement('CREATE (n:Person {email: $email, name: $name})', [
            'email' => 'ada@example.com',
            'name' => 'Ada',
        ]);

        Schema::connection('neo4j')->node('Person', function ($node): void {
            $node->unique('email');
            $node->index('name');
        });

        Schema::connection('neo4j')->dropConstraint('person_email_unique');
        Schema::connection('neo4j')->dropIndex('person_name_index');

        $count = $connection->select('MATCH (n:Person {email: $email}) RETURN count(n) AS c', [
            'email' => 'ada@example.com',
        ])[0]->c;
        self::assertSame(1, $count);

        $connection->statement('MATCH (n:Person {email: $email}) DETACH DELETE n', [
            'email' => 'ada@example.com',
        ]);
    }
}
