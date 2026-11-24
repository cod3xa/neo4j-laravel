<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;
use Neo4j\Neo4jLaravel\Tests\TestCase;

final class Neo4jEloquentMatchRelationshipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = $this->app->make('db')->connection('neo4j');
        $connection->statement('MATCH (n:ErPerson) DETACH DELETE n');
        $connection->statement('MATCH (n:ErMovie) DETACH DELETE n');
    }

    protected function tearDown(): void
    {
        $connection = $this->app->make('db')->connection('neo4j');
        $connection->statement('MATCH (n:ErPerson) DETACH DELETE n');
        $connection->statement('MATCH (n:ErMovie) DETACH DELETE n');

        parent::tearDown();
    }

    public function testLazyLoadConstrainedQueryAndEagerLoad(): void
    {
        $keanu = ErPerson::create(['name' => 'Keanu']);
        $carrie = ErPerson::create(['name' => 'Carrie']);
        $matrix = ErMovie::create(['title' => 'The Matrix']);
        $johnWick = ErMovie::create(['title' => 'John Wick']);
        $other = ErMovie::create(['title' => 'Other']);

        $keanu->movies()->attach($matrix->id, ['roles' => ['Neo']]);
        $keanu->movies()->attach($johnWick->id, ['roles' => ['John']]);
        $carrie->movies()->attach($matrix->id, ['roles' => ['Trinity']]);

        $movies = $keanu->fresh()->movies;
        self::assertCount(2, $movies);
        self::assertSame(
            ['John Wick', 'The Matrix'],
            $movies->pluck('title')->sort()->values()->all()
        );

        $filtered = $keanu->movies()->where('title', 'The Matrix')->get();
        self::assertCount(1, $filtered);
        self::assertSame('The Matrix', $filtered->first()->title);

        $loaded = ErPerson::with('movies')->orderBy('name')->get();
        self::assertCount(2, $loaded);

        $keanuLoaded = $loaded->firstWhere('name', 'Keanu');
        $carrieLoaded = $loaded->firstWhere('name', 'Carrie');

        self::assertTrue($keanuLoaded->relationLoaded('movies'));
        self::assertTrue($carrieLoaded->relationLoaded('movies'));
        self::assertCount(2, $keanuLoaded->movies);
        self::assertCount(1, $carrieLoaded->movies);
        self::assertSame(['John Wick', 'The Matrix'], $keanuLoaded->movies->pluck('title')->sort()->values()->all());
        self::assertSame(['The Matrix'], $carrieLoaded->movies->pluck('title')->all());

        self::assertFalse($keanuLoaded->movies->first()->offsetExists(MatchRelationship::PARENT_KEY_ALIAS));
        self::assertSame(0, ErMovie::where('title', 'Other')->firstOrFail()->actors()->count());
        self::assertCount(2, $matrix->fresh()->actors);
    }

    public function testCreateRelatedModelAndRelationship(): void
    {
        $person = ErPerson::create(['name' => 'Keanu']);

        $movie = $person->movies()->create(
            ['title' => 'The Matrix'],
            ['roles' => ['Neo']],
        );

        self::assertInstanceOf(ErMovie::class, $movie);
        self::assertSame('The Matrix', $movie->title);
        self::assertSame(
            ['The Matrix'],
            $person->fresh()->movies->pluck('title')->all()
        );
    }

    public function testWhereHasAndWhereDoesntHaveAgainstLiveGraph(): void
    {
        $keanu = ErPerson::create(['name' => 'Keanu']);
        $carrie = ErPerson::create(['name' => 'Carrie']);
        $nobody = ErPerson::create(['name' => 'Nobody']);
        $matrix = ErMovie::create(['title' => 'The Matrix']);
        $johnWick = ErMovie::create(['title' => 'John Wick']);

        $keanu->movies()->attach($matrix->id, ['roles' => ['Neo']]);
        $keanu->movies()->attach($johnWick->id, ['roles' => ['John']]);
        $carrie->movies()->attach($matrix->id, ['roles' => ['Trinity']]);

        self::assertSame(
            ['Carrie', 'Keanu'],
            ErPerson::whereHas('movies')->orderBy('name')->pluck('name')->all()
        );
        self::assertSame(
            ['Nobody'],
            ErPerson::whereDoesntHave('movies')->pluck('name')->all()
        );
        self::assertSame(
            ['Carrie', 'Keanu'],
            ErPerson::whereHas('movies', fn ($q) => $q->where('title', 'The Matrix'))
                ->orderBy('name')
                ->pluck('name')
                ->all()
        );
        self::assertSame(
            ['Keanu'],
            ErPerson::whereHas('movies', fn ($q) => $q->where('title', 'John Wick'))
                ->pluck('name')
                ->all()
        );
        self::assertSame(
            ['John Wick', 'The Matrix'],
            ErMovie::whereHas('actors')->orderBy('title')->pluck('title')->all()
        );
    }
}

final class ErPerson extends Neo4jModel
{
    protected $table = 'ErPerson';

    protected $guarded = [];

    public function movies(): MatchRelationship
    {
        return $this->matchRelationship(ErMovie::class, 'ACTED_IN>');
    }
}

final class ErMovie extends Neo4jModel
{
    protected $table = 'ErMovie';

    protected $guarded = [];

    public function actors(): MatchRelationship
    {
        return $this->matchRelationship(ErPerson::class, '<ACTED_IN');
    }
}
