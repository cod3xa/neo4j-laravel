# Neo4j Laravel

### Use Neo4j as a first-class Laravel database connection.

Run Cypher through Laravel's `DB` facade, query builder, and Eloquent-style models — with transactions, parameterized queries, graph relationships, and optional Debugbar visibility — without hand-wiring the Neo4j PHP client yourself.

[![Latest Version](https://img.shields.io/packagist/v/neo4j-php/neo4j-laravel?style=for-the-badge&logo=packagist&logoColor=white&color=018bff)](https://packagist.org/packages/neo4j-php/neo4j-laravel)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-ff2d20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com/)
[![License](https://img.shields.io/badge/License-MIT-2ea44f?style=for-the-badge)](https://opensource.org/licenses/MIT)

[![Unit Tests](https://img.shields.io/github/actions/workflow/status/neo4j-php/neo4j-laravel/unit-tests.yml?branch=main&label=unit%20tests&style=flat-square&logo=github)](https://github.com/neo4j-php/neo4j-laravel/actions/workflows/unit-tests.yml)
[![Integration Tests](https://img.shields.io/github/actions/workflow/status/neo4j-php/neo4j-laravel/integration-tests.yml?branch=main&label=integration%20tests&style=flat-square&logo=github)](https://github.com/neo4j-php/neo4j-laravel/actions/workflows/integration-tests.yml)

Jump to the **[5-minute Quick Start](#5-minute-quick-start)**, skim **[Features](#features)** and **[Installation](#installation)**, or dig into **[Usage](#usage)**, **[Logging and debugging](#logging-and-debugging)**, and **[Troubleshooting](#troubleshooting)**.

---

## Why neo4j-laravel?

You can always talk to Neo4j with [`laudis/neo4j-php-client`](https://github.com/neo4j-php/neo4j-php-client) alone. This package plugs that client into Laravel's database layer so you get:

- A `neo4j` driver registered with Laravel's connection manager
- Familiar `DB::connection('neo4j')` and query-builder APIs that compile to Cypher
- Eloquent-style models backed by Neo4j nodes (`HasNeo4jConnection` / `Neo4jModel`)
- First-class graph relationships on the query builder and on models
- Container bindings for `ClientInterface`, `DriverInterface`, and `SessionInterface`
- Optional Cypher visibility in Laravel Debugbar's shared Queries tab
- End-of-request query log flushing through Laravel's logger

> [!WARNING]
> **Default connection.** The package currently expects Neo4j to be your `database.default` connection (required when resolving the Neo4j client from the container). Laravel also uses the default connection for sessions, auth, and other core features.
>
> Practical setup:
>
> 1. Set `DB_CONNECTION=neo4j` and configure the `neo4j` connection as shown below.
> 2. Set `SESSION_DRIVER=file` (or another non-database driver) in `.env`.
> 3. Prefer storing auth and other relational app data outside Neo4j unless your models intentionally use the Neo4j Eloquent integration.
>
> Using Neo4j only as a secondary connection while keeping MySQL/SQLite/PostgreSQL as default is **not supported yet** by the service provider's client bootstrap.

---

## Features

### Querying

| Feature | What it gives you |
| --- | --- |
| Raw Cypher | `select()`, `statement()`, `insert()`, `update()`, `delete()` with named parameters |
| Query builder | `DB::connection('neo4j')->table('Person')->where(...)->get()` compiles to Cypher |
| Bulk upsert | `table(...)->upsert(...)` as a single `UNWIND` + `MERGE` statement |
| Vector search | `createVectorIndex()`, `whereVectorSimilarTo()`, optional `useVectorIndex()` |

### Graph relationships

| Feature | What it gives you |
| --- | --- |
| Pattern matching | `matchRelationship()` on the query builder for Cypher relationship patterns |
| Single-edge create | `insertRelationship()` between existing nodes (`CREATE`, not `MERGE`) |
| Bulk edge writes | `insertRelationships()` / `upsertRelationships()` / `syncRelationships()` / `deleteRelationships()` in one statement |

### Laravel integration

| Feature | What it gives you |
| --- | --- |
| Service container | Auto-discovered `Neo4jServiceProvider`; inject `ClientInterface` / `SessionInterface` |
| Eloquent models | `HasNeo4jConnection` or `Neo4jModel` — UUID keys, SoftDeletes, pagination, FK-style relations |
| Graph relations on models | Eloquent `matchRelationship()` with `attach` / `sync` / `detach` / `create` / eager load |
| Transactions | Laravel `beginTransaction()` / `commit()` / `rollBack()`, plus client `writeTransaction()` helpers |

### Developer experience

| Feature | What it gives you |
| --- | --- |
| Query logging | In-memory query log with timing; flush to Laravel logs after each request/command |
| Debugbar (optional) | Cypher appears in the shared **Queries** tab when barryvdh/fruitcake Debugbar is installed |
| Connection options | SSL modes, pool size, connection timeout, auth schemes (`basic`, `kerberos`, `oidc`, `none`) |

---

## Requirements

| Requirement | Supported |
| --- | --- |
| PHP | 8.2+ |
| Laravel (`illuminate/database`, `illuminate/support`) | 11, 12, or 13 |
| Neo4j | Reachable Bolt / Neo4j URI (CI uses Neo4j 5.22) |
| Core dependency | [`laudis/neo4j-php-client`](https://github.com/neo4j-php/neo4j-php-client) `^3.2` |

Optional:

| Package | Purpose |
| --- | --- |
| [`barryvdh/laravel-debugbar`](https://github.com/barryvdh/laravel-debugbar) or [`fruitcake/laravel-debugbar`](https://github.com/fruitcake/laravel-debugbar) (v4+) | Show Cypher in Debugbar's Queries tab |

> CI currently runs unit and integration tests on **PHP 8.2–8.4 with Laravel 11**. Composer allows Laravel 12 and 13 via Illuminate constraints.

---

## Installation

### 1. Install the package

```bash
composer require neo4j-php/neo4j-laravel
```

Laravel package auto-discovery registers `Neo4j\Neo4jLaravel\Neo4jServiceProvider`. No manual provider entry is required.

### 2. Add environment variables

```env
DB_CONNECTION=neo4j
SESSION_DRIVER=file

NEO4J_URL=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-password
NEO4J_DATABASE=neo4j
```

Use any supported URI scheme your Neo4j deployment expects (`bolt://`, `neo4j://`, `neo4j+s://`, and so on).

### 3. Register the connection in `config/database.php`

```php
'connections' => [
    // ...

    'neo4j' => [
        'driver' => 'neo4j',
        'url' => env('NEO4J_URL', 'bolt://localhost:7687'),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', ''),
        'database' => env('NEO4J_DATABASE', 'neo4j'),
        'auth_scheme' => env('NEO4J_AUTH_SCHEME', 'basic'),
        'ssl' => [
            'mode' => env('NEO4J_SSL_MODE', 'from_url'),
            'verify_peer' => env('NEO4J_SSL_VERIFY_PEER', true),
        ],
        'connection' => [
            'timeout' => env('NEO4J_CONNECTION_TIMEOUT', 30),
            'max_pool_size' => env('NEO4J_MAX_POOL_SIZE', 100),
        ],
    ],
],
```

There is no `vendor:publish` step for the connection — add the array above yourself. Package defaults for query-log flushing live in `config/neo4j-laravel.php` and are merged automatically.

### 4. Verify connectivity

```php
use Laudis\Neo4j\Contracts\ClientInterface;

app(ClientInterface::class)->verifyConnectivity(); // true when Bolt is reachable
```

---

## 5-minute Quick Start

With Neo4j running and the connection configured:

```php
use Illuminate\Support\Facades\DB;

$connection = DB::connection('neo4j');

// Create a node
$connection->statement(<<<'CYPHER'
    CREATE (m:Movie {
        title: $title,
        released: $released,
        created_at: datetime()
    })
    CYPHER, [
    'title' => 'The Matrix',
    'released' => 1999,
]);

// Read it back (parameterized)
$movies = $connection->select(<<<'CYPHER'
    MATCH (m:Movie {title: $title})
    RETURN m.title AS title, m.released AS released
    CYPHER, ['title' => 'The Matrix']);

// $movies is an array of stdClass rows, e.g. title = "The Matrix"
```

Cleanup when you are done experimenting:

```php
DB::connection('neo4j')->statement(
    'MATCH (m:Movie {title: $title}) DETACH DELETE m',
    ['title' => 'The Matrix']
);
```

---

## Usage

### Executing Cypher queries

```php
use Illuminate\Support\Facades\DB;

$db = DB::connection('neo4j');

$rows = $db->select('MATCH (n:Person) RETURN n.name AS name LIMIT $limit', [
    'limit' => 10,
]);

$db->statement('CREATE (n:Person {name: $name})', ['name' => 'Trinity']);
```

`select()` returns an array of `stdClass` row objects. Prefer named `$parameters` over string concatenation.

### Query builder

```php
use Illuminate\Support\Facades\DB;

$people = DB::connection('neo4j')
    ->table('Person')
    ->where('name', 'Keanu Reeves')
    ->get();

// Bulk upsert — one UNWIND + MERGE statement
DB::connection('neo4j')->table('Person')->upsert(
    [
        ['email' => 'neo@example.com', 'name' => 'Neo'],
        ['email' => 'trinity@example.com', 'name' => 'Trinity'],
    ],
    ['email'],
    ['name']
);
```

### Relationship patterns

Direction markers on the type: `Type>` / `>Type` (outgoing), `<Type` / `Type<` (incoming), bare `Type` (undirected).

```php
use Illuminate\Support\Facades\DB;

$rows = DB::connection('neo4j')
    ->table('Person')
    ->matchRelationship('ACTED_IN>', 'Movie', 'role', 'film')
    ->where('role.roles', 'Neo')
    ->where('film.title', 'The Matrix')
    ->select(['n', 'role', 'film'])
    ->get();
// MATCH (n:Person)-[role:ACTED_IN]->(film:Movie) WHERE ... RETURN n, role, film
```

Create a directed edge between existing nodes (`CREATE` — repeats create duplicates):

```php
DB::connection('neo4j')
    ->table('Person')
    ->insertRelationship(
        'ACTED_IN>',
        'Movie',
        ['id' => $personId],
        ['id' => $movieId],
        ['roles' => ['Neo']],
    );
```

Bulk relationship writes (one `UNWIND` statement per call; missing nodes are skipped):

```php
$people = DB::connection('neo4j')->table('Person');
$rows = [
    ['from' => ['id' => $keanu], 'to' => ['id' => $matrix], 'properties' => ['roles' => ['Neo']]],
    ['from' => ['id' => $carrie], 'to' => ['id' => $matrix], 'properties' => ['roles' => ['Trinity']]],
];

$people->insertRelationships('ACTED_IN>', 'Movie', $rows); // CREATE
$people->upsertRelationships('ACTED_IN>', 'Movie', $rows); // MERGE + SET
$people->syncRelationships('ACTED_IN>', 'Movie', $rows);   // keep listed edges; nodes stay
$people->deleteRelationships('ACTED_IN>', 'Movie', [['from' => ['id' => $keanu]]]);
```

`syncRelationships()` replaces that type's edges for each listed *from* node without deleting nodes. Rows without a `to` map in `deleteRelationships()` remove all edges of that type from the node.

### Eloquent models

Add `HasNeo4jConnection` to a normal Eloquent model, or extend `Neo4jModel`. The class name becomes the default label (`User` → `:User`). New models get a UUID `id` automatically.

```php
namespace App\Models;

use Neo4j\Neo4jLaravel\Neo4jModel;

class Person extends Neo4jModel
{
    protected $fillable = ['name', 'email'];
}

$person = Person::create(['name' => 'Pratiksha', 'email' => 'pratiksha@example.com']);
$person = Person::where('name', 'Pratiksha')->first();

$person->id;          // UUID Eloquent key
$person->elementId(); // Neo4j element id when a full node was returned
```

Also supported on Neo4j models:

- Laravel `SoftDeletes` (`deleted_at`; hard delete uses `DETACH DELETE`)
- Pagination (`paginate`, `simplePaginate`, `cursorPaginate`)
- Subquery filters via `whereIn` / `whereExists` (and `not` variants) using Laravel's closure / builder APIs
- Stock FK-style relations against node properties: `hasOne`, `hasMany`, `belongsTo`, `belongsToMany` (pivot **label**), `hasOneThrough` / `hasManyThrough`, and polymorphic `morph*` / `morphToMany`

### Graph relationships on models

```php
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;

class Person extends Neo4jModel
{
    public function movies(): MatchRelationship
    {
        return $this->matchRelationship(Movie::class, 'ACTED_IN>');
    }
}

$person->movies;                               // lazy
$person->movies()->where('title', '…')->get(); // constrained
Person::with('movies')->get();                 // eager

$person->movies()->attach($movie->id, ['roles' => ['Neo']]);
$person->movies()->sync([$matrix->id]);
$person->movies()->detach($movie->id);
$person->movies()->create(['title' => 'The Matrix'], ['roles' => ['Neo']]);
```

`whereHas` / `withCount` on these graph relations are **not supported yet**.

### Transactions

Laravel-style:

```php
$db = DB::connection('neo4j');

$db->beginTransaction();
try {
    $db->statement('CREATE (n:Person {name: $name})', ['name' => 'Morpheus']);
    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    throw $e;
}
```

Client-style (also captured for logging / Debugbar when resolved from the container):

```php
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\TransactionInterface;

app(ClientInterface::class)->writeTransaction(function (TransactionInterface $tx) {
    return $tx->run(
        'CREATE (n:Person {name: $name}) RETURN n',
        ['name' => 'Switch']
    );
});
```

### Dependency injection

```php
use Laudis\Neo4j\Contracts\SessionInterface;

class MovieController
{
    public function index(SessionInterface $session)
    {
        $result = $session->run('MATCH (m:Movie) RETURN m.title AS title LIMIT 25');

        return response()->json($result->toArray());
    }
}
```

Container-resolved `ClientInterface`, `DriverInterface`, and `SessionInterface` are wrapped so Cypher is logged the same way as `DB::connection('neo4j')`. A client you construct yourself (outside the container) bypasses capture.

### Vector search

Requires a Neo4j vector index on the property you query:

```php
use Illuminate\Support\Facades\DB;
use App\Models\Document; // extends Neo4jModel

$connection = DB::connection('neo4j');
$connection->createVectorIndex('document_embedding', 'Document', 'embedding', 3);

Document::query()
    ->whereVectorSimilarTo('embedding', [1.0, 0.0, 0.0], minSimilarity: 0.9)
    ->limit(10)
    ->get();
```

---

## Configuration

### Connection keys

| Key | Env | Default | Purpose |
| --- | --- | --- | --- |
| `driver` | — | `neo4j` | Must be `neo4j` |
| `url` | `NEO4J_URL` | `bolt://localhost:7687` | Bolt / Neo4j URI (or set `host` + `port` instead) |
| `username` | `NEO4J_USERNAME` | `neo4j` | Auth username |
| `password` | `NEO4J_PASSWORD` | `''` | Auth password |
| `database` | `NEO4J_DATABASE` | `neo4j` | Neo4j database name |
| `auth_scheme` | `NEO4J_AUTH_SCHEME` | `basic` | `basic`, `kerberos`, `oidc`, or `none` |
| `ssl.mode` | `NEO4J_SSL_MODE` | `from_url` | `from_url`, `enable`, `enable_with_self_signed`, `disable` |
| `ssl.verify_peer` | `NEO4J_SSL_VERIFY_PEER` | `true` | TLS peer verification |
| `connection.timeout` | `NEO4J_CONNECTION_TIMEOUT` | `30` | Acquire-connection timeout (seconds) |
| `connection.max_pool_size` | `NEO4J_MAX_POOL_SIZE` | `100` | Driver pool size |

For `kerberos`, provide `ticket` on the connection array. For `oidc`, provide `auth_token` or `token`.

> The service provider maps `url` / `host`+`port`, auth, SSL, and `connection.*` into the Neo4j client. A top-level `transaction.timeout` key is **not** currently forwarded from `config/database.php` into the client factory.

### Package config (`neo4j-laravel`)

Merged from the package; override with env:

| Key | Env | Default | Purpose |
| --- | --- | --- | --- |
| `query_log_channel` | `NEO4J_QUERY_CHANNEL` | `''` | Named Laravel log channel for the end-of-request Cypher flush |

---

## Logging and debugging

### Query log flush

Each Neo4j connection keeps an in-memory query log (same entries as `getQueryLog()`, timed via the existing `logQuery()` path). After each HTTP request or console command, the buffer is flushed once as `debug` lines, then cleared.

| `NEO4J_QUERY_CHANNEL` | Behavior |
| --- | --- |
| unset / empty | `Log::debug('Neo4j query', $entry)` on the app default log stack |
| non-empty name that exists in `config/logging.php` | `Log::channel(...)->debug(...)` |
| non-empty but missing channel | Falls back to the default logger |

Example dedicated channel in `config/logging.php`:

```php
'neo4j_queries' => [
    'driver' => 'daily',
    'path' => storage_path('logs/neo4j-queries.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 14,
],
```

```env
NEO4J_QUERY_CHANNEL=neo4j_queries
```

### Laravel Debugbar (optional)

Install barryvdh or fruitcake Debugbar as a **dev** dependency. No extra Neo4j config is required.

When Debugbar's database (`queries`) collector is enabled, Cypher run through the Laravel connection API **or** container-resolved client/driver/session appears in the shared **Queries** tab via Laravel's `QueryExecuted` event. The connection name identifies Neo4j rows.

Failed Cypher stays listed: the statement keeps the original query and appends `/* Neo4j error: … */`. The in-memory query log keeps the clean Cypher plus `status` / `error_message`.

```php
DB::connection('neo4j')->select('MATCH (n) RETURN n LIMIT $limit', ['limit' => 10]);

// Also captured when SessionInterface comes from the container
$session->run('MATCH (n) RETURN n LIMIT $limit', ['limit' => 10]);
```

In SPA / API apps, ensure `storage/debugbar` is writable and inspect the API request dataset — not only the HTML shell.

---

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| `Cannot connect to any server` / connectivity failures | Neo4j is running; `NEO4J_URL` host/port match your deployment; firewall / Docker network; try `app(ClientInterface::class)->verifyConnectivity()` |
| `Default Neo4j connection '…' is not configured or invalid` | `database.default` must be a connection whose `driver` is `neo4j`, and that connection must exist under `database.connections` |
| `Missing required URL or host/port` | Set `url`, or both `host` and `port`, on the neo4j connection |
| Auth failures | `NEO4J_USERNAME` / `NEO4J_PASSWORD`; for Aura use the console URI (`neo4j+s://…`) and matching SSL settings |
| Session / auth errors with Neo4j as default | Set `SESSION_DRIVER=file` (or redis, etc.); keep Laravel auth on a relational store unless you intentionally model users in Neo4j |
| Cypher missing from Debugbar | Install barryvdh/fruitcake Debugbar; enable the queries collector; resolve client/session from the container (or use `DB::connection('neo4j')`); for AJAX, open the API request in Debugbar |
| `NEO4J_QUERY_CHANNEL` seems ignored | Channel name must exist under `logging.channels`; otherwise the package falls back to the default logger |
| Unsupported PHP / Laravel | PHP 8.2+ and Illuminate 11–13 are required by Composer |

Open an issue: [github.com/neo4j-php/neo4j-laravel/issues](https://github.com/neo4j-php/neo4j-laravel/issues).

---

## Testing

From a clone of this repository:

```bash
composer test:unit
composer test:integration   # needs a reachable Neo4j (see docker-compose.yml)
```

Or run the full suite:

```bash
composer test
```

---

## Contributing

Bug reports and pull requests are welcome on [GitHub Issues](https://github.com/neo4j-php/neo4j-laravel/issues) and [Pull Requests](https://github.com/neo4j-php/neo4j-laravel/pulls).

There is no separate `CONTRIBUTING.md` in the repository yet — follow existing code style (`composer cs`, `composer analyze`) and add tests for behavioral changes.

---

## License

This package is open-source software licensed under the [MIT license](https://opensource.org/licenses/MIT).

> Note: the repository does not currently include a root `LICENSE` file; the MIT license is declared in `composer.json` and on Packagist.
