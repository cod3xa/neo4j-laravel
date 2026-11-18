<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit;

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

    public function testRejectsInvalidVectorIndexNames(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Neo4jSchemaGrammar())->compileCreateVectorIndex('bad-name', 'Document', 'embedding', 3);
    }
}
