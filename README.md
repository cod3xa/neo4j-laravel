# Neo4j Laravel

A Laravel package that provides integration with Neo4j graph database.

> [!WARNING]
> Database Configuration Recommendations:
>
> Laravel uses the default database connection for authentication, sessions, and other core features. While you can use Neo4j as your default database, we recommend:
>
> 1. Use a traditional database (SQLite, MySQL, PostgreSQL, etc.) as your default database connection
> 2. Use Neo4j as a secondary connection for your graph data
>
> If you choose to use Neo4j as your default database:
>
> - Set `SESSION_DRIVER=file` in your .env file
> - Store authentication data in Neo4j only if your application model uses the Neo4j Eloquent concern described below
> - Other features that depend on the default database connection may be affected
>
> These limitations will be addressed in future releases.

## Installation

```bash
composer require neo4j-php/neo4j-laravel
```

## Configuration

### Environment Variables

Add the following to your `.env` file:

```env
DB_CONNECTION=neo4j
NEO4J_URL=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your_password
NEO4J_DATABASE=neo4j
```

#### Query log flushing (`NEO4J_QUERY_CHANNEL`)

Each Neo4j connection keeps an in-memory query log (the same entries `getQueryLog()` returns; queries are still recorded by the existing `logQuery()` path with timing—nothing is double-logged at execution time). After each HTTP request finishes (or when a console command ends), that buffer is flushed once to Laravel’s logger as `debug` lines, then cleared.

| Variable | Behavior |
|----------|----------|
| `NEO4J_QUERY_CHANNEL` **unset or empty** | Each log line is written with `Log::debug(...)` (no `::channel()`), so messages use your app’s default log stack from `config('logging.default')` (usually `stack` → `single` / `laravel.log`). |
| `NEO4J_QUERY_CHANNEL=neo4j_queries` (non-empty) | If `config('logging.channels.neo4j_queries')` exists, lines are written with `Log::channel('neo4j_queries')->debug(...)`. Define that channel in `config/logging.php` (e.g. a `daily` file dedicated to Neo4j). If the name is set but **not** defined under `logging.channels`, the package falls back to the same behavior as an empty value (default logger). |

To tune or disable logging per environment, configure the channel itself in `config/logging.php` (e.g. `level`, a `null` driver, or omitting the channel from your production `LOG_STACK`).

Example: dedicated daily channel in `config/logging.php`:

```php
'neo4j_queries' => [
    'driver' => 'daily',
    'path' => storage_path('logs/neo4j-queries.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 14,
],
```

Then in `.env`:

```env
NEO4J_QUERY_CHANNEL=neo4j_queries
```

### Laravel Debugbar (optional)

Install [barryvdh/laravel-debugbar](https://github.com/barryvdh/laravel-debugbar) or [fruitcake/laravel-debugbar](https://github.com/fruitcake/laravel-debugbar) (v4+) as a **dev** dependency. No extra Neo4j config is required.

When Debugbar’s database (`queries`) collector is enabled, Cypher run through Laravel’s connection API appears in the shared **Queries** tab (via Laravel’s `QueryExecuted` event), alongside SQL from other connections. The connection name identifies Neo4j rows.

Failed Cypher is still listed there: the statement keeps the original query and appends a `/* Neo4j error: … */` comment with the exception message. The in-memory query log (`getQueryLog()` / `NEO4J_QUERY_CHANNEL` flush) keeps the clean Cypher plus separate `status` / `error_message` fields.

Use the connection API **or** the container-resolved Neo4j client / driver / session (they are wrapped so Cypher is captured the same way):

```php
DB::connection('neo4j')->select('MATCH (n) RETURN n LIMIT $limit', ['limit' => 10]);

// Also captured — SessionInterface / ClientInterface / DriverInterface from the container
$session->run('MATCH (n) RETURN n LIMIT $limit', ['limit' => 10]);
```

Calling methods on a raw client obtained outside this package (for example constructing `Client` yourself without going through the Laravel container) still bypasses capture.

In SPA / API apps, Debugbar often stores AJAX datasets under `storage/debugbar`. Ensure that directory is writable by PHP, then select the API request (for example `GET /api/...`) in Debugbar—not only the HTML shell that served the page.

### Database Configuration

Add the Neo4j connection configuration to your `config/database.php`:

```php
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
    'transaction' => [
        'timeout' => env('NEO4J_TRANSACTION_TIMEOUT', 30),
    ],
],
```

## Usage

### Using Laravel's DB Facade

```php
use Illuminate\Support\Facades\DB;

// Basic query
$result = DB::connection('neo4j')->select('MATCH (n) RETURN n');

// With parameters
$result = DB::connection('neo4j')->select(<<<'CYPHER'
    MATCH (m:Movie {title: $title})
    RETURN m
CYPHER, ['title' => 'The Matrix']);

// Transactions
DB::connection('neo4j')->beginTransaction();
try {
    // Your queries here
    DB::connection('neo4j')->commit();
} catch (\Exception $e) {
    DB::connection('neo4j')->rollBack();
    throw $e;
}
```

### Query Builder relationships

Use `matchRelationship()` on the Neo4j query builder for a first-class Cypher
relationship pattern. Additional calls add more edges from the same node:

```php
use Illuminate\Support\Facades\DB;

$rows = DB::connection('neo4j')
    ->table('Person')
    ->matchRelationship('ACTED_IN>', 'Movie', 'role', 'film')
    ->where('role.roles', 'Neo')
    ->where('film.title', 'The Matrix')
    ->get();
// MATCH (n:Person)-[role:ACTED_IN]->(film:Movie) WHERE ... RETURN n
```

Direction markers on the type: `Type>` / `>Type` (outgoing), `<Type` / `Type<`
(incoming), bare `Type` (undirected). An optional closure may only add WHERE
constraints on the related node (unqualified columns are scoped to that alias).

Default `RETURN` is `n` (Eloquent-safe). Select relationship / related variables
explicitly when you need graph rows:

```php
$rows = DB::connection('neo4j')
    ->table('Person')
    ->matchRelationship('ACTED_IN>', 'Movie', 'role', 'film')
    ->where('role.roles', 'Neo')
    ->select(['n', 'role', 'film'])
    ->get();
```

Create a directed relationship between existing nodes with `insertRelationship()`
(uses `CREATE`, not `MERGE` — duplicate calls create duplicate edges; builder
`where()` clauses are ignored, only the property maps are used):

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

To write many relationships at once, pass rows of `from` / `to` property maps
(plus optional `properties`). Each call runs one `UNWIND $rows` statement, no
matter how many rows; rows whose nodes do not exist are skipped:

```php
$rows = [
    ['from' => ['id' => $keanu], 'to' => ['id' => $matrix], 'properties' => ['roles' => ['Neo']]],
    ['from' => ['id' => $carrie], 'to' => ['id' => $matrix], 'properties' => ['roles' => ['Trinity']]],
];

$people = DB::connection('neo4j')->table('Person');

$people->insertRelationships('ACTED_IN>', 'Movie', $rows); // CREATE (duplicates on re-run)
$people->upsertRelationships('ACTED_IN>', 'Movie', $rows); // MERGE + SET properties
$people->syncRelationships('ACTED_IN>', 'Movie', $rows);   // MERGE listed, delete unlisted
$people->deleteRelationships('ACTED_IN>', 'Movie', [['from' => ['id' => $keanu]]]);
```

`syncRelationships()` replaces each listed from node's relationships of that
type without deleting any node, and keeps existing relationships (and their
other properties) that are still listed. From nodes that are not in the rows
are left alone. `deleteRelationships()` rows without a `to` map remove all of
that node's relationships of the type.

### Using Eloquent

Add `HasNeo4jConnection` to a standard Eloquent model. The model class name is
used as its default Neo4j label, so `User` maps to `:User`. New models receive a
UUID `id` automatically.

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Neo4j\Neo4jLaravel\Concerns\HasNeo4jConnection;

class User extends Model
{
    use HasNeo4jConnection;

    protected $fillable = ['name', 'email'];
}
```

The regular Eloquent read and CRUD APIs then use the Neo4j connection, Query
Builder, and Cypher grammar:

```php
$user = User::where('name', 'Pratiksha')->first();

$user = User::create([
    'name' => 'Pratiksha',
    'email' => 'pratiksha@example.com',
]);

$user->update(['name' => 'Pratiksha Zalte']);
$user->delete();
```

When a full node is returned, Neo4j's native element id is hydrated onto the
model (separate from the UUID primary key):

```php
$user = User::where('name', 'Pratiksha')->first();
$user->id;          // UUID property used as Eloquent key
$user->elementId(); // Neo4j element id, e.g. "4:xxx:123"
```

Laravel's `SoftDeletes` trait works on Neo4j models (`deleted_at` property,
`withTrashed` / `restore` / `forceDelete`). Hard deletes use `DETACH DELETE`.

Pagination APIs (`paginate`, `simplePaginate`, `cursorPaginate`) work via
Cypher `SKIP` / `LIMIT` and aggregates for totals.

Subquery filters use stock Laravel APIs (`whereIn` / `whereNotIn` with a
closure, query builder, Eloquent builder, or relation; and `whereExists` /
`whereNotExists`). They compile to Cypher `COLLECT { }` / `EXISTS { }`:

```php
User::query()
    ->whereIn('id', function ($query) {
        $query->select('user_id')->from('Post');
    })
    ->get();

User::query()
    ->whereIn('id', $user->posts()->select('user_id'))
    ->get();
```

Use `DB::connection('neo4j')->table(...)` or Eloquent on a Neo4j model for
these. Constructing `new Builder($connection, new Neo4jQueryGrammar)` by hand
still goes through Laravel's compiled Expression path for subquery `whereIn`
and is not supported — that path throws at compile time.

Foreign-key relations work with stock Eloquent APIs against node properties
(for example `Profile.user_id` / `Post.user_id` pointing at `User.id`):

- one-to-one: `hasOne` / `belongsTo`
- one-to-many: `hasMany` / `belongsTo`
- many-to-many: stock `belongsToMany` via a pivot **label** (node), e.g. `RoleUser`
  with `user_id` and `role_id` (Eloquent joins compile to multi-node MATCH + WHERE)
- through: stock `hasOneThrough` / `hasManyThrough` (same cartesian join compilation)
- polymorphic: stock `morphOne` / `morphMany` / `morphTo` (type + id properties),
  and stock `morphToMany` / `morphedByMany` via a pivot label (e.g. `Taggable`)

Including lazy load, `with(...)` eager load, `$user->profile()->create([...])`,
`$user->posts()->create([...])`, and `$user->roles()->attach([...])` / `detach`.

For first-class Cypher relationships on Eloquent models, use the same
`matchRelationship()` name as on the Query Builder — it returns an Eloquent
`MatchRelationship` relation (lazy load, constrained query, `with()`,
`attach()`, `sync()`, `detach()`, `create()`):

```php
class Person extends Neo4jModel
{
    public function movies()
    {
        return $this->matchRelationship(Movie::class, 'ACTED_IN>');
    }
}

$person->movies;                              // lazy
$person->movies()->where('title', '…')->get(); // constrained
Person::with('movies')->get();                // eager
$person->movies()->attach($movie->id, ['roles' => ['Neo']]);
$person->movies()->attach([$matrix->id, $johnWick->id]);    // one query
$person->movies()->sync([$matrix->id]);                     // keep only these, no node deleted
$person->movies()->detach($johnWick->id);                   // or detach() for all
$person->movies()->create(['title' => 'The Matrix'], ['roles' => ['Neo']]);
```

Direction markers match the Query Builder (`Type>`, `<Type`, bare type).
`whereHas` / `withCount` on these graph relations are not supported yet.

For a dedicated graph model, the package also provides
`Neo4j\Neo4jLaravel\Neo4jModel`, which already includes the concern.

```php
use Neo4j\Neo4jLaravel\Neo4jModel;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class User extends Neo4jModel
{
    protected $guarded = [];

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class, 'user_id', 'id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id', 'id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'RoleUser', 'user_id', 'role_id');
    }
}

class Profile extends Neo4jModel
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}

class Post extends Neo4jModel
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function image(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable', 'Taggable', 'taggable_id', 'tag_id');
    }
}

class Role extends Neo4jModel
{
    protected $guarded = [];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'RoleUser', 'role_id', 'user_id');
    }
}

class Image extends Neo4jModel
{
    protected $guarded = [];

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}

class Comment extends Neo4jModel
{
    protected $guarded = [];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

class Tag extends Neo4jModel
{
    protected $guarded = [];

    public function posts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'taggable', 'Taggable', 'tag_id', 'taggable_id');
    }
}

class Country extends Neo4jModel
{
    protected $guarded = [];

    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, User::class, 'country_id', 'user_id', 'id', 'id');
    }
}

class Mechanic extends Neo4jModel
{
    protected $guarded = [];

    public function carOwner(): HasOneThrough
    {
        return $this->hasOneThrough(Owner::class, Car::class, 'mechanic_id', 'car_id', 'id', 'id');
    }
}

$user = User::create(['name' => 'Ada']);
$user->profile()->create(['bio' => 'Engineer']);
$user->posts()->create(['title' => 'First']);

$admin = Role::create(['name' => 'admin']);
$user->roles()->attach($admin->id);

$post = Post::where('title', 'First')->first();
$post->image()->create(['url' => 'cover.png']);
$post->comments()->create(['body' => 'Nice']);
$post->tags()->attach(Tag::create(['name' => 'neo4j'])->id);

$user = User::with(['profile', 'posts', 'roles'])->where('name', 'Ada')->first();
$user->profile->bio; // Engineer
$user->roles->pluck('name'); // ['admin']
$post->image->url; // cover.png
$post->tags->pluck('name'); // ['neo4j']

$country = Country::with('posts')->where('name', 'India')->first();
$mechanic = Mechanic::with('carOwner')->first();
```

### Using Neo4j Client Interface

`ClientInterface`, `DriverInterface`, and `SessionInterface` resolved from the container are capturing wrappers, so `$session->run(...)` appears in Debugbar like `DB::connection('neo4j')`:

```php
use Laudis\Neo4j\Contracts\SessionInterface;

class YourController extends Controller
{
    public function index(SessionInterface $session)
    {
        $result = $session->run(<<<'CYPHER'
            MATCH (n)
            RETURN n
        CYPHER);
        return response()->json($result->toArray());
    }
}
```

### Example: Movie Management

```php
// Create a movie
$result = DB::connection('neo4j')->statement(<<<'CYPHER'
    CREATE (m:Movie {
        title: $title,
        released: $released,
        tagline: $tagline,
        created_at: datetime()
    })
    RETURN m
CYPHER, [
    'title' => 'The Matrix',
    'released' => 1999,
    'tagline' => 'Welcome to the Real World'
]);

// Add an actor to a movie
$result = DB::connection('neo4j')->select(<<<'CYPHER'
    MATCH (m:Movie {title: $movieTitle})
    MERGE (a:Person {name: $actorName})
    MERGE (a)-[r:ACTED_IN]->(m)
    SET r.roles = $roles
    RETURN m, a, r
CYPHER, [
    'movieTitle' => 'The Matrix',
    'actorName' => 'Keanu Reeves',
    'roles' => ['Neo']
]);

// Find similar movies
$result = DB::connection('neo4j')->select(<<<'CYPHER'
    MATCH (m:Movie {title: $title})<-[:ACTED_IN]-(a:Person)-[:ACTED_IN]->(other:Movie)
    WHERE m <> other
    WITH other, count(distinct a) as commonActors
    RETURN other, commonActors
    ORDER BY commonActors DESC
    LIMIT 5
CYPHER, ['title' => 'The Matrix']);
```

### Example: User Management

```php
// Create a user
$result = $session->run(<<<'CYPHER'
    CREATE (u:User {
        name: $name,
        email: $email,
        created_at: datetime()
    })
    RETURN u
CYPHER, [
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);

// Update a user
$result = $session->run(<<<'CYPHER'
    MATCH (u:User {email: $email})
    SET u.name = $name, u.updated_at = datetime()
    RETURN u
CYPHER, [
    'email' => 'john@example.com',
    'name' => 'John Smith'
]);
```

## Features

- Seamless integration with Laravel's database layer
- Support for both DB Facade and Neo4j Client Interface
- Query Builder `matchRelationship()` / `insertRelationship()` for Cypher patterns
- Bulk relationship writes (`insertRelationships()` / `upsertRelationships()` / `syncRelationships()` / `deleteRelationships()`) in one statement
- Eloquent `matchRelationship()` Relation for graph edges on models (lazy / eager / attach / sync / detach)
- Transaction support
- Parameterized queries
- Optional Laravel Debugbar support (Cypher in the shared Queries tab)
- Query log flushing via `NEO4J_QUERY_CHANNEL`
- SSL configuration options
- Connection pooling
- Timeout settings for connections and transactions

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
