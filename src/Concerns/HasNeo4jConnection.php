<?php

namespace Neo4j\Neo4jLaravel\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;

/**
 * Configure a standard Eloquent model for Neo4j node persistence.
 *
 * Models keep a UUID property as the Eloquent primary key (`id` by default).
 * When a full node is returned from Cypher, Neo4j's native element id is also
 * hydrated onto the model and available via {@see elementId()}.
 *
 * Laravel's SoftDeletes trait works with Neo4j models: soft delete sets
 * `deleted_at`, and force delete removes the node (DETACH DELETE).
 *
 * Many-to-many uses stock Eloquent BelongsToMany against a pivot label
 * (e.g. RoleUser). Joins compile to multi-node MATCH + equality WHERE.
 *
 * Graph relationships use {@see matchRelationship()} (Eloquent Relation) on top
 * of Query Builder {@see \Neo4j\Neo4jLaravel\Neo4jQueryBuilder::matchRelationship()}.
 */
trait HasNeo4jConnection
{
    public static function bootHasNeo4jConnection(): void
    {
        static::creating(function ($model): void {
            $key = $model->getKeyName();

            if ($key !== null && $model->getAttribute($key) === null) {
                $model->setAttribute($key, (string) Str::uuid());
            }
        });
    }

    public function initializeHasNeo4jConnection(): void
    {
        $this->connection ??= static::defaultNeo4jConnectionName();
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    /**
     * Prefer the app default when it is a Neo4j driver; otherwise `neo4j`.
     */
    protected static function defaultNeo4jConnectionName(): string
    {
        $default = (string) config('database.default', 'neo4j');
        $driver = config("database.connections.{$default}.driver");

        if ($driver === 'neo4j') {
            return $default;
        }

        if (is_array(config('database.connections.neo4j'))
            && config('database.connections.neo4j.driver') === 'neo4j') {
            return 'neo4j';
        }

        foreach (config('database.connections', []) as $name => $connection) {
            if (($connection['driver'] ?? null) === 'neo4j') {
                return (string) $name;
            }
        }

        return 'neo4j';
    }

    /**
     * Define a first-class Neo4j graph relationship (Eloquent Relation).
     *
     * Direction markers match the Query Builder API (`Type>`, `<Type`, bare type).
     *
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TRelatedModel>  $related
     * @return \Neo4j\Neo4jLaravel\Relations\MatchRelationship<TRelatedModel, $this>
     */
    public function matchRelationship(
        string $related,
        string $relationshipType,
        ?string $localKey = null,
        ?string $relatedKey = null,
    ): MatchRelationship {
        /** @var TRelatedModel $instance */
        $instance = $this->newRelatedInstance($related);

        return $this->newMatchRelationship(
            $instance->newQuery(),
            $this,
            $relationshipType,
            $localKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(),
        );
    }

    /**
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     * @return \Neo4j\Neo4jLaravel\Relations\MatchRelationship<TRelatedModel, TDeclaringModel>
     */
    protected function newMatchRelationship(
        Builder $query,
        Model $parent,
        string $relationshipType,
        string $localKey,
        string $relatedKey,
    ): MatchRelationship {
        return new MatchRelationship($query, $parent, $relationshipType, $localKey, $relatedKey);
    }

    /**
     * Neo4j element id for the matched node, when a full node was returned.
     *
     * Distinct from the Eloquent UUID primary key property (`id`).
     */
    public function elementId(): ?string
    {
        return isset($this->attributes['elementId'])
            ? (string) $this->attributes['elementId']
            : null;
    }

    /**
     * Exclude Neo4j metadata from CREATE payloads.
     *
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert()
    {
        $attributes = parent::getAttributesForInsert();
        unset($attributes['elementId']);

        return $attributes;
    }

    /**
     * Exclude Neo4j metadata from SET payloads.
     *
     * @return array<string, mixed>
     */
    protected function getDirtyForUpdate()
    {
        $dirty = parent::getDirtyForUpdate();
        unset($dirty['elementId']);

        return $dirty;
    }

    /**
     * Use a node label instead of Eloquent's plural snake-case table name.
     */
    public function getTable(): string
    {
        return $this->table ?? Str::studly(class_basename($this));
    }

    public function getLabel(): string
    {
        return $this->getTable();
    }

    public function setLabel(string $label): static
    {
        return $this->setTable($label);
    }
}
