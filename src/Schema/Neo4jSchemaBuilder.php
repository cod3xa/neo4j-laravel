<?php

namespace Neo4j\Neo4jLaravel\Schema;

use Closure;
use Illuminate\Database\Schema\Builder;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use Neo4j\Neo4jLaravel\Neo4jSchemaGrammar;
use RuntimeException;

/**
 * Graph-native schema builder for Neo4j connections.
 *
 * @property Neo4jConnection $connection
 * @property Neo4jSchemaGrammar $grammar
 *
 * @api
 */
class Neo4jSchemaBuilder extends Builder
{
    /**
     * Define indexes / constraints for a node label without creating nodes.
     *
     * @param  Closure(Neo4jNodeBlueprint): void  $callback
     */
    public function node(string $label, Closure $callback): void
    {
        $blueprint = new Neo4jNodeBlueprint($label);
        $callback($blueprint);
        $this->buildNode($blueprint);
    }

    /**
     * Define indexes for a relationship type without creating relationships.
     *
     * @param  Closure(Neo4jRelationshipBlueprint): void  $callback
     */
    public function relationship(string $type, Closure $callback): void
    {
        $blueprint = new Neo4jRelationshipBlueprint($type);
        $callback($blueprint);
        $this->buildRelationship($blueprint);
    }

    /**
     * Drop a named range / vector index if it exists.
     */
    public function dropIndex(string $name): void
    {
        $this->connection->statement($this->grammar()->compileDropIndex($name));
    }

    /**
     * Drop a named constraint if it exists.
     */
    public function dropConstraint(string $name): void
    {
        $this->connection->statement($this->grammar()->compileDropConstraint($name));
    }

    /**
     * @throws RuntimeException
     */
    public function create($table, Closure $callback)
    {
        throw new RuntimeException(
            'Schema::create() is not supported for Neo4j. Use Schema::connection(\'neo4j\')->node() or relationship().'
        );
    }

    /**
     * @throws RuntimeException
     */
    public function drop($table)
    {
        throw new RuntimeException(
            'Schema::drop() is not supported for Neo4j. Use dropIndex() / dropConstraint() for schema objects.'
        );
    }

    /**
     * @throws RuntimeException
     */
    public function dropIfExists($table)
    {
        throw new RuntimeException(
            'Schema::dropIfExists() is not supported for Neo4j. Use dropIndex() / dropConstraint().'
        );
    }

    public function hasTable($table)
    {
        throw new RuntimeException(
            'Schema::hasTable() is not supported for Neo4j; node labels are not relational tables.'
        );
    }

    private function buildNode(Neo4jNodeBlueprint $blueprint): void
    {
        $grammar = $this->grammar();
        $label = $blueprint->getLabel();

        foreach ($blueprint->getCommands() as $command) {
            $properties = $command['properties'];
            $name = $command['name'] ?? $this->defaultSchemaName($label, $properties, $command['type']);

            $cypher = match ($command['type']) {
                'unique' => $grammar->compileCreateNodeUniqueConstraint($name, $label, $properties),
                'index' => $grammar->compileCreateNodeIndex($name, $label, $properties),
                'vector' => $grammar->compileCreateVectorIndex(
                    $name,
                    $label,
                    $properties[0],
                    $command['dimensions'] ?? 1,
                    $command['similarity'] ?? 'cosine'
                ),
                default => throw new RuntimeException('Unsupported node schema command: '.$command['type']),
            };

            $this->connection->statement($cypher);
        }
    }

    private function buildRelationship(Neo4jRelationshipBlueprint $blueprint): void
    {
        $grammar = $this->grammar();
        $type = $blueprint->getType();

        foreach ($blueprint->getCommands() as $command) {
            $properties = $command['properties'];
            $name = $command['name'] ?? $this->defaultSchemaName($type, $properties, $command['type']);

            $cypher = match ($command['type']) {
                'index' => $grammar->compileCreateRelationshipIndex($name, $type, $properties),
                default => throw new RuntimeException('Unsupported relationship schema command: '.$command['type']),
            };

            $this->connection->statement($cypher);
        }
    }

    /**
     * @param  list<string>  $properties
     */
    private function defaultSchemaName(string $owner, array $properties, string $kind): string
    {
        $suffix = match ($kind) {
            'unique' => 'unique',
            'vector' => 'vector',
            default => 'index',
        };

        return strtolower($owner.'_'.implode('_', $properties).'_'.$suffix);
    }

    private function grammar(): Neo4jSchemaGrammar
    {
        $grammar = $this->grammar;

        if (! $grammar instanceof Neo4jSchemaGrammar) {
            throw new RuntimeException('Neo4jSchemaBuilder requires Neo4jSchemaGrammar.');
        }

        return $grammar;
    }
}
