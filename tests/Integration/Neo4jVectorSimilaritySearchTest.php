<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

use ArrayObject;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Tests\TestCase;
use RuntimeException;

/**
 * Exercises the query steps of Laravel AI's SimilaritySearch::usingModel() against Neo4j.
 *
 * laravel/ai is not a dependency of this package, so the Stringable::toEmbeddings macro
 * is faked with the same contract (variadic named arguments, float list result).
 */
final class Neo4jVectorSimilaritySearchTest extends TestCase
{
    private ArrayObject $embeddingCalls;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = $this->neo4jConnection();
        $connection->statement('MATCH (n) WHERE n:Article OR n:Point DETACH DELETE n');
        $connection->dropVectorIndex('article_embedding');
        $connection->dropVectorIndex('point_embedding');
        $connection->createVectorIndex('article_embedding', 'Article', 'embedding', 3);
        $connection->createVectorIndex('point_embedding', 'Point', 'embedding', 3, 'euclidean');
        $connection->statement("CALL db.awaitIndex('article_embedding')");
        $connection->statement("CALL db.awaitIndex('point_embedding')");

        $this->embeddingCalls = new ArrayObject();
    }

    protected function tearDown(): void
    {
        Stringable::flushMacros();

        $connection = $this->neo4jConnection();
        $connection->statement('MATCH (n) WHERE n:Article OR n:Point DETACH DELETE n');
        $connection->dropVectorIndex('article_embedding');
        $connection->dropVectorIndex('point_embedding');

        parent::tearDown();
    }

    public function testRawTextIsEmbeddedThroughTheStringableMacroAndRanked(): void
    {
        $this->fakeEmbeddingMacro([
            'graphs' => [1.0, 0.0, 0.0],
        ]);

        Article::create(['title' => 'Graphs', 'category' => 'news', 'active' => true, 'embedding' => [1.0, 0.0, 0.0]]);
        Article::create(['title' => 'Graph-adjacent', 'category' => 'news', 'active' => true, 'embedding' => [0.8, 0.6, 0.0]]);
        Article::create(['title' => 'Cooking', 'category' => 'news', 'active' => true, 'embedding' => [0.0, 1.0, 0.0]]);

        // "Cooking" is orthogonal (normalized score 0.5), so the default 0.6 excludes it.
        $results = $this->similaritySearch('graphs');

        self::assertSame(['Graphs', 'Graph-adjacent'], $results->pluck('title')->all());
        // Neo4j cosine index scores are normalized to (1 + cos) / 2.
        self::assertEqualsWithDelta(1.0, $results[0]['score'], 0.0001);
        self::assertEqualsWithDelta(0.9, $results[1]['score'], 0.0001);

        self::assertCount(1, $this->embeddingCalls);
        self::assertSame('graphs', $this->embeddingCalls[0]['text']);
        self::assertSame(['cache' => true], $this->embeddingCalls[0]['arguments']);
    }

    public function testMinimumSimilarityThresholdIsEnforced(): void
    {
        $this->fakeEmbeddingMacro(['graphs' => [1.0, 0.0, 0.0]]);

        Article::create(['title' => 'Graphs', 'active' => true, 'embedding' => [1.0, 0.0, 0.0]]);
        Article::create(['title' => 'Graph-adjacent', 'active' => true, 'embedding' => [0.8, 0.6, 0.0]]);

        $results = $this->similaritySearch('graphs', minSimilarity: 0.95);

        self::assertSame(['Graphs'], $results->pluck('title')->all());
    }

    public function testFiltersAreAppliedBeforeTheCandidateLimit(): void
    {
        $this->fakeEmbeddingMacro(['graphs' => [1.0, 0.0, 0.0]]);

        // Fifteen inactive nodes are more similar than every active node. Filtering after
        // the index's top-k would return nothing here.
        for ($i = 0; $i < 15; $i++) {
            Article::create(['title' => "Inactive {$i}", 'active' => false, 'embedding' => [1.0, 0.0, 0.0]]);
        }
        Article::create(['title' => 'Active A', 'active' => true, 'embedding' => [0.8, 0.6, 0.0]]);
        Article::create(['title' => 'Active B', 'active' => true, 'embedding' => [0.75, 0.66, 0.0]]);
        Article::create(['title' => 'Active C', 'active' => true, 'embedding' => [0.7, 0.71, 0.0]]);

        $results = $this->similaritySearch(
            'graphs',
            minSimilarity: 0.5,
            limit: 3,
            scope: fn (Builder $query) => $query->where('active', true),
        );

        self::assertSame(['Active A', 'Active B', 'Active C'], $results->pluck('title')->all());
    }

    public function testNestedOrGroupIsAppliedUnderTheVectorThreshold(): void
    {
        $this->fakeEmbeddingMacro(['graphs' => [1.0, 0.0, 0.0]]);

        Article::create(['title' => 'News above', 'category' => 'news', 'active' => true, 'embedding' => [1.0, 0.0, 0.0]]);
        Article::create(['title' => 'Blog above', 'category' => 'blog', 'active' => true, 'embedding' => [0.8, 0.6, 0.0]]);
        Article::create(['title' => 'Blog below', 'category' => 'blog', 'active' => true, 'embedding' => [0.0, 1.0, 0.0]]);
        Article::create(['title' => 'Other above', 'category' => 'other', 'active' => true, 'embedding' => [1.0, 0.0, 0.0]]);

        // "Blog below" scores 0.5 (orthogonal) and is excluded by the threshold.
        $results = $this->similaritySearch(
            'graphs',
            minSimilarity: 0.6,
            scope: fn (Builder $query) => $query->where(
                fn (Builder $group) => $group->where('category', 'news')->orWhere('category', 'blog'),
            ),
        );

        self::assertSame(['News above', 'Blog above'], $results->pluck('title')->all());
    }

    public function testLimitZeroFromLaravelAiReturnsEveryQualifyingResult(): void
    {
        $this->fakeEmbeddingMacro(['graphs' => [1.0, 0.0, 0.0]]);

        // More than the default cap of 10 to prove no hidden LIMIT is added.
        for ($i = 0; $i < 25; $i++) {
            Article::create(['title' => "Match {$i}", 'active' => true, 'embedding' => [1.0, $i * 0.001, 0.0]]);
        }
        Article::create(['title' => 'Too far', 'active' => true, 'embedding' => [0.0, 1.0, 0.0]]);

        $unlimited = $this->similaritySearch('graphs', minSimilarity: 0.9, limit: 0);
        $capped = $this->similaritySearch('graphs', minSimilarity: 0.9, limit: 15);

        self::assertCount(25, $unlimited);
        self::assertCount(15, $capped);
        self::assertNotContains('Too far', $unlimited->pluck('title')->all());
    }

    public function testExplicitLimitZeroReturnsNoRows(): void
    {
        Article::create(['title' => 'Graphs', 'active' => true, 'embedding' => [1.0, 0.0, 0.0]]);

        $plain = Article::query()->whereVectorSimilarTo('embedding', [1.0, 0.0, 0.0], 0.5)->limit(0)->get();
        $filtered = Article::query()
            ->where('active', true)
            ->whereVectorSimilarTo('embedding', [1.0, 0.0, 0.0], 0.5)
            ->limit(0)
            ->get();

        self::assertCount(0, $plain);
        self::assertCount(0, $filtered);
    }

    public function testPositiveLimitReturnsTheTopKByScore(): void
    {
        Article::create(['title' => 'Best', 'embedding' => [1.0, 0.0, 0.0]]);
        Article::create(['title' => 'Second', 'embedding' => [0.8, 0.6, 0.0]]);
        Article::create(['title' => 'Third', 'embedding' => [0.6, 0.8, 0.0]]);

        $results = Article::query()
            ->whereVectorSimilarTo('embedding', [1.0, 0.0, 0.0], 0.1)
            ->limit(2)
            ->get();

        self::assertSame(['Best', 'Second'], $results->pluck('title')->all());
    }

    public function testSoftDeletedArticlesAreExcludedFromSimilaritySearch(): void
    {
        $this->fakeEmbeddingMacro(['graphs' => [1.0, 0.0, 0.0]]);

        $deleted = Article::create(['title' => 'Deleted', 'active' => true, 'embedding' => [1.0, 0.0, 0.0]]);
        Article::create(['title' => 'Kept', 'active' => true, 'embedding' => [0.9, 0.1, 0.0]]);

        $deleted->delete();

        self::assertSame(2, Article::withTrashed()->count());

        $results = $this->similaritySearch('graphs', minSimilarity: 0.5);

        self::assertSame(['Kept'], $results->pluck('title')->all());
    }

    public function testEmbeddingPropertyIsExcludedFromTheReturnedJson(): void
    {
        $this->fakeEmbeddingMacro(['graphs' => [1.0, 0.0, 0.0]]);

        Article::create(['title' => 'Graphs', 'category' => 'news', 'active' => true, 'embedding' => [1.0, 0.0, 0.0]]);

        $json = json_encode($this->similaritySearch('graphs', minSimilarity: 0.5), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"title":"Graphs"', $json);
        self::assertStringNotContainsString('embedding', $json);
    }

    public function testFilteredScanScoresMatchTheIndexForCosineAndEuclidean(): void
    {
        Article::create(['title' => 'A', 'active' => true, 'embedding' => [1.0, 0.0, 0.0]]);
        Article::create(['title' => 'B', 'active' => true, 'embedding' => [0.5, 0.5, 0.0]]);
        Point::create(['title' => 'A', 'active' => true, 'embedding' => [1.0, 0.0, 0.0]]);
        Point::create(['title' => 'B', 'active' => true, 'embedding' => [0.5, 0.5, 0.0]]);

        $cosineIndex = Article::query()->whereVectorSimilarTo('embedding', [1.0, 0.0, 0.0], 0.0)->limit(10)->get();
        $cosineScan = Article::query()
            ->where('active', true)
            ->whereVectorSimilarTo('embedding', [1.0, 0.0, 0.0], 0.0)
            ->get();

        $euclideanIndex = Point::query()->whereVectorSimilarTo('embedding', [1.0, 0.0, 0.0], 0.0)->limit(10)->get();
        $euclideanScan = Point::query()
            ->where('active', true)
            ->useVectorSimilarityFunction('euclidean')
            ->whereVectorSimilarTo('embedding', [1.0, 0.0, 0.0], 0.0)
            ->get();

        $this->assertScoresMatch($this->scoresByTitle($cosineIndex), $this->scoresByTitle($cosineScan));
        $this->assertScoresMatch($this->scoresByTitle($euclideanIndex), $this->scoresByTitle($euclideanScan));
        self::assertEqualsWithDelta(0.85355, $this->scoresByTitle($cosineScan)['B'], 0.0001);
        self::assertEqualsWithDelta(0.6667, $this->scoresByTitle($euclideanScan)['B'], 0.0001);
    }

    /**
     * @param  array<string, float>  $expected
     * @param  array<string, float>  $actual
     */
    private function assertScoresMatch(array $expected, array $actual): void
    {
        self::assertSame(array_keys($expected), array_keys($actual));

        foreach ($expected as $title => $score) {
            self::assertEqualsWithDelta($score, $actual[$title], 0.0001, "score for {$title}");
        }
    }

    public function testMalformedEmbeddingsDoNotBreakFilteredSearch(): void
    {
        $this->fakeEmbeddingMacro(['graphs' => [1.0, 0.0, 0.0]]);

        Article::create(['title' => 'Graphs', 'active' => true, 'embedding' => [1.0, 0.0, 0.0]]);

        // Wrong dimension, string, and missing properties: the index ignores these, and the
        // filtered scan must skip them instead of failing the whole query.
        $connection = $this->neo4jConnection();
        $connection->statement("CREATE (:Article {title: 'Wrong dims', active: true, embedding: [1.0, 0.0]})");
        $connection->statement("CREATE (:Article {title: 'Text', active: true, embedding: 'abc'})");
        $connection->statement("CREATE (:Article {title: 'Missing', active: true})");

        $results = $this->similaritySearch(
            'graphs',
            minSimilarity: 0.5,
            limit: 0,
            scope: fn (Builder $query) => $query->where('active', true),
        );

        self::assertSame(['Graphs'], $results->pluck('title')->all());
    }

    /**
     * Mirrors the query steps of Laravel AI's SimilaritySearch::usingModel() closure.
     *
     * @param  Closure(Builder): Builder|null  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function similaritySearch(
        string $queryString,
        float $minSimilarity = 0.6,
        int $limit = 15,
        ?Closure $scope = null,
    ): Collection {
        $pendingQuery = Article::query()->whereVectorSimilarTo('embedding', $queryString, $minSimilarity);

        if ($scope !== null) {
            $pendingQuery = $scope($pendingQuery);
        }

        if ($limit !== 0) {
            $pendingQuery->limit($limit);
        }

        return $pendingQuery->get()->map(
            fn (Article $model): array => Arr::except($model->toArray(), ['embedding']),
        );
    }

    /**
     * @param  array<string, list<float>>  $embeddings
     */
    private function fakeEmbeddingMacro(array $embeddings): void
    {
        $calls = $this->embeddingCalls;

        Stringable::macro('toEmbeddings', function (...$arguments) use ($embeddings, $calls): array {
            $text = (string) $this;
            $calls[] = ['text' => $text, 'arguments' => $arguments];

            return $embeddings[$text] ?? throw new RuntimeException("No fake embedding for [{$text}].");
        });
    }

    /**
     * @return array<string, float>
     */
    private function scoresByTitle(Collection $models): array
    {
        return $models
            ->mapWithKeys(fn (Neo4jModel $model): array => [$model->title => (float) $model->score])
            ->all();
    }

    private function neo4jConnection(): Neo4jConnection
    {
        /** @var Neo4jConnection $connection */
        $connection = $this->app->make('db')->connection('neo4j');

        return $connection;
    }
}

final class Article extends Neo4jModel
{
    use SoftDeletes;

    protected $guarded = [];
}

final class Point extends Neo4jModel
{
    protected $guarded = [];
}
