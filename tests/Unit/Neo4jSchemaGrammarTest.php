<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;
use InvalidArgumentException;
use Neo4j\Neo4jLaravel\Neo4jSchemaGrammar;
use PHPUnit\Framework\TestCase;

final class Neo4jSchemaGrammarTest extends TestCase
{
    public function testCompilesCreateVectorIndex(): void
    {
        $grammar = new Neo4jSchemaGrammar();

        self::assertSame(
            'CREATE VECTOR INDEX document_embedding IF NOT EXISTS FOR (n:Document) ON (n.embedding) '
                .'OPTIONS {indexConfig: {`vector.dimensions`: 3, `vector.similarity_function`: \'cosine\'}}',
            $grammar->compileCreateVectorIndex('document_embedding', 'Document', 'embedding', 3)
        );
    }

    public function testCompilesDropVectorIndex(): void
    {
        self::assertSame(
            'DROP INDEX document_embedding IF EXISTS',
            (new Neo4jSchemaGrammar())->compileDropNamedVectorIndex('document_embedding')
        );
    }

    public function testCompilesDropVectorIndexFromLaravelBlueprintCommand(): void
    {
        // Laravel 13 calls compileDropVectorIndex($blueprint, $command) with the name on the command.
        // Blueprint's constructor differs across Laravel versions, so use a stub.
        self::assertSame(
            'DROP INDEX document_embedding IF EXISTS',
            (new Neo4jSchemaGrammar())->compileDropVectorIndex(
                $this->createStub(Blueprint::class),
                new Fluent(['index' => 'document_embedding']),
            )
        );
    }

    public function testRejectsDropVectorIndexWithoutAStringName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Neo4jSchemaGrammar())->compileDropVectorIndex($this->createStub(Blueprint::class), new Fluent());
    }

    public function testRejectsInvalidVectorIndexNames(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Neo4jSchemaGrammar())->compileCreateVectorIndex('bad-name', 'Document', 'embedding', 3);
    }

    public function testCompilesNodeIndexAndUniqueConstraint(): void
    {
        $grammar = new Neo4jSchemaGrammar();

        self::assertSame(
            'CREATE INDEX person_name_index IF NOT EXISTS FOR (n:Person) ON (n.name)',
            $grammar->compileCreateNodeIndex('person_name_index', 'Person', ['name'])
        );

        self::assertSame(
            'CREATE CONSTRAINT person_email_unique IF NOT EXISTS FOR (n:Person) REQUIRE n.email IS UNIQUE',
            $grammar->compileCreateNodeUniqueConstraint('person_email_unique', 'Person', ['email'])
        );

        self::assertSame(
            'CREATE CONSTRAINT person_email_name_unique IF NOT EXISTS FOR (n:Person) REQUIRE (n.email, n.name) IS UNIQUE',
            $grammar->compileCreateNodeUniqueConstraint('person_email_name_unique', 'Person', ['email', 'name'])
        );
    }

    public function testCompilesRelationshipIndexAndDrops(): void
    {
        $grammar = new Neo4jSchemaGrammar();

        self::assertSame(
            'CREATE INDEX acted_in_role_index IF NOT EXISTS FOR ()-[r:ACTED_IN]-() ON (r.role)',
            $grammar->compileCreateRelationshipIndex('acted_in_role_index', 'ACTED_IN', ['role'])
        );

        self::assertSame(
            'DROP INDEX person_name_index IF EXISTS',
            $grammar->compileDropIndex('person_name_index')
        );

        self::assertSame(
            'DROP CONSTRAINT person_email_unique IF EXISTS',
            $grammar->compileDropConstraint('person_email_unique')
        );
    }
}
