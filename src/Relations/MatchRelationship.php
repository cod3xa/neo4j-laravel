<?php

namespace Neo4j\Neo4jLaravel\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\InteractsWithDictionary;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Neo4j\Neo4jLaravel\Neo4jQueryBuilder;
use RuntimeException;

/**
 * Eloquent relation backed by a first-class Neo4j graph relationship.
 *
 * Declared from the parent model's perspective (same direction markers as
 * Query Builder {@see Neo4jQueryBuilder::matchRelationship()}):
 *
 *   return $this->matchRelationship(Movie::class, 'ACTED_IN>');
 *
 * Under the hood the related-model query matches the inverted pattern so
 * Eloquent can hydrate related nodes as `n`.
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends \Illuminate\Database\Eloquent\Relations\Relation<TRelatedModel, TDeclaringModel, \Illuminate\Database\Eloquent\Collection<int, TRelatedModel>>
 */
class MatchRelationship extends Relation
{
    use InteractsWithDictionary;

    public const PARENT_KEY_ALIAS = 'neo4j_parent_key';

    protected const RELATIONSHIP_ALIAS = 'neo4j_rel';

    protected const PARENT_ALIAS = 'neo4j_parent';

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     */
    public function __construct(
        Builder $query,
        Model $parent,
        protected string $relationshipType,
        protected string $localKey,
        protected string $relatedKey,
    ) {
        parent::__construct($query, $parent);
    }

    public function addConstraints(): void
    {
        if (! static::$constraints) {
            return;
        }

        $this->applyGraphPattern();

        $this->query->where(
            self::PARENT_ALIAS.'.'.$this->localKey,
            '=',
            $this->parent->getAttribute($this->localKey)
        );
    }

    public function addEagerConstraints(array $models): void
    {
        $this->applyGraphPattern();

        $keys = $this->getKeys($models, $this->localKey);

        if ($keys === []) {
            $this->eagerKeysWereEmpty = true;

            return;
        }

        $this->query->whereIn(self::PARENT_ALIAS.'.'.$this->localKey, $keys);
        $this->query->select([
            'n',
            self::PARENT_ALIAS.'.'.$this->localKey.' as '.self::PARENT_KEY_ALIAS,
        ]);
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     * @return array<int, TDeclaringModel>
     */
    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     * @param  \Illuminate\Database\Eloquent\Collection<int, TRelatedModel>  $results
     * @return array<int, TDeclaringModel>
     */
    public function match(array $models, EloquentCollection $results, $relation)
    {
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = $this->getDictionaryKey($model->getAttribute($this->localKey));

            if (isset($dictionary[$key])) {
                $model->setRelation(
                    $relation,
                    $this->related->newCollection($dictionary[$key])
                );
            }
        }

        return $models;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, TRelatedModel>
     */
    public function getResults()
    {
        if (is_null($this->parent->getAttribute($this->localKey))) {
            return $this->related->newCollection();
        }

        return $this->query->get();
    }

    /**
     * Create a related model and a directed relationship from the parent.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $relationshipAttributes
     * @return TRelatedModel
     */
    public function create(array $attributes = [], array $relationshipAttributes = [])
    {
        $instance = $this->related->newInstance($attributes);
        $instance->save();

        $this->attach($instance->getAttribute($this->relatedKey), $relationshipAttributes);

        return $instance;
    }

    /**
     * Create a directed relationship from the parent to existing related ids.
     *
     * @param  mixed  $ids
     * @param  array<string, mixed>  $attributes
     */
    public function attach($ids, array $attributes = []): void
    {
        $this->parentQuery('attach')->insertRelationships(
            $this->relationshipType,
            $this->relatedLabel(),
            $this->relationshipRows('attach', $ids, $attributes),
        );
    }

    /**
     * Make the given related ids the only relationships of this type from the parent.
     *
     * Relationships to ids that are not listed are deleted, missing ones are
     * created and existing ones are kept (with $attributes set on them). No
     * node is deleted.
     *
     * @api
     *
     * @param  mixed  $ids
     * @param  array<string, mixed>  $attributes
     * @return int Relationships created and deleted plus properties set.
     */
    public function sync($ids, array $attributes = []): int
    {
        $rows = $this->relationshipRows('sync', $ids, $attributes);

        if ($rows === []) {
            return $this->detach();
        }

        return $this->parentQuery('sync')->syncRelationships(
            $this->relationshipType,
            $this->relatedLabel(),
            $rows,
        );
    }

    /**
     * Delete relationships from the parent to the given related ids (all when null).
     *
     * @param  mixed  $ids
     * @return int Relationships deleted.
     */
    public function detach($ids = null): int
    {
        $rows = $ids === null
            ? [['from' => [$this->localKey => $this->parentKey('detach')]]]
            : $this->relationshipRows('detach', $ids);

        return $this->parentQuery('detach')->deleteRelationships(
            $this->relationshipType,
            $this->relatedLabel(),
            $rows,
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<TRelatedModel>  $query
     * @param  \Illuminate\Database\Eloquent\Builder<TDeclaringModel>  $parentQuery
     * @param  array|mixed  $columns
     * @return \Illuminate\Database\Eloquent\Builder<TRelatedModel>
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        throw new RuntimeException(
            'whereHas() / has() / withCount() are not yet supported for Neo4j matchRelationship() relations.'
        );
    }

    public function getRelationshipType(): string
    {
        return $this->relationshipType;
    }

    public function getLocalKeyName(): string
    {
        return $this->localKey;
    }

    public function getRelatedKeyName(): string
    {
        return $this->relatedKey;
    }

    protected function parentQuery(string $method): Neo4jQueryBuilder
    {
        $base = $this->parent->newQuery()->getQuery();

        if (! $base instanceof Neo4jQueryBuilder) {
            throw new RuntimeException("matchRelationship() {$method} requires the Neo4j query builder.");
        }

        return $base;
    }

    protected function parentKey(string $method): mixed
    {
        $parentKey = $this->parent->getAttribute($this->localKey);

        if ($parentKey === null) {
            throw new RuntimeException("Cannot {$method} matchRelationship() edges without a parent key.");
        }

        return $parentKey;
    }

    /**
     * @param  mixed  $ids
     * @param  array<string, mixed>  $attributes
     * @return list<array{from: array<string, mixed>, to: array<string, mixed>, properties?: array<string, mixed>}>
     */
    protected function relationshipRows(string $method, $ids, array $attributes = []): array
    {
        $parentKey = $this->parentKey($method);
        $rows = [];

        foreach (Arr::wrap($ids) as $id) {
            if ($id === null || $id === '') {
                continue;
            }

            $row = ['from' => [$this->localKey => $parentKey], 'to' => [$this->relatedKey => $id]];

            if ($attributes !== []) {
                $row['properties'] = $attributes;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    protected function applyGraphPattern(): void
    {
        $base = $this->query->getQuery();

        if (! $base instanceof Neo4jQueryBuilder) {
            throw new RuntimeException(
                'Eloquent matchRelationship() requires models on the Neo4j connection / query builder.'
            );
        }

        if ($base->graphRelationships !== []) {
            return;
        }

        $base->matchRelationship(
            $this->invertRelationshipType($this->relationshipType),
            $this->parentLabel(),
            self::RELATIONSHIP_ALIAS,
            self::PARENT_ALIAS,
        );
    }

    /**
     * Parent declares direction from itself; the related query matches the inverse.
     */
    protected function invertRelationshipType(string $relationshipType): string
    {
        $normalized = preg_replace('/\s+/', '', trim($relationshipType)) ?? trim($relationshipType);
        $normalized = ltrim($normalized, ':');

        if ($normalized === '' || $normalized === '<' || $normalized === '>' || $normalized === '<>') {
            throw new InvalidArgumentException("Invalid Neo4j relationship type: {$relationshipType}");
        }

        if (str_starts_with($normalized, '<') && str_ends_with($normalized, '>')) {
            return $normalized;
        }

        if (str_starts_with($normalized, '<')) {
            return substr($normalized, 1).'>';
        }

        if (str_ends_with($normalized, '<')) {
            return substr($normalized, 0, -1).'>';
        }

        if (str_starts_with($normalized, '>')) {
            return '<'.substr($normalized, 1);
        }

        if (str_ends_with($normalized, '>')) {
            return '<'.substr($normalized, 0, -1);
        }

        return $normalized;
    }

    protected function parentLabel(): string
    {
        return method_exists($this->parent, 'getLabel')
            ? $this->parent->getLabel()
            : $this->parent->getTable();
    }

    protected function relatedLabel(): string
    {
        return method_exists($this->related, 'getLabel')
            ? $this->related->getLabel()
            : $this->related->getTable();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, TRelatedModel>  $results
     * @return array<string|int, list<TRelatedModel>>
     */
    protected function buildDictionary(EloquentCollection $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $key = $this->getDictionaryKey($result->getAttribute(self::PARENT_KEY_ALIAS));
            $result->offsetUnset(self::PARENT_KEY_ALIAS);
            $dictionary[$key][] = $result;
        }

        return $dictionary;
    }
}
