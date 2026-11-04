# Neo4j Laravel migrations — architecture

## Goal

Reuse Laravel's migration lifecycle (`Migrator`, Artisan commands, `Migration` classes, path discovery, batches) and only replace the pieces that assume a relational store.

## Investigation: can existing query builder / models back migration history?

### 1. Query builder CRUD

The Neo4j query builder already supports the operations Laravel's history repository needs against a label treated as a "table":

| Repository need | Package support |
| --- | --- |
| Insert a history row | `table('_LaravelMigration')->insert([...])` → `CREATE (n:_LaravelMigration {…})` |
| List ran migrations | `orderBy` + `pluck('migration')` |
| Filter by batch / take last N | `where` + `orderBy` + `take` + `get` |
| Delete on rollback | `where('migration', …)->delete()` → `DETACH DELETE` |
| Next batch | `max('batch')` via existing aggregate compilation |
| Count / existence of rows | `count()` (already used in integration tests) |

So **history record CRUD should go through the query builder**, not hand-written Cypher for every method.

### 2. Neo4j models

A `Neo4jModel` with `$table = '_LaravelMigration'` could represent history nodes, but:

- Laravel's `Migrator` only talks to `MigrationRepositoryInterface`, not Eloquent.
- `HasNeo4jConnection` auto-assigns a UUID `id` on create; Laravel's `log($file, $batch)` only supplies `migration` + `batch`.
- Using a model inside the repository would not remove the need for a repository adapter.

**Conclusion:** prefer the query builder over a dedicated Eloquent model for migration history.

### 3. Can stock `DatabaseMigrationRepository` work unchanged?

Almost for CRUD — it already uses `$connection->table($this->table)` — but **not for repository lifecycle**:

```php
$schema->create($this->table, function ($table) {
    $table->increments('id');
    $table->string('migration');
    $table->integer('batch');
});
// + hasTable() / drop()
```

Those APIs assume SQL tables and Laravel's relational `Blueprint`. Emulating full `Schema::create` / column blueprints for Neo4j would be a large, fragile abstraction (and still would not match graph-native `node()` / `relationship()` migrations).

**Conclusion:** do **not** force `DatabaseMigrationRepository` onto Neo4j. Keep it for SQL connections.

### 4. Smallest correct integration

| Approach | Verdict |
| --- | --- |
| A. Stock `DatabaseMigrationRepository` + fake SQL schema | Rejected — Blueprint/`hasTable`/`drop` are relational |
| B. Thin `MigrationRepositoryInterface` adapter using query builder for CRUD; Cypher only for bootstrap constraint | **Chosen** |
| C. Full custom repository + custom Migrator + custom commands | Rejected — unnecessary |

Laravel's original `Migrator` and Artisan commands stay. The only Neo4j-specific migration-history code is a repository adapter (plus a connection-aware wrapper so SQL apps keep working).

### 5. Limitations

| Topic | Finding |
| --- | --- |
| Batches | Supported via integer `batch` property + `max('batch')` through the query builder |
| Transactions | Schema grammar defaults `supportsSchemaTransactions = false`; Migrator will not wrap Neo4j DDL in `$connection->transaction()`. History inserts are separate statements. Partial DDL failure can leave schema objects applied without a history row (same class of risk as non-transactional DDL elsewhere) |
| Multiple Neo4j connections | `setSource($name)` + connection resolver isolate history per connection/database |
| `migrated_at` | Not part of Laravel's stock repository insert; the Neo4j adapter adds it on `log()` |
| Label vs config table name | History uses fixed internal label `:_LaravelMigration` (not the SQL `migrations` table name) |

## What Laravel already provides

| Component | Role | Reuse? |
| --- | --- | --- |
| `Illuminate\Database\Migrations\Migrator` | Run / rollback / status / batches | Yes — unchanged |
| Migration Artisan commands | `migrate`, `migrate:status`, `migrate:rollback`, … | Yes — unchanged |
| `MigrationRepositoryInterface` | Contract for migration history | Yes — thin Neo4j adapter |
| `DatabaseMigrationRepository` | SQL `migrations` table | Yes — for non-Neo4j sources only |
| Neo4j query builder | History node CRUD | Yes — used inside the adapter |
| `Migration` base class | `up` / `down`, `$connection`, `$withinTransaction` | Yes |
| `make:migration` | Stub generation | Yes |

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
   CRUD via table('_LaravelMigration')         node() / relationship()
   bootstrap: uniqueness constraint            Neo4jSchemaGrammar → Cypher
```

### Service binding

`Neo4jServiceProvider` **extends** `migration.repository` with `ConnectionAwareMigrationRepository` that:

1. Keeps Laravel's `DatabaseMigrationRepository` for SQL connections.
2. Delegates to `Neo4jMigrationRepository` when the active source connection's driver is `neo4j`.

### Migration history graph

- Label: `:_LaravelMigration`
- Properties: `migration` (string), `batch` (int), `migrated_at` (datetime)
- Uniqueness: constraint `_laravel_migration_migration_unique` on `migration`
- `createRepository()` / `repositoryExists()` / `deleteRepository()` manage that constraint (+ node cleanup on delete via query builder)

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
| `Neo4jMigrationRepository` | Minimum `MigrationRepositoryInterface` adapter; CRUD via existing query builder |
| `ConnectionAwareMigrationRepository` | Preserve SQL migrations when the source is not Neo4j |
| `Neo4jSchemaBuilder` + node/relationship blueprints | Graph-native schema surface via `Schema` facade |
| Grammar compile helpers | Cypher for range indexes / uniqueness constraints |

No custom `Neo4jMigrator` and no replacement Artisan commands.
