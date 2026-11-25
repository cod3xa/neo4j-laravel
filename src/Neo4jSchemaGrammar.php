<?php

namespace Neo4j\Neo4jLaravel;

use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;
use InvalidArgumentException;

/**
 * Compiles Neo4j schema Cypher (indexes, constraints, vector indexes).
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class Neo4jSchemaGrammar extends Grammar
{
    public function __construct()
    {
    }

    /**
     * @param  list<string>  $properties
     */
    public function compileCreateNodeIndex(string $name, string $label, array $properties): string
    {
        $this->assertIdentifier($name);
        $this->assertIdentifier($label);
        $props = $this->compileNodeProperties('n', $properties);

        return sprintf(
            'CREATE INDEX %s IF NOT EXISTS FOR (n:%s) ON (%s)',
            $name,
            $label,
            $props
        );
    }

    /**
     * @param  list<string>  $properties
     */
    public function compileCreateNodeUniqueConstraint(string $name, string $label, array $properties): string
    {
        $this->assertIdentifier($name);
        $this->assertIdentifier($label);
        $props = $this->compileNodeProperties('n', $properties);
        $require = count($properties) === 1 ? $props : '('.$props.')';

        return sprintf(
            'CREATE CONSTRAINT %s IF NOT EXISTS FOR (n:%s) REQUIRE %s IS UNIQUE',
            $name,
            $label,
            $require
        );
    }

    /**
     * @param  list<string>  $properties
     */
    public function compileCreateRelationshipIndex(string $name, string $type, array $properties): string
    {
        $this->assertIdentifier($name);
        $this->assertIdentifier($type);
        $props = $this->compileNodeProperties('r', $properties);

        return sprintf(
            'CREATE INDEX %s IF NOT EXISTS FOR ()-[r:%s]-() ON (%s)',
            $name,
            $type,
            $props
        );
    }

    public function compileDropIndex(string $name): string
    {
        $this->assertIdentifier($name);

        return sprintf('DROP INDEX %s IF EXISTS', $name);
    }

    public function compileDropConstraint(string $name): string
    {
        $this->assertIdentifier($name);

        return sprintf('DROP CONSTRAINT %s IF EXISTS', $name);
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
     * Accepts an index name (Neo4jConnection::dropVectorIndex()) or Laravel 13's
     * (Blueprint, Fluent) pair. Laravel 13 declares that parent signature, so a
     * `string` parameter here is a fatal incompatibility.
     */
    public function compileDropVectorIndex(mixed $name, mixed $command = null): string
    {
        $index = $command instanceof Fluent ? $command->index : $name;

        if (! is_string($index)) {
            throw new InvalidArgumentException('Vector index name must be a string.');
        }

        return $this->compileDropIndex($index);
    }

    /**
     * Compile DROP INDEX for a Neo4j vector index by name.
     *
     * Named separately from Laravel 13+'s Blueprint-based compileDropVectorIndex().
     */
    public function compileDropNamedVectorIndex(string $name): string
    {
        return $this->compileDropIndex($name);
    }

    /**
     * @param  list<string>  $properties
     */
    private function compileNodeProperties(string $variable, array $properties): string
    {
        if ($properties === []) {
            throw new InvalidArgumentException('At least one property is required for a Neo4j schema object.');
        }

        $parts = [];
        foreach ($properties as $property) {
            $this->assertIdentifier($property);
            $parts[] = $variable.'.'.$property;
        }

        return implode(', ', $parts);
    }

    private function assertIdentifier(string $identifier): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Invalid Neo4j identifier: {$identifier}");
        }
    }
}
