# Neo4j Laravel migrations — architecture

## Goal

Reuse Laravel's migration lifecycle (`Migrator`, Artisan commands, `Migration` classes, path discovery, batches) and only replace the pieces that assume a relational store.

## What Laravel already provides

| Component | Role | Reuse? |
| --- | --- | --- |
| `Illuminate\Database\Migrations\Migrator` | Run / rollback / status / batches | Yes — unchanged |
| Migration Artisan commands | `migrate`, `migrate:status`, `migrate:rollback`, … | Yes — unchanged |
| `MigrationRepositoryInterface` | Contract for migration history | Yes — implement for Neo4j |
| `DatabaseMigrationRepository` | SQL `migrations` table | No — uses `Schema::create` / query builder inserts |
| `Migration` base class | `up` / `down`, `$connection`, `$withinTransaction` | Yes |
| `make:migration` | Stub generation | Yes |

`DatabaseMigrationRepository` cannot back Neo4j: `createRepository()` builds a SQL table, and history CRUD goes through `table()->insert()` / `pluck()`.

Schema DDL wrapping uses `SchemaGrammar::supportsSchemaTransactions()` (default `false`). Neo4j keeps that default — schema commands are not wrapped in Bolt transactions by the Migrator.

## Integration shape

```text
php artisan migrate --database=neo4j
        │
        ▼
 Laravel Migrator  ─────────────────────────────────┐
        │                                           │
        │  repository->setSource('neo4j')           │  resolveConnection('neo4j')
        ▼                                           ▼
 ConnectionAwareMigrationRepository          Neo4jConnection
        │                                           │
        │  driver === neo4j                         │  getSchemaBuilder()
        ▼                                           ▼
 Neo4jMigrationRepository                    Neo4jSchemaBuilder
   label :_LaravelMigration                    node() / relationship()
   unique constraint on .migration             Neo4jSchemaGrammar → Cypher
```

### Service binding

`Neo4jServiceProvider` **extends** (does not replace) `migration.repository` with a `ConnectionAwareMigrationRepository` that:

1. Keeps Laravel's `DatabaseMigrationRepository` for SQL connections.
2. Delegates to `Neo4jMigrationRepository` when the active source connection's driver is `neo4j`.

Stock commands keep working for mixed apps; Neo4j history stays isolated per connection/database.

### Migration history graph

- Label: `:_LaravelMigration`
- Properties: `migration` (string), `batch` (int), `migrated_at` (datetime)
- Uniqueness: constraint `_laravel_migration_migration_unique` on `migration`
- `createRepository()` / `repositoryExists()` / `deleteRepository()` manage that constraint (+ node cleanup on delete)

### Graph schema API (migrations)

```php
Schema::connection('neo4j')->node('Person', function ($node) {
    $node->unique('email');
    $node->index('name');
});

Schema::connection('neo4j')->relationship('ACTED_IN', function ($relationship) {
    $relationship->index('role');
});
```

Defining a label does **not** create nodes. Only indexes / constraints are created. Rollback drops those schema objects by predictable names; it does not delete application data.

## Custom components (and why)

| Class | Why |
| --- | --- |
| `Neo4jMigrationRepository` | Only adapter needed for history on Neo4j |
| `ConnectionAwareMigrationRepository` | Preserve SQL migrations when the source is not Neo4j |
| `Neo4jSchemaBuilder` + node/relationship blueprints | Graph-native schema surface via `Schema` facade |
| Grammar compile helpers | Cypher for range indexes / uniqueness constraints |

No custom `Neo4jMigrator` and no replacement Artisan commands.
