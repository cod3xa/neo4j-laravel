<?php

namespace Neo4j\Neo4jLaravel;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use RuntimeException;

/**
 * Query builder with Neo4j-specific clauses such as vector similarity search
 * and graph relationship matching.
 */
final class Neo4jQueryBuilder extends Builder
{
    public ?string $vectorIndex = null;

    /**
     * Graph relationships to include in MATCH as first-class Cypher relationships.
     *
     * @var list<array{
     *     type: string,
     *     related: string,
     *     relationship: string,
     *     relatedAlias: string,
     *     direction: 'both'|'out'|'in'
     * }>
     */
    public array $graphRelationships = [];

    /**
     * Restrict vector search to a named Neo4j vector index.
     */
    public function useVectorIndex(string $name): static
    {
        $this->vectorIndex = $name;

        return $this;
    }

    /**
     * Match a Neo4j relationship between the from() node and a related label.
     *
     * Additional calls append another pattern that shares the from() node (`n`).
     * Aliases must stay distinct across every pattern on the query.
     *
     * Direction can be encoded on the type string (no marker means undirected):
     *   'Bar2' / ':Bar2'   -> (n)-[r:Bar2]-(related)   undirected
     *   'Bar2>' / '>Bar2'  -> (n)-[r:Bar2]->(related)  outgoing
     *   '<Bar2' / 'Bar2<'  -> (n)<-[r:Bar2]-(related)  incoming
     *   '<Bar2>'           -> (n)-[r:Bar2]-(related)   undirected (explicit)
     *
     * Optional 3rd/4th string args are relationship and related-node aliases.
     * Aliases default from the type/label (lcfirst / lower for SCREAMING_SNAKE)
     * and must be distinct from each other and from `n`.
     *
     * A Closure (as 3rd, 4th, or 5th arg) may only add WHERE constraints on the
     * related node; unqualified columns inside the closure are scoped to the
     * related alias. Filter relationship properties with outer where() using
     * the relationship alias (e.g. where('role.roles', ...)).
     *
     * Default RETURN is `n` (safe for Eloquent). Select relationship / related
     * variables explicitly when you need graph rows, e.g. select('n', 'role', 'film').
     *
     * Examples:
     *   ->matchRelationship('Bar2', 'Foo')
     *   ->matchRelationship('Baz>', 'Bar', fn ($q) => $q->where('x', 0))
     *   ->matchRelationship('ACTED_IN', 'Movie', 'role', 'film')
     */
    public function matchRelationship(
        string $relationshipType,
        string $relatedNodeLabel,
        Closure|string|null $relationshipAlias = null,
        Closure|string|null $relatedNodeAlias = null,
        ?Closure $constraints = null
    ): static {
        [$relationshipAlias, $relatedNodeAlias, $constraints] = $this->normalizeMatchRelationshipArgs(
            $relationshipAlias,
            $relatedNodeAlias,
            $constraints
        );

        $parsed = $this->parseRelationshipType($relationshipType);

        $relationshipVariable = $relationshipAlias ?? $this->defaultGraphAlias($parsed['type']);
        $relatedVariable = $relatedNodeAlias ?? $this->defaultGraphAlias($relatedNodeLabel);

        $this->assertDistinctGraphAliases($relationshipVariable, $relatedVariable);

        $this->graphRelationships[] = [
            'type' => $parsed['type'],
            'related' => $relatedNodeLabel,
            'relationship' => $relationshipVariable,
            'relatedAlias' => $relatedVariable,
            'direction' => $parsed['direction'],
        ];

        if ($constraints !== null) {
            $this->applyRelatedConstraints($constraints, $relatedVariable);
        }

        return $this;
    }

    /**
     * Create a directed relationship between two existing nodes.
     *
     * The type must include a direction marker (e.g. `ACTED_IN>` or `<ACTED_IN`).
     * Nodes are matched only by the given property maps — any where()/orderBy()
     * already on this builder is ignored. Optional relationship properties are
     * set on CREATE (not MERGE); calling this twice with the same keys creates
     * duplicate relationships.
     *
     * Example:
     *   DB::table('Person')->insertRelationship(
     *       'ACTED_IN>',
     *       'Movie',
     *       ['id' => 1],
     *       ['id' => 2],
     *       ['roles' => ['Neo']],
     *   );
     *
     * @param  array<string, mixed>  $fromKey
     * @param  array<string, mixed>  $toKey
     * @param  array<string, mixed>  $properties
     */
    public function insertRelationship(
        string $relationshipType,
        string $relatedNodeLabel,
        array $fromKey,
        array $toKey,
        array $properties = [],
        ?string $relationshipAlias = null,
        ?string $relatedNodeAlias = null
    ): bool {
        if ($this->graphRelationships !== []) {
            throw new RuntimeException(
                'insertRelationship() cannot be combined with matchRelationship().'
            );
        }

        if ($fromKey === [] || $toKey === []) {
            throw new InvalidArgumentException(
                'insertRelationship() requires non-empty from and to property maps to MATCH nodes.'
            );
        }

        $parsed = $this->parseRelationshipType($relationshipType);

        if ($parsed['direction'] === 'both') {
            throw new InvalidArgumentException(
                'insertRelationship() requires a directed type (e.g. ACTED_IN> or <ACTED_IN).'
            );
        }

        $relationshipVariable = $relationshipAlias ?? $this->defaultGraphAlias($parsed['type']);
        $relatedVariable = $relatedNodeAlias ?? $this->defaultGraphAlias($relatedNodeLabel);

        $this->assertDistinctGraphAliases($relationshipVariable, $relatedVariable);

        $relationship = [
            'type' => $parsed['type'],
            'related' => $relatedNodeLabel,
            'relationship' => $relationshipVariable,
            'relatedAlias' => $relatedVariable,
            'direction' => $parsed['direction'],
            'fromColumns' => array_keys($fromKey),
            'toColumns' => array_keys($toKey),
            'propertyColumns' => array_keys($properties),
        ];

        /** @var Neo4jQueryGrammar $grammar */
        $grammar = $this->grammar;
        $sql = $grammar->compileInsertRelationship($this, $relationship);

        $bindings = array_merge(
            array_values($fromKey),
            array_values($toKey),
            array_values($properties)
        );

        return $this->connection->insert($sql, $this->cleanBindings($bindings));
    }

    /**
     * Insert or update many nodes in one statement.
     *
     * Same contract as Laravel's upsert(): nodes not found by their $uniqueBy
     * properties are created with every column, existing ones only get the
     * $update columns (all columns when null). Rows are sent as one list
     * parameter instead of flattened bindings, so the Cypher is the same size
     * for any number of rows.
     *
     * Example:
     *   DB::table('Person')->upsert($rows, ['email'], ['name', 'age']);
     *   // UNWIND $rows AS row MERGE (n:Person {email: row.email})
     *   // ON CREATE SET n.name = row.name, n.age = row.age
     *   // ON MATCH SET n.name = row.name, n.age = row.age
     *
     * @param  array<int|string, mixed>  $values
     * @param  array<array-key, mixed>|string  $uniqueBy
     * @param  array<int|string, mixed>|null  $update
     */
    #[\Override]
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        if ($values === []) {
            return 0;
        }

        if ($update === []) {
            return (int) $this->insert($values);
        }

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        /** @var list<array<string, mixed>> $values */
        $values = array_values($values);
        $uniqueBy = array_values((array) $uniqueBy);

        if ($uniqueBy === []) {
            throw new InvalidArgumentException('upsert() requires at least one uniqueBy property to MERGE nodes on.');
        }

        $columns = array_keys($values[0]);
        sort($columns);

        foreach ($uniqueBy as $column) {
            if (! in_array($column, $columns, true)) {
                throw new InvalidArgumentException("upsert() uniqueBy property [{$column}] is missing from the rows.");
            }
        }

        $rows = [];
        foreach ($values as $row) {
            $keys = array_keys($row);
            sort($keys);

            if ($keys !== $columns) {
                throw new InvalidArgumentException('upsert() rows must all have the same properties.');
            }

            foreach ($row as $column => $value) {
                if ($value instanceof Expression) {
                    throw new InvalidArgumentException("upsert() row values cannot be raw expressions ([{$column}]).");
                }
            }

            $rows[] = $this->connection instanceof Connection ? $this->connection->prepareBindings($row) : $row;
        }

        $update ??= $columns;

        $this->applyBeforeQueryCallbacks();

        $bindings = ['rows' => $rows];
        $parameter = 0;
        foreach ($update as $key => $value) {
            if (is_string($key) && ! $value instanceof Expression) {
                $bindings['u'.$parameter++] = $value;
            }
        }

        return $this->connection->affectingStatement(
            $this->grammar->compileUpsert($this, $values, $uniqueBy, $update),
            $bindings
        );
    }

    /**
     * Keep whereIn subqueries as builders (same AST shape as whereExists) so the
     * grammar can emit Cypher COLLECT { } without shadowing the outer `n`.
     *
     * Covers the full isQueryable() set: Closure, Query\Builder, Eloquent\Builder,
     * and Relation — not only Closure|Query\Builder.
     *
     * @param  \Illuminate\Contracts\Database\Query\Expression|string|\Closure|self  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @param  bool  $not
     */
    #[\Override]
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if ($this->isQueryable($values)) {
            if ($values instanceof Closure) {
                $query = $this->forSubQuery();
                $values($query);
            } else {
                $query = ($values instanceof EloquentBuilder || $values instanceof Relation)
                    ? $values->toBase()
                    : $values;
            }

            $this->wheres[] = [
                'type' => $not ? 'NotInSub' : 'InSub',
                'column' => $column,
                'query' => $query,
                'boolean' => $boolean,
            ];

            $this->addBinding($query->getBindings(), 'where');

            return $this;
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }

    /**
     * Filter by cosine similarity against a stored embedding property.
     *
     * Same signature as Laravel 13's whereVectorSimilarTo(); this driver
     * requires a precomputed embedding array (the app generates embeddings).
     *
     * @param  array<int, float>  $vector
     */
    public function whereVectorSimilarTo(
        $column,
        $vector,
        $minSimilarity = 0.6,
        $order = true
    ): static {
        if (! is_array($vector) || $vector === []) {
            throw new InvalidArgumentException(
                'whereVectorSimilarTo() expects a non-empty embedding array; this driver does not generate embeddings.'
            );
        }

        foreach ($vector as $component) {
            if (! is_numeric($component)) {
                throw new InvalidArgumentException('Embedding vectors must contain only numeric components.');
            }
        }

        $this->wheres[] = [
            'type' => 'VectorSimilar',
            'column' => $column,
            'boolean' => 'and',
            'order' => (bool) $order,
        ];

        $this->addBinding(new VectorBinding(array_map(
            static fn ($value): float => (float) $value,
            array_values($vector)
        )), 'where');
        $this->addBinding((float) $minSimilarity, 'where');

        return $this;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?Closure}
     */
    private function normalizeMatchRelationshipArgs(
        Closure|string|null $relationshipAlias,
        Closure|string|null $relatedNodeAlias,
        ?Closure $constraints
    ): array {
        if ($relationshipAlias instanceof Closure) {
            if ($relatedNodeAlias !== null || $constraints !== null) {
                throw new InvalidArgumentException(
                    'matchRelationship() closure must be the last argument.'
                );
            }

            return [null, null, $relationshipAlias];
        }

        if ($relatedNodeAlias instanceof Closure) {
            if ($constraints !== null) {
                throw new InvalidArgumentException(
                    'matchRelationship() closure must be the last argument.'
                );
            }

            return [$relationshipAlias, null, $relatedNodeAlias];
        }

        return [$relationshipAlias, $relatedNodeAlias, $constraints];
    }

    /**
     * @return array{type: string, direction: 'both'|'out'|'in'}
     */
    private function parseRelationshipType(string $relationshipType): array
    {
        $normalized = preg_replace('/\s+/', '', trim($relationshipType)) ?? trim($relationshipType);
        $normalized = ltrim($normalized, ':');

        if ($normalized === '' || $normalized === '<' || $normalized === '>' || $normalized === '<>') {
            throw new InvalidArgumentException("Invalid Neo4j relationship type: {$relationshipType}");
        }

        if (str_starts_with($normalized, '<') && str_ends_with($normalized, '>')) {
            $name = substr($normalized, 1, -1);

            return ['type' => $name, 'direction' => 'both'];
        }

        if (str_starts_with($normalized, '<')) {
            return ['type' => substr($normalized, 1), 'direction' => 'in'];
        }

        if (str_ends_with($normalized, '<')) {
            return ['type' => substr($normalized, 0, -1), 'direction' => 'in'];
        }

        if (str_starts_with($normalized, '>')) {
            return ['type' => substr($normalized, 1), 'direction' => 'out'];
        }

        if (str_ends_with($normalized, '>')) {
            return ['type' => substr($normalized, 0, -1), 'direction' => 'out'];
        }

        return ['type' => $normalized, 'direction' => 'both'];
    }

    private function assertDistinctGraphAliases(string $relationshipAlias, string $relatedNodeAlias): void
    {
        if ($relationshipAlias === 'n' || $relatedNodeAlias === 'n') {
            throw new InvalidArgumentException(
                'matchRelationship()/insertRelationship() aliases cannot be "n" (reserved for the from() node).'
            );
        }

        if ($relationshipAlias === $relatedNodeAlias) {
            throw new InvalidArgumentException(
                "Relationship and related-node aliases collide ({$relationshipAlias}); pass distinct aliases."
            );
        }

        foreach ($this->graphRelationships as $existing) {
            foreach ([$relationshipAlias, $relatedNodeAlias] as $alias) {
                if ($alias === $existing['relationship'] || $alias === $existing['relatedAlias']) {
                    throw new InvalidArgumentException(
                        "matchRelationship() alias \"{$alias}\" is already used; pass distinct aliases."
                    );
                }
            }
        }
    }

    private function applyRelatedConstraints(Closure $constraints, string $relatedAlias): void
    {
        $query = $this->forSubQuery();
        $constraints($query);

        $this->assertRelatedConstraintsAreWhereOnly($query);

        foreach ($query->wheres ?? [] as $where) {
            $this->wheres[] = $this->qualifyRelatedWhere($where, $relatedAlias);
        }

        $this->addBinding($query->getRawBindings()['where'] ?? [], 'where');
    }

    private function assertRelatedConstraintsAreWhereOnly(Builder $query): void
    {
        $hasGraphRelationships = $query instanceof self && $query->graphRelationships !== [];

        if (
            $hasGraphRelationships
            || ! empty($query->joins)
            || ! empty($query->orders)
            || ! empty($query->groups)
            || ! empty($query->havings)
            || ! empty($query->columns)
            || ! empty($query->unions)
            || $query->aggregate !== null
            || $query->limit !== null
            || $query->offset !== null
        ) {
            throw new InvalidArgumentException(
                'matchRelationship() closure may only add WHERE constraints on the related node.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $where
     * @return array<string, mixed>
     */
    private function qualifyRelatedWhere(array $where, string $relatedAlias): array
    {
        if (isset($where['column']) && is_string($where['column']) && ! str_contains($where['column'], '.')) {
            $where['column'] = $relatedAlias.'.'.$where['column'];
        }

        if (($where['type'] ?? null) === 'Nested' && isset($where['query']) && $where['query'] instanceof Builder) {
            $nested = $where['query'];
            foreach ($nested->wheres ?? [] as $index => $nestedWhere) {
                $nested->wheres[$index] = $this->qualifyRelatedWhere($nestedWhere, $relatedAlias);
            }
        }

        return $where;
    }

    private function defaultGraphAlias(string $name): string
    {
        if (strtoupper($name) === $name) {
            return strtolower($name);
        }

        return lcfirst($name);
    }
}
