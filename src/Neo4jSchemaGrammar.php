<?php

namespace Neo4j\Neo4jLaravel;

use Illuminate\Database\Schema\Grammars\Grammar;
use InvalidArgumentException;

/**
 * Minimal implementation of a Neo4j Schema Grammar
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class Neo4jSchemaGrammar extends Grammar
{
    // Override constructor to avoid parameter requirements
    public function __construct()
    {
        // No-op constructor
    }

    public function compileCreateVectorIndex(
        string $name,
        string $label,
        string $property,
        int $dimensions,
        string $similarityFunction = 'cosine'
    ): string {
        $this->assertIdentifier($name);
        $this->assertIdentifier($label);
        $this->assertIdentifier($property);

        if ($dimensions < 1) {
            throw new InvalidArgumentException('Vector index dimensions must be at least 1.');
        }

        $similarityFunction = strtolower($similarityFunction);
        if (! in_array($similarityFunction, ['cosine', 'euclidean'], true)) {
            throw new InvalidArgumentException("Unsupported vector similarity function: {$similarityFunction}");
        }

        return sprintf(
            'CREATE VECTOR INDEX %s IF NOT EXISTS FOR (n:%s) ON (n.%s) OPTIONS {indexConfig: {`vector.dimensions`: %d, `vector.similarity_function`: \'%s\'}}',
            $name,
            $label,
            $property,
            $dimensions,
            $similarityFunction
        );
    }

    /**
     * Compile DROP INDEX for a Neo4j vector index by name.
     *
     * Named separately from Laravel 13+'s Blueprint-based compileDropVectorIndex().
     */
    public function compileDropNamedVectorIndex(string $name): string
    {
        $this->assertIdentifier($name);

        return sprintf('DROP INDEX %s IF EXISTS', $name);
    }

    private function assertIdentifier(string $identifier): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Invalid Neo4j identifier: {$identifier}");
        }
    }
}
