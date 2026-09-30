<?php

namespace Neo4j\Neo4jLaravel;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use InvalidArgumentException;
use RuntimeException;
use WikibaseSolutions\CypherDSL\Expressions\Operators\In;
use WikibaseSolutions\CypherDSL\Expressions\Parameter;
use WikibaseSolutions\CypherDSL\Expressions\Property;
use WikibaseSolutions\CypherDSL\Patterns\Node;
use WikibaseSolutions\CypherDSL\Query;
use WikibaseSolutions\CypherDSL\Types\PropertyTypes\BooleanType;

/**
 * Neo4j Cypher query grammar backed by php-cypher-dsl.
 *
 * Supported today (select path):
 *   - from() / table() -> MATCH (n:Label)
 *   - join()           -> MATCH (n:Label), (join:JoinLabel) + equality WHERE
 *                        (cartesian-product style; inner/cross only)
 *   - matchRelationship(type, relatedLabel) -> MATCH (n:Label)-[rel:Type]-(related:Related)
 *     (direction markers Type> / <Type; optional WHERE-only related-node closure;
 *      further calls add patterns that share n)
 *     Default RETURN is n (select rel/related explicitly for graph rows)
 *     With a relationship MATCH, count(*) -> count(n) counts paths (not distinct nodes)
 *   - insertRelationship() -> MATCH nodes by property maps + CREATE directed relationship
 *     (CREATE not MERGE; builder where() is ignored)
 *   - select(columns)  -> RETURN n.col, ... (including `as` aliases / table.*)
 *   - where (Basic, Null, NotNull, In, NotIn, Between/NotBetween, Nested,
 *            Column, raw, Date, Time, Day, Month, Year)
 *   - whereVectorSimilarTo -> CALL db.index.vector.queryNodes
 *   - aggregates, exists()
 *   - groupBy / having (basic; not combined with joins / relationships yet)
 *   - orderBy -> ORDER BY
 *   - limit   -> LIMIT
 *   - offset  -> SKIP
 *   - union / unionAll
 *   - whereIn / whereNotIn with subquery -> IN COLLECT { ... }
 *   - whereExists / whereNotExists -> EXISTS { ... } / NOT EXISTS { ... }
 *   - insert   -> CREATE
 *   - update   -> MATCH / SET (including increment/decrement expressions)
 *   - delete   -> MATCH / DETACH DELETE
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class Neo4jQueryGrammar extends Grammar
{
    private int $parameterIndex = 0;

    private int $subqueryIndex = 0;

    public function __construct()
    {
        // Connection may be injected via setConnection() on Laravel 11+.
    }

    /**
     * Whether this grammar supports Laravel vector-distance query methods.
     */
    public function supportsVectorDistance(): bool
    {
        return true;
    }

    /**
     * Compile a cosine-distance expression for the given embedding property.
     *
     * Laravel 13 uses this for pgvector; Neo4j vector search compiles the
     * full query via compileSelect() instead of embedding this in MATCH.
     */
    public function compileVectorDistanceExpression($column): string
    {
        $property = $this->compileColumn((string) $column)->toQuery();

        return "(1.0 - vector.similarity.cosine({$property}, \$queryVector))";
    }

    /**
     * Wrap a column so increment/decrement and columnize emit Cypher properties.
     *
     * @param  \Illuminate\Contracts\Database\Query\Expression|string  $value
     */
    #[\Override]
    public function wrap($value)
    {
        if ($this->isExpression($value)) {
            return $this->getValue($value);
        }

        if (! is_string($value) || $value === '') {
            return parent::wrap($value);
        }

        if ($value === '*') {
            return '*';
        }

        return $this->compileColumn($value)->toQuery();
    }

    #[\Override]
    protected function wrapValue($value)
    {
        if ($value === '*') {
            return $value;
        }

        $this->assertIdentifier($value);

        return $value;
    }

    #[\Override]
    public function compileSelect(Builder $query): string
    {
        $this->parameterIndex = 0;
        $this->subqueryIndex = 0;

        return $this->compileSelectBody($query);
    }

    /**
     * Compile a select without resetting the shared parameter counter (unions).
     */
    private function compileSelectBody(Builder $query): string
    {
        if ($this->hasVectorSimilarity($query)) {
            if ($this->graphRelationships($query) !== []) {
                throw new RuntimeException('matchRelationship() cannot be combined with whereVectorSimilarTo().');
            }

            if (! empty($query->unions) || ! empty($query->groups) || ! empty($query->havings) || $query->aggregate !== null) {
                throw new RuntimeException('Vector similarity queries cannot be combined with union, groupBy, having, or aggregates.');
            }

            return $this->compileVectorSelect($query);
        }

        $sql = $this->compileMatchSelect($query);

        if (! empty($query->unions)) {
            foreach ($query->unions as $union) {
                $conjunction = ! empty($union['all']) ? ' UNION ALL ' : ' UNION ';
                $sql .= $conjunction.$this->compileMatchSelect($union['query']);
            }

            if (! empty($query->unionOrders)) {
                $orders = $this->compileOrders($query, $query->unionOrders);
                if ($orders !== '') {
                    $sql .= ' '.$orders;
                }
            }

            if (isset($query->unionOffset)) {
                $sql .= ' SKIP '.(int) $query->unionOffset;
            }

            if (isset($query->unionLimit)) {
                $sql .= ' LIMIT '.(int) $query->unionLimit;
            }
        }

        return $sql;
    }

    private function compileMatchSelect(Builder $query): string
    {
        if (! empty($query->joins) && $this->hasVectorSimilarity($query)) {
            throw new RuntimeException('Joins cannot be combined with whereVectorSimilarTo().');
        }

        $variables = $this->compileVariableMap($query);
        $prefix = $this->compileMatchPrefix($query);
        $where = $this->compileWhereExpression($this->mergeJoinWheres($query), $variables);

        if ($where !== null) {
            $prefix .= ' '.Query::new()->where($where)->build();
        }

        if ($query->aggregate !== null) {
            return $this->compileAggregateSelect($query, $prefix, $variables);
        }

        if (! empty($query->groups) || ! empty($query->havings)) {
            if (! empty($query->joins) || $this->graphRelationships($query) !== []) {
                throw new RuntimeException('groupBy/having with joins or matchRelationship() is not supported on Neo4j Query Builder yet.');
            }

            return $this->compileGroupedSelect($query, $prefix, $variables);
        }

        $cypher = $prefix.' RETURN '.$this->compileReturnClause($query, $variables);

        $orders = $this->compileOrders($query, $query->orders ?? []);
        if ($orders !== '') {
            $cypher .= ' '.$orders;
        }

        if ($query->offset !== null) {
            $cypher .= ' SKIP '.(int) $query->offset;
        }

        if ($query->limit !== null) {
            $cypher .= ' LIMIT '.(int) $query->limit;
        }

        return $cypher;
    }

    /**
     * @return list<array{
     *     type: string,
     *     related: string,
     *     relationship: string,
     *     relatedAlias: string,
     *     direction: 'both'|'out'|'in'
     * }>
     */
    private function graphRelationships(Builder $query): array
    {
        return $query instanceof Neo4jQueryBuilder ? $query->graphRelationships : [];
    }

    /**
     * Map table / alias names to Cypher variables.
     *
     * The primary from() label is always bound to "n". Joined labels use their
     * table name (or explicit alias) as the variable, enabling cartesian joins:
     * MATCH (n:Role), (RoleUser:RoleUser) WHERE n.id = RoleUser.role_id
     *
     * matchRelationship() aliases map both the type/label and the Cypher
     * variable so where('bar2.status') and where('Bar2.status') both work.
     *
     * Primary from()/alias mappings are never overwritten when the related
     * label matches the from label — use the related alias for the other node.
     *
     * @return array<string, string>
     */
    private function compileVariableMap(Builder $query): array
    {
        $from = $this->parseTableName($query->from);
        $map = [
            '' => 'n',
            $from['name'] => 'n',
        ];

        if ($from['alias'] !== null) {
            $map[$from['alias']] = 'n';
        }

        foreach ($query->joins ?? [] as $join) {
            $type = strtolower((string) $join->type);
            if (! in_array($type, ['inner', 'cross'], true)) {
                throw new RuntimeException("Unsupported join type for Neo4j Query Builder: {$join->type}");
            }

            if (! is_string($join->table)) {
                throw new RuntimeException('Subquery and expression joins are not supported on Neo4j Query Builder.');
            }

            $table = $this->parseTableName($join->table);
            $variable = $table['alias'] ?? $table['name'];
            $this->assertIdentifier($variable);
            $map[$table['name']] = $variable;

            if ($table['alias'] !== null) {
                $map[$table['alias']] = $variable;
            }
        }

        foreach ($this->graphRelationships($query) as $relationship) {
            $this->assertIdentifier($relationship['relationship']);
            $this->assertIdentifier($relationship['relatedAlias']);
            $this->assertIdentifier($relationship['type']);
            $this->assertLabel($relationship['related']);

            if (! array_key_exists($relationship['type'], $map)) {
                $map[$relationship['type']] = $relationship['relationship'];
            }
            $map[$relationship['relationship']] = $relationship['relationship'];

            if (! array_key_exists($relationship['related'], $map)) {
                $map[$relationship['related']] = $relationship['relatedAlias'];
            }
            $map[$relationship['relatedAlias']] = $relationship['relatedAlias'];
        }

        return $map;
    }

    /**
     * @return array{name: string, alias: string|null}
     */
    private function parseTableName(mixed $table): array
    {
        if (! is_string($table) || $table === '') {
            throw new InvalidArgumentException('Neo4j Query Builder requires a node label via from()/table().');
        }

        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $table, $matches) === 1) {
            $name = trim($matches[1]);
            $alias = trim($matches[2]);
            $this->assertLabel($name);
            $this->assertIdentifier($alias);

            return ['name' => $name, 'alias' => $alias];
        }

        $this->assertLabel($table);

        return ['name' => $table, 'alias' => null];
    }

    private function compileMatchPrefix(Builder $query): string
    {
        $from = $this->parseTableName($query->from);
        $relationships = $this->graphRelationships($query);

        if ($relationships !== [] && ! empty($query->joins)) {
            throw new RuntimeException('matchRelationship() cannot be combined with join() on Neo4j Query Builder.');
        }

        if ($relationships !== []) {
            $patterns = [];

            foreach ($relationships as $index => $relationship) {
                $patterns[] = $this->compileRelationshipPattern($from['name'], $relationship, $index === 0);
            }

            return 'MATCH '.implode(', ', $patterns);
        }

        $patterns = ['(n:'.$from['name'].')'];
        $seen = ['n' => true];

        foreach ($query->joins ?? [] as $join) {
            $table = $this->parseTableName($join->table);
            $variable = $table['alias'] ?? $table['name'];

            if (isset($seen[$variable])) {
                continue;
            }

            $seen[$variable] = true;
            $patterns[] = "({$variable}:{$table['name']})";
        }

        return 'MATCH '.implode(', ', $patterns);
    }

    /**
     * @param  array{
     *     type: string,
     *     related: string,
     *     relationship: string,
     *     relatedAlias: string,
     *     direction?: 'both'|'out'|'in'
     * }  $relationship
     */
    private function compileRelationshipPattern(string $fromLabel, array $relationship, bool $labelFromNode): string
    {
        $this->assertIdentifier($relationship['type']);
        $this->assertIdentifier($relationship['relationship']);
        $this->assertIdentifier($relationship['relatedAlias']);
        $this->assertLabel($relationship['related']);

        $left = $labelFromNode ? "(n:{$fromLabel})" : '(n)';
        $rel = "[{$relationship['relationship']}:{$relationship['type']}]";
        $related = "({$relationship['relatedAlias']}:{$relationship['related']})";
        $direction = $relationship['direction'] ?? 'both';

        return match ($direction) {
            'out' => "{$left}-{$rel}->{$related}",
            'in' => "{$left}<-{$rel}-{$related}",
            default => "{$left}-{$rel}-{$related}",
        };
    }

    /**
     * Join ON conditions are column equalities; fold them into MATCH WHERE.
     *
     * @return list<array<string, mixed>>
     */
    private function mergeJoinWheres(Builder $query): array
    {
        $wheres = [];

        foreach ($query->joins ?? [] as $join) {
            foreach ($join->wheres ?? [] as $where) {
                $wheres[] = $where;
            }
        }

        foreach ($query->wheres ?? [] as $where) {
            $wheres[] = $where;
        }

        return $wheres;
    }

    private function assertLabel(string $label): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?::[A-Za-z_][A-Za-z0-9_]*)*$/', $label)) {
            throw new InvalidArgumentException("Invalid Neo4j label: {$label}");
        }
    }

    /**
     * @param  array{function: string, columns: array<int, mixed>}  $aggregate
     * @param  array<string, string>  $variables
     */
    private function compileAggregateSelect(Builder $query, string $prefix, array $variables): string
    {
        $aggregate = $query->aggregate;
        $function = strtolower((string) $aggregate['function']);
        $this->assertIdentifier($function);

        $argument = $this->compileAggregateArgument($function, $aggregate['columns'], (bool) $query->distinct, $variables);
        $aggregateReturn = "{$function}({$argument}) AS aggregate";

        if (! empty($query->groups) || ! empty($query->havings)) {
            $withParts = $this->compileGroupExpressions($query, $variables);
            $withParts[] = $aggregateReturn;
            $cypher = $prefix.' WITH '.implode(', ', $withParts);

            if (! empty($query->havings)) {
                $cypher .= ' WHERE '.$this->compileNeo4jHavings($query);
            }

            $returnParts = [];
            foreach ($query->groups ?? [] as $group) {
                $returnParts[] = $this->groupAlias($group);
            }
            $returnParts[] = 'aggregate';

            return $cypher.' RETURN '.implode(', ', $returnParts);
        }

        return $prefix.' RETURN '.$aggregateReturn;
    }

    /**
     * @param  array<int, mixed>  $columns
     * @param  array<string, string>  $variables
     */
    private function compileAggregateArgument(string $function, array $columns, bool $distinct, array $variables = []): string
    {
        $column = $columns[0] ?? '*';

        if ($this->isExpression($column)) {
            $argument = $this->getValue($column);
        } elseif ($column === '*' || $column === 'n.*') {
            if ($function !== 'count') {
                throw new RuntimeException("Aggregate {$function}() requires a column on Neo4j Query Builder.");
            }
            $argument = 'n';
        } else {
            $argument = $this->compileColumn((string) $column, $variables)->toQuery();
        }

        if ($distinct && $argument !== 'n') {
            return 'DISTINCT '.$argument;
        }

        return $argument;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function compileGroupedSelect(Builder $query, string $prefix, array $variables): string
    {
        $withParts = $this->compileGroupExpressions($query, $variables);

        foreach ($query->columns ?? [] as $column) {
            if (! is_string($column) || $column === '*') {
                continue;
            }

            $alias = $this->propertyName($column);
            $alreadyGrouped = false;
            foreach ($query->groups ?? [] as $group) {
                if (! $this->isExpression($group) && $this->propertyName((string) $group) === $alias) {
                    $alreadyGrouped = true;
                    break;
                }
            }

            if (! $alreadyGrouped) {
                $withParts[] = $this->compileColumn($column, $variables)->toQuery().' AS '.$alias;
            }
        }

        if ($withParts === []) {
            throw new RuntimeException('groupBy() requires at least one grouping column on Neo4j Query Builder.');
        }

        // Without an aggregate, DISTINCT is required so Cypher collapses rows like SQL GROUP BY.
        $cypher = $prefix.' WITH DISTINCT '.implode(', ', $withParts);

        if (! empty($query->havings)) {
            $cypher .= ' WHERE '.$this->compileNeo4jHavings($query);
        }

        $returnParts = [];
        foreach ($query->groups ?? [] as $group) {
            $returnParts[] = $this->groupAlias($group);
        }

        foreach ($query->columns ?? [] as $column) {
            if (! is_string($column) || $column === '*') {
                continue;
            }
            $alias = $this->propertyName($column);
            if (! in_array($alias, $returnParts, true)) {
                $returnParts[] = $alias;
            }
        }

        if ($returnParts === []) {
            $returnParts = array_map(fn ($group) => $this->groupAlias($group), $query->groups ?? []);
        }

        $cypher .= ' RETURN '.implode(', ', $returnParts);

        $orders = $this->compileOrders($query, $query->orders ?? []);
        if ($orders !== '') {
            $cypher .= ' '.$orders;
        }

        if ($query->offset !== null) {
            $cypher .= ' SKIP '.(int) $query->offset;
        }

        if ($query->limit !== null) {
            $cypher .= ' LIMIT '.(int) $query->limit;
        }

        return $cypher;
    }

    /**
     * @param  array<string, string>  $variables
     * @return list<string>
     */
    private function compileGroupExpressions(Builder $query, array $variables): array
    {
        $parts = [];

        foreach ($query->groups ?? [] as $group) {
            if ($this->isExpression($group)) {
                $parts[] = $this->getValue($group);

                continue;
            }

            $property = $this->compileColumn((string) $group, $variables)->toQuery();
            $parts[] = $property.' AS '.$this->propertyName((string) $group);
        }

        return $parts;
    }

    private function groupAlias(mixed $group): string
    {
        if ($this->isExpression($group)) {
            $value = trim($this->getValue($group));
            if (preg_match('/\s+AS\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $value, $matches) === 1) {
                return $matches[1];
            }

            throw new RuntimeException('Raw groupBy expressions must end with AS alias for Neo4j Query Builder.');
        }

        return $this->propertyName((string) $group);
    }

    private function compileNeo4jHavings(Builder $query): string
    {
        $parts = [];

        foreach ($query->havings ?? [] as $having) {
            $boolean = strtolower((string) ($having['boolean'] ?? 'and'));
            $compiled = $this->compileHaving($having);
            $parts[] = ($parts === [] ? '' : strtoupper($boolean).' ').$compiled;
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $having
     */
    #[\Override]
    protected function compileHaving(array $having): string
    {
        return match ($having['type'] ?? null) {
            'Raw' => (string) $having['sql'],
            'Null' => $this->havingColumnName($having).' IS NULL',
            'NotNull' => $this->havingColumnName($having).' IS NOT NULL',
            'between' => $this->compileNeo4jHavingBetween($having),
            'Nested' => '('.$this->compileNeo4jHavings($having['query']).')',
            'Expression' => $this->getValue($having['column']),
            default => $this->compileNeo4jBasicHaving($having),
        };
    }

    /**
     * @param  array<string, mixed>  $having
     */
    private function compileNeo4jBasicHaving(array $having): string
    {
        $column = $this->havingColumnName($having);
        $operator = $this->normalizeComparisonOperator((string) $having['operator']);
        $parameter = '$'.$this->nextParameterName();

        return "{$column} {$operator} {$parameter}";
    }

    /**
     * @param  array<string, mixed>  $having
     */
    private function compileNeo4jHavingBetween(array $having): string
    {
        $column = $this->havingColumnName($having);
        $low = '$'.$this->nextParameterName();
        $high = '$'.$this->nextParameterName();
        $clause = "{$column} >= {$low} AND {$column} <= {$high}";

        return ! empty($having['not']) ? "NOT ({$clause})" : $clause;
    }

    /**
     * @param  array<string, mixed>  $having
     */
    private function havingColumnName(array $having): string
    {
        $column = $having['column'] ?? null;

        if ($this->isExpression($column)) {
            return $this->getValue($column);
        }

        $name = (string) $column;

        if (str_contains($name, '.')) {
            return $this->propertyName($name);
        }

        $this->assertIdentifier($name);

        return $name;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function compileReturnClause(Builder $query, array $variables): string
    {
        $columns = $query->columns ?? null;
        $distinct = $query->distinct ? 'DISTINCT ' : '';

        if ($columns === null || $columns === [] || $columns === ['*']) {
            return $distinct.$this->compileDefaultReturn($query);
        }

        $parts = [];
        foreach ($columns as $column) {
            if ($this->isExpression($column)) {
                $parts[] = $this->getValue($column);

                continue;
            }

            if (! is_string($column)) {
                return $distinct.$this->compileDefaultReturn($query);
            }

            if ($column === '*') {
                $parts[] = $this->compileDefaultReturn($query);

                continue;
            }

            $parts[] = $this->compileReturnColumn($column, $variables);
        }

        return $distinct.implode(', ', $parts);
    }

    private function compileDefaultReturn(Builder $query): string
    {
        return 'n';
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function compileReturnColumn(string $column, array $variables): string
    {
        $alias = null;

        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $column, $matches) === 1) {
            $column = trim($matches[1]);
            $alias = trim($matches[2]);
            $this->assertIdentifier($alias);
        }

        if ($column === '*') {
            $expression = 'n';
        } elseif (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\.\*$/', $column, $matches) === 1) {
            $expression = $variables[$matches[1]] ?? 'n';
        } elseif (! str_contains($column, '.') && (isset($variables[$column]) || in_array($column, $variables, true))) {
            // Whole node / relationship variable (e.g. select bar2, foo).
            $expression = $variables[$column] ?? $column;
        } else {
            $expression = $this->compileColumn($column, $variables)->toQuery();
        }

        return $alias === null ? $expression : "{$expression} AS {$alias}";
    }

    /**
     * @param  array<int, array<string, mixed>>  $orders
     */
    #[\Override]
    public function compileOrders(Builder $query, $orders): string
    {
        if (empty($orders)) {
            return '';
        }

        $variables = $this->compileVariableMap($query);
        $parts = [];
        foreach ($orders as $order) {
            if ($this->isExpression($order['column'] ?? null)) {
                $column = $this->getValue($order['column']);
            } else {
                $columnName = (string) $order['column'];
                // After WITH aliases (groupBy), order by the alias when present.
                $column = ! empty($query->groups) && ! str_contains($columnName, '.')
                    ? $this->propertyName($columnName)
                    : $this->compileColumn($columnName, $variables)->toQuery();
            }
            $direction = strtolower((string) ($order['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
            $parts[] = "{$column} {$direction}";
        }

        return 'ORDER BY '.implode(', ', $parts);
    }

    #[\Override]
    public function compileWheres(Builder $query): string
    {
        $this->parameterIndex = 0;
        $this->subqueryIndex = 0;

        $wheres = $this->mergeJoinWheres($query);

        if ($wheres === []) {
            return '';
        }

        $expression = $this->compileWhereExpression($wheres, $this->compileVariableMap($query));

        return $expression === null ? '' : Query::new()->where($expression)->build();
    }

    #[\Override]
    public function compileExists(Builder $query): string
    {
        $this->parameterIndex = 0;
        $this->subqueryIndex = 0;

        if ($this->hasVectorSimilarity($query)) {
            throw new RuntimeException('exists() is not supported with whereVectorSimilarTo().');
        }

        $variables = $this->compileVariableMap($query);
        $cypher = $this->compileMatchPrefix($query);
        $where = $this->compileWhereExpression($this->mergeJoinWheres($query), $variables);

        if ($where !== null) {
            $cypher .= ' '.Query::new()->where($where)->build();
        }

        return $cypher.' RETURN true AS exists LIMIT 1';
    }

    /**
     * @param  array<int, array<string, mixed>>  $wheres
     * @param  array<string, string>  $variables
     */
    private function compileWhereExpression(array $wheres, array $variables): ?BooleanType
    {
        $expression = null;

        foreach ($wheres as $where) {
            $clause = $this->compileWhereClause($where, $variables);

            if ($expression === null) {
                $expression = $clause;

                continue;
            }

            $expression = strtolower((string) ($where['boolean'] ?? 'and')) === 'or'
                ? $expression->or($clause)
                : $expression->and($clause);
        }

        return $expression;
    }

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string, string>  $variables
     */
    private function compileWhereClause(array $where, array $variables): BooleanType
    {
        $type = $where['type'] ?? null;

        return match ($type) {
            'Basic' => $this->compileBasicWhere($where, $variables),
            'Null' => $this->compileColumn((string) $where['column'], $variables)->isNull(),
            'NotNull' => $this->compileColumn((string) $where['column'], $variables)->isNotNull(),
            'In' => $this->compileInWhere($where, $variables, false),
            'NotIn' => $this->compileInWhere($where, $variables, true),
            'InSub' => $this->compileInSubWhere($where, $variables, false),
            'NotInSub' => $this->compileInSubWhere($where, $variables, true),
            'Exists' => $this->compileExistsWhere($where, $variables, false),
            'NotExists' => $this->compileExistsWhere($where, $variables, true),
            'between' => $this->compileBetweenWhere($where, $variables),
            'Nested' => $this->compileNestedWhere($where, $variables),
            'Column' => $this->compileColumnWhere($where, $variables),
            'raw', 'Raw' => $this->compileRawWhere($where),
            'Date' => $this->compileDateWhere($where, $variables, 'date'),
            'Time' => $this->compileDateWhere($where, $variables, 'time'),
            'Day' => $this->compileDatePartWhere($where, $variables, 'day'),
            'Month' => $this->compileDatePartWhere($where, $variables, 'month'),
            'Year' => $this->compileDatePartWhere($where, $variables, 'year'),
            'VectorSimilar' => throw new RuntimeException('whereVectorSimilarTo() cannot be nested inside MATCH WHERE; it replaces the scan with a vector index query.'),
            default => throw new RuntimeException("Unsupported where type for Neo4j Query Builder: {$type}"),
        };
    }

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string, string>  $variables
     */
    private function compileBasicWhere(array $where, array $variables): BooleanType
    {
        $column = $this->compileColumn((string) $where['column'], $variables);
        $operator = $this->normalizeOperator((string) $where['operator']);
        $param = $this->nextParameter();

        return match ($operator) {
            '=' => $column->equals($param),
            '<>' => $column->notEquals($param),
            '<' => $column->lt($param),
            '<=' => $column->lte($param),
            '>' => $column->gt($param),
            '>=' => $column->gte($param),
            'CONTAINS' => $column->contains($param),
            'STARTS WITH' => $column->startsWith($param),
            'ENDS WITH' => $column->endsWith($param),
            '=~' => $column->regex($param),
        };
    }

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string, string>  $variables
     */
    private function compileColumnWhere(array $where, array $variables): BooleanType
    {
        $first = $this->compileColumn((string) $where['first'], $variables)->toQuery();
        $second = $this->compileColumn((string) $where['second'], $variables)->toQuery();
        $operator = $this->normalizeComparisonOperator((string) $where['operator']);

        return Query::rawExpression("{$first} {$operator} {$second}");
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function compileRawWhere(array $where): BooleanType
    {
        $sql = (string) $where['sql'];
        $sql = preg_replace_callback('/\?/', function (): string {
            return '$'.$this->nextParameterName();
        }, $sql) ?? $sql;

        return Query::rawExpression($sql);
    }

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string, string>  $variables
     */
    private function compileDateWhere(array $where, array $variables, string $function): BooleanType
    {
        $column = $this->compileColumn((string) $where['column'], $variables)->toQuery();
        $operator = $this->normalizeComparisonOperator((string) $where['operator']);
        $parameter = '$'.$this->nextParameterName();
        $temporal = $this->temporalExpression($column);

        if ($function === 'date') {
            return Query::rawExpression("date({$temporal}) {$operator} date({$parameter})");
        }

        return Query::rawExpression("time({$temporal}) {$operator} time({$parameter})");
    }

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string, string>  $variables
     */
    private function compileDatePartWhere(array $where, array $variables, string $part): BooleanType
    {
        $column = $this->compileColumn((string) $where['column'], $variables)->toQuery();
        $operator = $this->normalizeComparisonOperator((string) $where['operator']);
        $parameter = '$'.$this->nextParameterName();
        $temporal = $this->temporalExpression($column);

        return Query::rawExpression("{$temporal}.{$part} {$operator} toInteger({$parameter})");
    }

    private function temporalExpression(string $column): string
    {
        // Accept Neo4j temporal values or Laravel-style "Y-m-d H:i:s" strings.
        return "datetime(replace(toString({$column}), ' ', 'T'))";
    }

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string, string>  $variables
     */
    private function compileInWhere(array $where, array $variables, bool $negate): BooleanType
    {
        $column = $this->compileColumn((string) $where['column'], $variables);
        $values = $where['values'] ?? [];

        if (count($values) === 1 && $this->isExpression($values[0])) {
            throw new RuntimeException(
                'Compiled whereIn subquery expressions are not supported on Neo4j Query Builder; '
                .'use the connection query builder so subqueries stay as builders (InSub).'
            );
        }

        $params = [];
        foreach ($values as $ignored) {
            $params[] = $this->nextParameter();
        }

        if ($params === []) {
            return Query::rawExpression($negate ? 'true' : 'false');
        }

        $clause = new In($column, Query::list($params));

        return $negate ? $clause->not() : $clause;
    }

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string, string>  $variables
     */
    private function compileInSubWhere(array $where, array $variables, bool $negate): BooleanType
    {
        $sub = $where['query'] ?? null;
        if (! $sub instanceof Builder) {
            throw new RuntimeException('Invalid whereIn subquery for Neo4j Query Builder.');
        }

        $column = $this->compileColumn((string) $where['column'], $variables)->toQuery();
        $subquery = $this->compileCollectSubquery($sub, $variables);
        $clause = $column.' IN COLLECT { '.$subquery.' }';

        return Query::rawExpression($negate ? 'NOT ('.$clause.')' : $clause);
    }

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string, string>  $variables
     */
    private function compileExistsWhere(array $where, array $variables, bool $negate): BooleanType
    {
        $sub = $where['query'] ?? null;
        if (! $sub instanceof Builder) {
            throw new RuntimeException('Invalid whereExists subquery for Neo4j Query Builder.');
        }

        $body = $this->compileExistsSubqueryBody($sub, $variables);
        $clause = ($negate ? 'NOT EXISTS' : 'EXISTS').' { '.$body.' }';

        return Query::rawExpression($clause);
    }

    /**
     * @param  array<string, string>  $outerVariables
     */
    private function compileCollectSubquery(Builder $query, array $outerVariables): string
    {
        [$match, $subVariables] = $this->compileSubqueryMatch($query, $outerVariables);
        $cypher = $match;
        $where = $this->compileWhereExpression($query->wheres ?? [], $subVariables);

        if ($where !== null) {
            $cypher .= ' '.Query::new()->where($where)->build();
        }

        $columns = $query->columns ?? null;
        if (! is_array($columns) || count($columns) !== 1) {
            throw new RuntimeException('whereIn subquery must select exactly one column on Neo4j Query Builder.');
        }

        $column = $columns[0];
        $return = $this->isExpression($column)
            ? (string) $this->getValue($column)
            : $this->compileColumn((string) $column, $subVariables)->toQuery();

        return $cypher.' RETURN '.$return;
    }

    /**
     * @param  array<string, string>  $outerVariables
     */
    private function compileExistsSubqueryBody(Builder $query, array $outerVariables): string
    {
        [$match, $subVariables] = $this->compileSubqueryMatch($query, $outerVariables);
        $where = $this->compileWhereExpression($query->wheres ?? [], $subVariables);

        if ($where === null) {
            return $match;
        }

        return $match.' '.Query::new()->where($where)->build();
    }

    /**
     * @param  array<string, string>  $outerVariables
     * @return array{0: string, 1: array<string, string>}
     */
    private function compileSubqueryMatch(Builder $query, array $outerVariables): array
    {
        $from = $this->parseTableName($query->from);
        $variable = $from['alias'] ?? $from['name'];
        $this->assertIdentifier($variable);

        // Outer queries bind the primary label to `n`; avoid shadowing it or any
        // already-bound join/subquery variable (sq0, sq1, …).
        if ($variable === 'n' || in_array($variable, $outerVariables, true)) {
            $variable = $this->allocateSubqueryVariable($outerVariables);
        }

        if (! empty($query->joins)) {
            throw new RuntimeException('Joins inside subqueries are not supported on Neo4j Query Builder yet.');
        }

        $subVariables = $outerVariables;
        $subVariables[''] = $variable;
        $subVariables[$from['name']] = $variable;

        if ($from['alias'] !== null) {
            $subVariables[$from['alias']] = $variable;
        }

        return ['MATCH ('.$variable.':'.$from['name'].')', $subVariables];
    }

    /**
     * @param  array<string, string>  $outerVariables
     */
    private function allocateSubqueryVariable(array $outerVariables): string
    {
        do {
            $candidate = 'sq'.$this->subqueryIndex;
            $this->subqueryIndex++;
        } while ($candidate === 'n' || in_array($candidate, $outerVariables, true));

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string, string>  $variables
     */
    private function compileBetweenWhere(array $where, array $variables): BooleanType
    {
        $column = $this->compileColumn((string) $where['column'], $variables);
        $low = $this->nextParameter();
        $high = $this->nextParameter();

        $clause = $column->gte($low)->and($column->lte($high));

        return ! empty($where['not']) ? $clause->not() : $clause;
    }

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string, string>  $variables
     */
    private function compileNestedWhere(array $where, array $variables): BooleanType
    {
        $nested = $where['query'] ?? null;
        if (! $nested instanceof Builder) {
            throw new RuntimeException('Invalid nested where clause for Neo4j Query Builder.');
        }

        $expression = $this->compileWhereExpression($nested->wheres ?? [], $variables);

        if ($expression === null) {
            throw new RuntimeException('Nested where clause for Neo4j Query Builder cannot be empty.');
        }

        return $expression;
    }

    /**
     * @param  array<int, array<string, mixed>>  $wheres
     */
    private function hasVectorSimilarity(Builder $query): bool
    {
        foreach ($query->wheres ?? [] as $where) {
            if (($where['type'] ?? null) === 'VectorSimilar') {
                return true;
            }
        }

        return false;
    }

    private function compileVectorSelect(Builder $query): string
    {
        $variables = $this->compileVariableMap($query);
        $vectorWhere = null;
        $vectorParameter = null;
        $minSimilarityParameter = null;
        $extraFilters = [];

        foreach ($query->wheres ?? [] as $where) {
            if (($where['type'] ?? null) === 'VectorSimilar') {
                if ($vectorWhere !== null) {
                    throw new RuntimeException('Only one whereVectorSimilarTo() clause is supported per query.');
                }

                $vectorWhere = $where;
                $vectorParameter = $this->nextParameterName();
                $minSimilarityParameter = $this->nextParameterName();

                continue;
            }

            $extraFilters[] = $this->compileWhereClause($where, $variables)->toQuery();
        }

        if ($vectorWhere === null || $vectorParameter === null || $minSimilarityParameter === null) {
            throw new RuntimeException('Vector similarity query is missing a whereVectorSimilarTo() clause.');
        }

        $column = $this->propertyName((string) $vectorWhere['column']);
        $index = $this->vectorIndexName($query, $column);
        $limit = max(1, (int) ($query->limit ?? 10));
        $offset = (int) ($query->offset ?? 0);
        $k = $limit + $offset;

        $filters = ["score >= \${$minSimilarityParameter}"];
        foreach ($extraFilters as $filter) {
            $filters[] = $filter;
        }

        $cypher = sprintf(
            'CALL db.index.vector.queryNodes(\'%s\', %d, $%s) YIELD node AS n, score WHERE %s RETURN %s',
            $index,
            $k,
            $vectorParameter,
            implode(' AND ', $filters),
            $this->compileVectorReturn($query)
        );

        if (! empty($vectorWhere['order'])) {
            $cypher .= ' ORDER BY score DESC';
        } else {
            $orders = $this->compileOrders($query, $query->orders ?? []);
            if ($orders !== '') {
                $cypher .= ' '.$orders;
            }
        }

        if ($offset > 0) {
            $cypher .= ' SKIP '.$offset;
        }

        return $cypher.' LIMIT '.$limit;
    }

    private function compileVectorReturn(Builder $query): string
    {
        $columns = $query->columns ?? null;
        $distinct = $query->distinct ? 'DISTINCT ' : '';

        if ($columns === null || $columns === [] || $columns === ['*']) {
            return $distinct.'n, score';
        }

        $parts = [];
        foreach ($columns as $column) {
            if (! is_string($column) || $column === '*') {
                return $distinct.'n, score';
            }
            $parts[] = $this->compileColumn($column)->toQuery();
        }

        $parts[] = 'score';

        return $distinct.implode(', ', $parts);
    }

    private function vectorIndexName(Builder $query, string $column): string
    {
        $explicit = $query instanceof Neo4jQueryBuilder ? $query->vectorIndex : null;
        if (is_string($explicit) && $explicit !== '') {
            $this->assertIdentifier($explicit);

            return $explicit;
        }

        $hint = $query->indexHint ?? null;
        if (is_object($hint) && isset($hint->index) && is_string($hint->index) && $hint->index !== '') {
            $this->assertIdentifier($hint->index);

            return $hint->index;
        }

        $from = strtolower(str_replace(':', '_', (string) $query->from));
        $name = $from.'_'.$column;
        $this->assertIdentifier($name);

        return $name;
    }

    private function nextParameter(): Parameter
    {
        return Query::parameter($this->nextParameterName());
    }

    private function normalizeOperator(string $operator): string
    {
        $normalized = strtoupper(trim($operator));

        if ($normalized === '!=') {
            $normalized = '<>';
        }

        $allowed = [
            '=', '<>', '<', '<=', '>', '>=',
            'CONTAINS', 'STARTS WITH', 'ENDS WITH', '=~',
        ];

        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported operator for Neo4j Query Builder: {$operator}");
        }

        return $normalized;
    }

    private function normalizeComparisonOperator(string $operator): string
    {
        $normalized = trim($operator);

        if ($normalized === '!=') {
            $normalized = '<>';
        }

        $allowed = ['=', '<>', '<', '<=', '>', '>='];

        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported comparison operator for Neo4j Query Builder: {$operator}");
        }

        return $normalized;
    }

    private function compileNode(mixed $from): Node
    {
        $table = $this->parseTableName($from);

        return Query::node()
            ->withLabels(explode(':', $table['name']))
            ->withVariable('n');
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function compileColumn(mixed $column, array $variables = []): Property
    {
        if (! is_string($column) || $column === '') {
            throw new InvalidArgumentException('Invalid column reference for Neo4j Query Builder.');
        }

        if (str_contains($column, '.')) {
            [$alias, $property] = explode('.', $column, 2);
            $this->assertIdentifier($alias);
            $this->assertIdentifier($property);

            // Eloquent qualifies keys with the model table/label (for example,
            // User.id). The primary from() label is bound to "n"; joined labels
            // keep their own Cypher variable from the variable map.
            $variable = $variables[$alias] ?? 'n';

            return Query::variable($variable)->property($property);
        }

        $this->assertIdentifier($column);

        return Query::variable($variables[''] ?? 'n')->property($column);
    }

    private function assertIdentifier(string $identifier): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Invalid Neo4j identifier: {$identifier}");
        }
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function getOperators(): array
    {
        return [
            '=', '<', '>', '<=', '>=', '<>', '!=',
            'contains', 'starts with', 'ends with', '=~',
        ];
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return string
     */
    #[\Override]
    public function compileInsert(Builder $query, array $values)
    {
        $this->parameterIndex = 0;
        $label = $this->compileLabel($query->from);
        $nodes = [];

        foreach (array_values($values) as $index => $record) {
            $nodes[] = sprintf(
                '(n%d:%s %s)',
                $index,
                $label,
                $this->compilePropertyMap(array_keys($record))
            );
        }

        return 'CREATE '.implode(', ', $nodes);
    }

    /**
     * Compile MATCH + CREATE for a directed relationship between two existing nodes.
     *
     * @param  array{
     *     type: string,
     *     related: string,
     *     relationship: string,
     *     relatedAlias: string,
     *     direction: 'out'|'in',
     *     fromColumns: list<string>,
     *     toColumns: list<string>,
     *     propertyColumns: list<string>
     * }  $relationship
     */
    public function compileInsertRelationship(Builder $query, array $relationship): string
    {
        $this->parameterIndex = 0;

        $from = $this->parseTableName($query->from);
        $this->assertIdentifier($relationship['type']);
        $this->assertIdentifier($relationship['relationship']);
        $this->assertIdentifier($relationship['relatedAlias']);
        $this->assertLabel($relationship['related']);

        $fromNode = "(n:{$from['name']} ".$this->compilePropertyMap($relationship['fromColumns']).')';
        $relatedNode = "({$relationship['relatedAlias']}:{$relationship['related']} "
            .$this->compilePropertyMap($relationship['toColumns']).')';
        $relProps = $relationship['propertyColumns'] === []
            ? ''
            : ' '.$this->compilePropertyMap($relationship['propertyColumns']);
        $rel = "[{$relationship['relationship']}:{$relationship['type']}{$relProps}]";

        $create = match ($relationship['direction']) {
            'out' => "(n)-{$rel}->({$relationship['relatedAlias']})",
            'in' => "(n)<-{$rel}-({$relationship['relatedAlias']})",
            default => throw new InvalidArgumentException(
                'insertRelationship() requires a directed relationship type.'
            ),
        };

        return "MATCH {$fromNode}, {$relatedNode} CREATE {$create}";
    }

    /**
     * @param  array<string, mixed>  $values
     * @return string
     */
    #[\Override]
    public function compileUpdate(Builder $query, array $values)
    {
        $this->parameterIndex = 0;
        $this->subqueryIndex = 0;

        if (! empty($query->joins)) {
            throw new RuntimeException('Updates with joins are not supported on Neo4j Query Builder.');
        }

        if ($this->graphRelationships($query) !== []) {
            throw new RuntimeException('Updates with matchRelationship() are not supported on Neo4j Query Builder.');
        }

        $variables = $this->compileVariableMap($query);
        $node = $this->compileNode($query->from);
        $assignments = [];

        foreach ($values as $column => $value) {
            $property = $this->compileColumn((string) $column, $variables)->toQuery();

            if ($this->isExpression($value)) {
                $assignments[] = $property.' = '.$this->getValue($value);
            } else {
                $assignments[] = $property.' = $'.$this->nextParameterName();
            }
        }

        $cypher = Query::new()->match($node);
        $where = $this->compileWhereExpression($query->wheres ?? [], $variables);
        $prefix = $cypher->build();

        if ($where !== null) {
            $prefix .= ' '.Query::new()->where($where)->build();
        }

        return $prefix.' SET '.implode(', ', $assignments);
    }

    /**
     * @return string
     */
    #[\Override]
    public function compileDelete(Builder $query)
    {
        $this->parameterIndex = 0;
        $this->subqueryIndex = 0;

        if (! empty($query->joins)) {
            throw new RuntimeException('Deletes with joins are not supported on Neo4j Query Builder.');
        }

        if ($this->graphRelationships($query) !== []) {
            throw new RuntimeException('Deletes with matchRelationship() are not supported on Neo4j Query Builder.');
        }

        $variables = $this->compileVariableMap($query);
        $node = $this->compileNode($query->from);
        $cypher = Query::new()->match($node);
        $where = $this->compileWhereExpression($query->wheres ?? [], $variables);
        $prefix = $cypher->build();

        if ($where !== null) {
            $prefix .= ' '.Query::new()->where($where)->build();
        }

        return $prefix.' DETACH DELETE n';
    }

    /**
     * @param  list<string>  $columns
     */
    private function compilePropertyMap(array $columns): string
    {
        $properties = [];

        foreach ($columns as $column) {
            $properties[] = $this->propertyName($column).': $'.$this->nextParameterName();
        }

        return '{'.implode(', ', $properties).'}';
    }

    private function compileLabel(mixed $from): string
    {
        return $this->parseTableName($from)['name'];
    }

    private function nextParameterName(): string
    {
        return 'p'.$this->parameterIndex++;
    }

    private function propertyName(string $column): string
    {
        if (str_contains($column, '.')) {
            [, $property] = explode('.', $column, 2);
            $this->assertIdentifier($property);

            return $property;
        }

        $this->assertIdentifier($column);

        return $column;
    }
}
