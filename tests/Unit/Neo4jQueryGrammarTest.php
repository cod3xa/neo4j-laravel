<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use InvalidArgumentException;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Types\CypherList;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use Neo4j\Neo4jLaravel\Neo4jQueryBuilder;
use Neo4j\Neo4jLaravel\Neo4jQueryGrammar;
use Neo4j\Neo4jLaravel\VectorBinding;
use PHPUnit\Framework\TestCase;

final class Neo4jQueryGrammarTest extends TestCase
{
    public function testCompilesSelectWithDslBackedClauses(): void
    {
        $builder = $this->builder()
            ->from('Person')
            ->where('name', 'Tom Hanks')
            ->whereIn('born', [1956, 1957])
            ->orderBy('name')
            ->offset(10)
            ->limit(5);

        self::assertSame(
            'MATCH (n:Person) WHERE ((n.name = $p0) AND (n.born IN [$p1, $p2])) '
                .'RETURN n ORDER BY n.name ASC SKIP 10 LIMIT 5',
            $builder->toSql()
        );
        self::assertSame(['Tom Hanks', 1956, 1957], $builder->getBindings());
    }

    public function testCompilesNestedWheresNullChecksAndStringOperators(): void
    {
        $builder = $this->builder()
            ->from('Person:Actor')
            ->where(function (Builder $query): void {
                $query->where('name', 'starts with', 'Tom')
                    ->orWhereNull('retired_at');
            })
            ->whereNotBetween('born', [1900, 2000]);

        self::assertSame(
            'MATCH (n:Person:Actor) WHERE (((n.name STARTS WITH $p0) OR (n.retired_at IS NULL)) '
                .'AND (NOT ((n.born >= $p1) AND (n.born <= $p2)))) RETURN n',
            $builder->toSql()
        );
    }

    public function testFlatOrWhereUsesSqlAndOrPrecedence(): void
    {
        $builder = $this->builder()
            ->from('Person')
            ->where('a', 1)
            ->orWhere('b', 2)
            ->where('c', 3);

        self::assertSame(
            'MATCH (n:Person) WHERE ((n.a = $p0) OR ((n.b = $p1) AND (n.c = $p2))) RETURN n',
            $builder->toSql()
        );
    }

    public function testCompilesSelectedPropertiesDistinctAndMixedOrdering(): void
    {
        $builder = $this->builder()
            ->from('Person')
            ->distinct()
            ->select(['name', 'n.born'])
            ->orderBy('name')
            ->orderByDesc('born');

        self::assertSame(
            'MATCH (n:Person) RETURN DISTINCT n.name, n.born ORDER BY n.name ASC, n.born DESC',
            $builder->toSql()
        );
    }

    public function testCompilesEmptyInClausesWithoutBindings(): void
    {
        $builder = $this->builder()
            ->from('Person')
            ->whereIn('name', []);

        self::assertSame('MATCH (n:Person) WHERE false RETURN n', $builder->toSql());
        self::assertSame([], $builder->getBindings());
    }

    public function testRejectsInvalidLabels(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder()->from('Person`) MATCH (x')->toSql();
    }

    public function testMapsEloquentQualifiedColumnsToTheNodeVariable(): void
    {
        $builder = $this->builder()
            ->from('User')
            ->where('User.id', 'user-1');

        self::assertSame(
            'MATCH (n:User) WHERE (n.id = $p0) RETURN n',
            $builder->toSql()
        );
    }

    public function testConnectionMapsPositionalBindingsToCypherParameterNames(): void
    {
        $connection = new Neo4jConnection($this->createMock(ClientInterface::class));

        self::assertSame(
            ['p0' => 'Tom Hanks', 'p1' => 1956],
            $connection->prepareBindings(['Tom Hanks', 1956])
        );
        self::assertSame(
            ['name' => 'Tom Hanks'],
            $connection->prepareBindings(['name' => 'Tom Hanks'])
        );
        self::assertSame(
            ['p0' => [0.1, 0.2], 'p1' => 0.4],
            $connection->prepareBindings([new VectorBinding([0.1, 0.2]), 0.4])
        );
        self::assertSame(
            ['p0' => true, 'p1' => false, 'flag' => true],
            $connection->prepareBindings([true, false, 'flag' => true])
        );
        self::assertSame(
            ['p0' => '2026-01-02 03:04:05'],
            $connection->prepareBindings([new \DateTimeImmutable('2026-01-02 03:04:05')])
        );
    }

    public function testCompilesSingleAndBatchInserts(): void
    {
        $grammar = new Neo4jQueryGrammar();
        $builder = $this->builder()->from('User');

        self::assertSame(
            'CREATE (n0:User {id: $p0, name: $p1})',
            $grammar->compileInsert($builder, [['id' => 'user-1', 'name' => 'Pratiksha']])
        );
        self::assertSame(
            'CREATE (n0:User {id: $p0, name: $p1}), (n1:User {id: $p2, name: $p3})',
            $grammar->compileInsert($builder, [
                ['id' => 'user-1', 'name' => 'Pratiksha'],
                ['id' => 'user-2', 'name' => 'Ghlen'],
            ])
        );
    }

    public function testCompilesUpdateWithValueBindingsBeforeWhereBindings(): void
    {
        $grammar = new Neo4jQueryGrammar();
        $builder = $this->builder()
            ->from('User')
            ->where('id', 'user-1');

        self::assertSame(
            'MATCH (n:User) WHERE (n.id = $p1) SET n.name = $p0',
            $grammar->compileUpdate($builder, ['name' => 'Pratiksha Zalte'])
        );
        self::assertSame(
            ['Pratiksha Zalte', 'user-1'],
            $grammar->prepareBindingsForUpdate($builder->getRawBindings(), ['Pratiksha Zalte'])
        );
    }

    public function testCompilesUpdateWithEloquentQualifiedTimestampColumns(): void
    {
        $grammar = new Neo4jQueryGrammar();
        $builder = $this->builder()
            ->from('User')
            ->where('User.id', 'user-1');

        self::assertSame(
            'MATCH (n:User) WHERE (n.id = $p2) SET n.name = $p0, n.updated_at = $p1',
            $grammar->compileUpdate($builder, [
                'name' => 'Pratiksha Zalte',
                'User.updated_at' => '2026-08-20 09:56:11',
            ])
        );
    }

    public function testCompilesDelete(): void
    {
        $grammar = new Neo4jQueryGrammar();
        $builder = $this->builder()
            ->from('User')
            ->where('id', 'user-1');

        self::assertSame(
            'MATCH (n:User) WHERE (n.id = $p0) DETACH DELETE n',
            $grammar->compileDelete($builder)
        );
    }

    public function testCompilesVectorSimilaritySearch(): void
    {
        $embedding = [0.1, 0.2, 0.3];

        $builder = $this->vectorBuilder()
            ->from('Document')
            ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: 0.4)
            ->limit(10);

        self::assertSame(
            'CALL db.index.vector.queryNodes(\'document_embedding\', 10, $p0) YIELD node AS n, score '
                .'WHERE score >= $p1 RETURN n, score ORDER BY score DESC LIMIT 10',
            $builder->toSql()
        );
        self::assertSame([$embedding, 0.4], $this->vectorBindings($builder));
    }

    public function testCompilesVectorSimilarityWithAdditionalWheresAsExactScan(): void
    {
        $embedding = [0.4, 0.5, 0.6];

        $builder = $this->vectorBuilder()
            ->from('Document')
            ->where('status', 'published')
            ->useVectorIndex('docs_by_embedding')
            ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: 0.8)
            ->limit(5);

        // The index would pick its k candidates before WHERE runs, so filters must come first.
        self::assertSame(
            'MATCH (n:Document) WHERE (n.status = $p0) WITH n, '
                .'CASE WHEN n.embedding IS :: LIST<FLOAT NOT NULL> THEN CASE WHEN size(n.embedding) = size($p1) '
                .'THEN vector.similarity.cosine(n.embedding, $p1) END END AS score '
                .'WHERE score >= $p2 RETURN n, score ORDER BY coalesce(score, -1.0) DESC LIMIT 5',
            $builder->toSql()
        );
        self::assertSame(['published', $embedding, 0.8], $this->vectorBindings($builder));
    }

    public function testCompilesCustomVectorIndexForPlainVectorSearch(): void
    {
        $embedding = [0.4, 0.5, 0.6];

        $builder = $this->vectorBuilder()
            ->from('Document')
            ->useVectorIndex('docs_by_embedding')
            ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: 0.8)
            ->limit(5);

        self::assertSame(
            'CALL db.index.vector.queryNodes(\'docs_by_embedding\', 5, $p0) YIELD node AS n, score '
                .'WHERE score >= $p1 RETURN n, score ORDER BY score DESC LIMIT 5',
            $builder->toSql()
        );
        self::assertSame([$embedding, 0.8], $this->vectorBindings($builder));
    }

    public function testRejectsNonArrayEmbeddings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->vectorBuilder()->from('Document')->whereVectorSimilarTo('embedding', 'find similar docs');
    }

    public function testEmbedsStringQueryThroughStringableMacroWithCache(): void
    {
        $captured = null;
        Stringable::macro('toEmbeddings', function (...$arguments) use (&$captured): array {
            $captured = ['text' => (string) $this, 'arguments' => $arguments];

            return [0.1, 0.2, 0.3];
        });

        try {
            $builder = $this->vectorBuilder()
                ->from('Document')
                ->whereVectorSimilarTo('embedding', 'find similar docs', minSimilarity: 0.4)
                ->limit(10);

            self::assertSame(['text' => 'find similar docs', 'arguments' => ['cache' => true]], $captured);
            self::assertSame([[0.1, 0.2, 0.3], 0.4], $this->vectorBindings($builder));
            self::assertStringContainsString('db.index.vector.queryNodes(\'document_embedding\', 10, $p0)', $builder->toSql());
        } finally {
            Stringable::flushMacros();
        }
    }

    public function testStringQueryWithoutToEmbeddingsMacroFailsWithActionableMessage(): void
    {
        Stringable::flushMacros();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stringable::toEmbeddings macro, which laravel/ai registers');

        $this->vectorBuilder()->from('Document')->whereVectorSimilarTo('embedding', 'find similar docs');
    }

    public function testEmbeddingMacroResultIsValidatedLikeArrays(): void
    {
        Stringable::macro('toEmbeddings', fn (...$arguments): array => ['not-a-number']);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('finite numeric components');

            $this->vectorBuilder()->from('Document')->whereVectorSimilarTo('embedding', 'text');
        } finally {
            Stringable::flushMacros();
        }
    }

    public function testEmbeddingMacroCollectionResultIsNormalized(): void
    {
        Stringable::macro('toEmbeddings', fn (...$arguments): Collection => collect([1, 0, 0]));

        try {
            $builder = $this->vectorBuilder()->from('Document')->whereVectorSimilarTo('embedding', 'text');

            self::assertSame([[1.0, 0.0, 0.0], 0.6], $this->vectorBindings($builder));
        } finally {
            Stringable::flushMacros();
        }
    }

    public function testAcceptsCollectionEmbedding(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->whereVectorSimilarTo('embedding', collect([0.1, 0.2, 0.3]), 0.5);

        self::assertSame([[0.1, 0.2, 0.3], 0.5], $this->vectorBindings($builder));
    }

    public function testAcceptsArrayableEmbedding(): void
    {
        $embedding = new class () implements Arrayable {
            public function toArray(): array
            {
                return [0.5, 0.5];
            }
        };

        $builder = $this->vectorBuilder()->from('Document')->whereVectorSimilarTo('embedding', $embedding);

        self::assertSame([[0.5, 0.5], 0.6], $this->vectorBindings($builder));
    }

    public function testAcceptsCypherListEmbeddingAndCastsComponentsToFloats(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->whereVectorSimilarTo('embedding', new CypherList([1, 0, 0]), 0.5);

        self::assertSame([[1.0, 0.0, 0.0], 0.5], $this->vectorBindings($builder));
    }

    public function testRejectsEmptyCollectionEmbedding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty embedding');

        $this->vectorBuilder()->from('Document')->whereVectorSimilarTo('embedding', collect());
    }

    public function testRejectsNonNumericEmbeddingComponents(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('finite numeric components');

        $this->vectorBuilder()->from('Document')->whereVectorSimilarTo('embedding', [0.1, 'x', 0.3]);
    }

    public function testRejectsNonFiniteEmbeddingComponents(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('finite numeric components');

        $this->vectorBuilder()->from('Document')->whereVectorSimilarTo('embedding', [0.1, NAN]);
    }

    public function testDefaultSimilarityThresholdIsBound(): void
    {
        $builder = $this->vectorBuilder()->from('Document')->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3]);

        self::assertSame([[0.1, 0.2, 0.3], 0.6], $this->vectorBindings($builder));
    }

    public function testOrderFalseUsesRegularOrdersInsteadOfScore(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.5, false)
            ->orderBy('title')
            ->limit(3);

        self::assertSame(
            'CALL db.index.vector.queryNodes(\'document_embedding\', 3, $p0) YIELD node AS n, score '
                .'WHERE score >= $p1 RETURN n, score ORDER BY n.title ASC LIMIT 3',
            $builder->toSql()
        );
    }

    public function testOffsetOverfetchesExactlyLimitPlusOffset(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.4)
            ->offset(5)
            ->limit(10);

        self::assertSame(
            'CALL db.index.vector.queryNodes(\'document_embedding\', 15, $p0) YIELD node AS n, score '
                .'WHERE score >= $p1 RETURN n, score ORDER BY score DESC SKIP 5 LIMIT 10',
            $builder->toSql()
        );
    }

    public function testUnlimitedVectorSearchAddsNoLimitAndUsesExactScan(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.8);

        $sql = $builder->toSql();

        self::assertStringNotContainsString('LIMIT', $sql);
        self::assertStringNotContainsString('queryNodes', $sql);
        self::assertStringStartsWith('MATCH (n:Document) WITH n, CASE WHEN n.embedding IS :: LIST<FLOAT NOT NULL>', $sql);
        self::assertSame([[0.1, 0.2, 0.3], 0.8], $this->vectorBindings($builder));
    }

    public function testExplicitZeroLimitReturnsNoRowsThroughLimitZero(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3])
            ->limit(0);

        self::assertStringEndsWith(' LIMIT 0', $builder->toSql());
        self::assertStringContainsString('queryNodes(\'document_embedding\', 1, $p0)', $builder->toSql());
    }

    public function testExplicitZeroLimitWithFilterStillReturnsNoRows(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->where('active', true)
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.8)
            ->limit(0);

        self::assertStringEndsWith(' LIMIT 0', $builder->toSql());
    }

    public function testAndConditionsCompileBeforeScoringWithMatchingBindings(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->where('a', 1)
            ->where('b', 2)
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.8)
            ->limit(3);

        self::assertSame(
            'MATCH (n:Document) WHERE ((n.a = $p0) AND (n.b = $p1)) WITH n, '
                .'CASE WHEN n.embedding IS :: LIST<FLOAT NOT NULL> THEN CASE WHEN size(n.embedding) = size($p2) '
                .'THEN vector.similarity.cosine(n.embedding, $p2) END END AS score '
                .'WHERE score >= $p3 RETURN n, score ORDER BY coalesce(score, -1.0) DESC LIMIT 3',
            $builder->toSql()
        );
        self::assertSame([1, 2, [0.1, 0.2, 0.3], 0.8], $this->vectorBindings($builder));
    }

    public function testParameterNumberingFollowsWhereOrderWhenVectorComesFirst(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.8)
            ->where('a', 1)
            ->limit(3);

        $sql = $builder->toSql();

        self::assertStringContainsString('WHERE (n.a = $p2) WITH n,', $sql);
        self::assertStringContainsString('size($p0)', $sql);
        self::assertStringContainsString('WHERE score >= $p1 ', $sql);
        self::assertSame([[0.1, 0.2, 0.3], 0.8, 1], $this->vectorBindings($builder));
    }

    public function testOrConditionsKeepGroupSemanticsAndVectorAppliesToTheWholeGroup(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->where('category', 'a')
            ->orWhere('category', 'b')
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.8)
            ->limit(3);

        self::assertStringContainsString(
            'WHERE (((n.category = $p0) OR (n.category = $p1)) AND score >= $p3) RETURN',
            $builder->toSql()
        );
        self::assertSame(['a', 'b', [0.1, 0.2, 0.3], 0.8], $this->vectorBindings($builder));
    }

    public function testOrAfterVectorFollowsLaravelBooleanChain(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.8)
            ->where('category', 'a')
            ->orWhere('category', 'b')
            ->limit(3);

        // Same as SQL's left-to-right chain: vector AND a OR b. Use a nested where to group.
        self::assertStringContainsString(
            'WHERE ((score >= $p1 AND (n.category = $p2)) OR (n.category = $p3)) RETURN',
            $builder->toSql()
        );
        self::assertSame([[0.1, 0.2, 0.3], 0.8, 'a', 'b'], $this->vectorBindings($builder));
    }

    public function testNestedConditionsCompileInsideTheScanFilter(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->where(fn ($query) => $query->where('a', 1)->orWhere('b', 2))
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.8)
            ->limit(3);

        self::assertStringContainsString('WHERE ((n.a = $p0) OR (n.b = $p1)) WITH n,', $builder->toSql());
        self::assertStringContainsString('WHERE score >= $p3 RETURN', $builder->toSql());
        self::assertSame([1, 2, [0.1, 0.2, 0.3], 0.8], $this->vectorBindings($builder));
    }

    public function testSoftDeleteStyleNullFilterIsAppliedBeforeScoring(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->whereNull('deleted_at')
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.8)
            ->limit(15);

        self::assertStringStartsWith('MATCH (n:Document) WHERE (n.deleted_at IS NULL) WITH n,', $builder->toSql());
        self::assertStringEndsWith('LIMIT 15', $builder->toSql());
    }

    public function testColumnAliasesAreProjectedOnTheIndexPath(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->select('title as heading')
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.8)
            ->limit(3);

        self::assertStringContainsString('RETURN n.title AS heading, score ORDER BY score DESC LIMIT 3', $builder->toSql());
    }

    public function testWildcardSelectionReturnsTheWholeNodeWithScore(): void
    {
        foreach (['*', 'Document.*'] as $columns) {
            $builder = $this->vectorBuilder()
                ->from('Document')
                ->select($columns)
                ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.8)
                ->limit(3);

            self::assertStringContainsString('RETURN n, score ORDER BY score DESC LIMIT 3', $builder->toSql(), $columns);
        }
    }

    public function testDistinctSelectionIsKeptOnVectorQueries(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->distinct()
            ->select('title')
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.8)
            ->limit(3);

        self::assertStringContainsString('RETURN DISTINCT n.title, score', $builder->toSql());
    }

    public function testEuclideanFunctionIsUsedForScanScoring(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document')
            ->where('active', true)
            ->useVectorSimilarityFunction('euclidean')
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.8)
            ->limit(3);

        self::assertStringContainsString('vector.similarity.euclidean(n.embedding, $p1)', $builder->toSql());
        self::assertStringNotContainsString('vector.similarity.cosine', $builder->toSql());
    }

    public function testRejectsUnsupportedVectorSimilarityFunction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported vector similarity function: dot');

        $this->vectorBuilder()->useVectorSimilarityFunction('dot');
    }

    public function testVectorIndexNameIgnoresFromAlias(): void
    {
        $builder = $this->vectorBuilder()
            ->from('Document as d')
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.8)
            ->limit(3);

        self::assertStringContainsString('queryNodes(\'document_embedding\', 3, $p0)', $builder->toSql());
    }

    public function testRejectsJoinCombinedWithVectorSimilarity(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Joins cannot be combined with whereVectorSimilarTo()');

        $this->vectorBuilder()
            ->from('Document')
            ->join('Author', 'Document.author_id', '=', 'Author.id')
            ->whereVectorSimilarTo('embedding', [0.1, 0.2, 0.3], 0.8)
            ->limit(3)
            ->toSql();
    }

    public function testCompilesCountAndExists(): void
    {
        $builder = $this->builder()->from('User')->where('active', true);
        $builder->aggregate = ['function' => 'count', 'columns' => ['*']];

        self::assertSame(
            'MATCH (n:User) WHERE (n.active = $p0) RETURN count(n) AS aggregate',
            $builder->toSql()
        );

        $exists = $this->builder()->from('User')->where('name', 'Pratiksha');
        $grammar = new Neo4jQueryGrammar();

        self::assertSame(
            'MATCH (n:User) WHERE (n.name = $p0) RETURN true AS exists LIMIT 1',
            $grammar->compileExists($exists)
        );
    }

    public function testCompilesAggregatesSumAvgMinMax(): void
    {
        foreach (['sum', 'avg', 'min', 'max'] as $function) {
            $builder = $this->builder()->from('User')->where('active', true);
            $builder->aggregate = ['function' => $function, 'columns' => ['score']];

            self::assertSame(
                "MATCH (n:User) WHERE (n.active = \$p0) RETURN {$function}(n.score) AS aggregate",
                $builder->toSql()
            );
        }
    }

    public function testPassesUnknownAggregatesThroughToCypher(): void
    {
        $builder = $this->builder()->from('User');
        $builder->aggregate = ['function' => 'collect', 'columns' => ['name']];

        self::assertSame(
            'MATCH (n:User) RETURN collect(n.name) AS aggregate',
            $builder->toSql()
        );
    }

    public function testCompilesWhereColumnAndWhereRaw(): void
    {
        $builder = $this->builder()
            ->from('User')
            ->whereColumn('first_name', 'last_name')
            ->whereRaw('n.age > ?', [21]);

        self::assertSame(
            'MATCH (n:User) WHERE (n.first_name = n.last_name AND n.age > $p0) RETURN n',
            $builder->toSql()
        );
        self::assertSame([21], $builder->getBindings());
    }

    public function testCompilesDateWheres(): void
    {
        $builder = $this->builder()
            ->from('User')
            ->whereDate('created_at', '2026-08-31')
            ->whereYear('created_at', 2026);

        self::assertSame(
            "MATCH (n:User) WHERE (date(datetime(replace(toString(n.created_at), ' ', 'T'))) = date(\$p0) "
                ."AND datetime(replace(toString(n.created_at), ' ', 'T')).year = toInteger(\$p1)) RETURN n",
            $builder->toSql()
        );
    }

    public function testCompilesGroupByHaving(): void
    {
        $builder = $this->builder()
            ->from('User')
            ->select('status')
            ->groupBy('status')
            ->having('status', 'active');

        self::assertSame(
            'MATCH (n:User) WITH DISTINCT n.status AS status WHERE status = $p0 RETURN status',
            $builder->toSql()
        );
    }

    public function testCompilesAggregateWithGroupBy(): void
    {
        $builder = $this->builder()->from('User')->groupBy('status');
        $builder->aggregate = ['function' => 'count', 'columns' => ['*']];

        self::assertSame(
            'MATCH (n:User) WITH n.status AS status, count(n) AS aggregate RETURN status, aggregate',
            $builder->toSql()
        );
    }

    public function testCompilesIncrementUpdateExpressions(): void
    {
        $grammar = new Neo4jQueryGrammar();
        $builder = $this->builder()
            ->from('User')
            ->where('id', 'user-1');

        $sql = $grammar->compileUpdate($builder, [
            'votes' => new \Illuminate\Database\Query\Expression('n.votes + 1'),
            'name' => 'Pratiksha',
        ]);

        self::assertSame(
            'MATCH (n:User) WHERE (n.id = $p1) SET n.votes = n.votes + 1, n.name = $p0',
            $sql
        );
    }

    public function testCompilesInnerJoinAsCartesianMatchWithWhere(): void
    {
        $builder = $this->builder()
            ->from('Role')
            ->join('RoleUser', 'Role.id', '=', 'RoleUser.role_id')
            ->where('RoleUser.user_id', 'user-1')
            ->select([
                'Role.*',
                'RoleUser.user_id as pivot_user_id',
                'RoleUser.role_id as pivot_role_id',
            ]);

        self::assertSame(
            'MATCH (n:Role), (RoleUser:RoleUser) WHERE (n.id = RoleUser.role_id AND (RoleUser.user_id = $p0)) '
                .'RETURN n, RoleUser.user_id AS pivot_user_id, RoleUser.role_id AS pivot_role_id',
            $builder->toSql()
        );
        self::assertSame(['user-1'], $builder->getBindings());
    }

    public function testCompilesFromTableAlias(): void
    {
        $builder = $this->builder()
            ->from('User as u')
            ->where('u.name', 'Ada')
            ->select(['u.name']);

        self::assertSame(
            'MATCH (n:User) WHERE (n.name = $p0) RETURN n.name',
            $builder->toSql()
        );
        self::assertSame(['Ada'], $builder->getBindings());
    }

    public function testCompilesJoinTableAlias(): void
    {
        $builder = $this->builder()
            ->from('User as u')
            ->join('RoleUser as ru', 'u.id', '=', 'ru.user_id')
            ->where('ru.role_id', 'role-1')
            ->select(['u.name', 'ru.role_id']);

        self::assertSame(
            'MATCH (n:User), (ru:RoleUser) WHERE (n.id = ru.user_id AND (ru.role_id = $p0)) '
                .'RETURN n.name, ru.role_id',
            $builder->toSql()
        );
        self::assertSame(['role-1'], $builder->getBindings());
    }

    public function testCompilesJoinAggregatesAndExists(): void
    {
        $count = $this->builder()
            ->from('Role')
            ->join('RoleUser', 'Role.id', '=', 'RoleUser.role_id')
            ->where('RoleUser.user_id', 'user-1');
        $count->aggregate = ['function' => 'count', 'columns' => ['*']];

        self::assertSame(
            'MATCH (n:Role), (RoleUser:RoleUser) WHERE (n.id = RoleUser.role_id AND (RoleUser.user_id = $p0)) '
                .'RETURN count(n) AS aggregate',
            $count->toSql()
        );

        $exists = $this->builder()
            ->from('Role')
            ->join('RoleUser', 'Role.id', '=', 'RoleUser.role_id')
            ->where('RoleUser.user_id', 'user-1');

        self::assertSame(
            'MATCH (n:Role), (RoleUser:RoleUser) WHERE (n.id = RoleUser.role_id AND (RoleUser.user_id = $p0)) '
                .'RETURN true AS exists LIMIT 1',
            (new Neo4jQueryGrammar())->compileExists($exists)
        );
    }

    public function testRejectsUnsupportedJoinTypes(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->builder()
            ->from('Role')
            ->leftJoin('RoleUser', 'Role.id', '=', 'RoleUser.role_id')
            ->toSql();
    }

    public function testCompilesHasManyThroughStyleJoinAndThroughKeyAlias(): void
    {
        $builder = $this->builder()
            ->from('Post')
            ->join('User', 'User.id', '=', 'Post.user_id')
            ->where('User.country_id', 'country-1')
            ->select([
                'Post.*',
                'User.country_id as laravel_through_key',
            ]);

        self::assertSame(
            'MATCH (n:Post), (User:User) WHERE (User.id = n.user_id AND (User.country_id = $p0)) '
                .'RETURN n, User.country_id AS laravel_through_key',
            $builder->toSql()
        );
        self::assertSame(['country-1'], $builder->getBindings());
    }

    public function testCompilesMorphToManyStyleJoinWithTypeConstraint(): void
    {
        $builder = $this->builder()
            ->from('Tag')
            ->join('Taggable', 'Tag.id', '=', 'Taggable.tag_id')
            ->where('Taggable.taggable_id', 'post-1')
            ->where('Taggable.taggable_type', 'Post')
            ->select([
                'Tag.*',
                'Taggable.taggable_id as pivot_taggable_id',
                'Taggable.tag_id as pivot_tag_id',
                'Taggable.taggable_type as pivot_taggable_type',
            ]);

        self::assertSame(
            'MATCH (n:Tag), (Taggable:Taggable) WHERE ((n.id = Taggable.tag_id AND (Taggable.taggable_id = $p0)) '
                .'AND (Taggable.taggable_type = $p1)) '
                .'RETURN n, Taggable.taggable_id AS pivot_taggable_id, Taggable.tag_id AS pivot_tag_id, '
                .'Taggable.taggable_type AS pivot_taggable_type',
            $builder->toSql()
        );
        self::assertSame(['post-1', 'Post'], $builder->getBindings());
    }

    public function testCompilesWhereInSubquery(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('User')
            ->whereIn('id', function (Builder $query): void {
                $query->select('user_id')->from('Post');
            });

        self::assertSame(
            'MATCH (n:User) WHERE n.id IN COLLECT { MATCH (Post:Post) RETURN Post.user_id } RETURN n',
            $builder->toSql()
        );
        self::assertSame([], $builder->getBindings());
    }

    public function testCompilesWhereNotInSubquery(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('User')
            ->whereNotIn('id', function (Builder $query): void {
                $query->select('user_id')->from('Post');
            });

        self::assertSame(
            'MATCH (n:User) WHERE NOT (n.id IN COLLECT { MATCH (Post:Post) RETURN Post.user_id }) RETURN n',
            $builder->toSql()
        );
        self::assertSame([], $builder->getBindings());
    }

    public function testCompilesWhereExistsSubquery(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('User')
            ->whereExists(function (Builder $query): void {
                $query->from('Post')
                    ->whereColumn('Post.user_id', 'User.id')
                    ->where('published', true);
            });

        self::assertSame(
            'MATCH (n:User) WHERE EXISTS { MATCH (Post:Post) '
                .'WHERE (Post.user_id = n.id AND (Post.published = $p0)) } RETURN n',
            $builder->toSql()
        );
        self::assertSame([true], $builder->getBindings());
    }

    public function testCompilesWhereNotExistsSubquery(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('User')
            ->whereNotExists(function (Builder $query): void {
                $query->from('Post')
                    ->whereColumn('Post.user_id', 'User.id');
            });

        self::assertSame(
            'MATCH (n:User) WHERE NOT EXISTS { MATCH (Post:Post) WHERE Post.user_id = n.id } RETURN n',
            $builder->toSql()
        );
        self::assertSame([], $builder->getBindings());
    }

    public function testWhereInSubqueryKeepsOuterCorrelation(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('User')
            ->whereIn('id', function (Builder $query): void {
                $query->select('user_id')
                    ->from('Post')
                    ->whereColumn('Post.owner_id', 'User.id');
            });

        self::assertSame(
            'MATCH (n:User) WHERE n.id IN COLLECT { MATCH (Post:Post) WHERE Post.owner_id = n.id RETURN Post.user_id } RETURN n',
            $builder->toSql()
        );
    }

    public function testWhereInWithQueryBuilderUsesInSubNotExpression(): void
    {
        $outer = $this->neo4jBuilder()->from('User');
        $inner = $this->neo4jBuilder()->from('Post')->select('user_id');

        $outer->whereIn('id', $inner);

        self::assertSame('InSub', $outer->wheres[0]['type']);
        self::assertInstanceOf(Builder::class, $outer->wheres[0]['query']);
    }

    public function testStockBuilderWhereInSubqueryRejectsCompiledExpression(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Compiled whereIn subquery expressions are not supported');

        $this->builder()
            ->from('User')
            ->whereIn('id', function (Builder $query): void {
                $query->select('user_id')->from('Post');
            })
            ->toSql();
    }

    public function testExistsSubqueryDoesNotReuseOuterJoinAliasSq(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('User')
            ->join('Audit as sq', 'User.id', '=', 'sq.user_id')
            ->whereExists(function (Builder $query): void {
                $query->from('Post as n')
                    ->whereColumn('n.user_id', 'User.id');
            });

        $cypher = $builder->toSql();

        self::assertStringContainsString('MATCH (n:User), (sq:Audit)', $cypher);
        self::assertDoesNotMatchRegularExpression('/EXISTS \{ MATCH \(sq:/', $cypher);
        self::assertMatchesRegularExpression('/EXISTS \{ MATCH \(sq\d+:Post\)/', $cypher);
    }

    public function testWhereInClosureAndBuilderCompileTheSame(): void
    {
        $viaClosure = $this->neo4jBuilder()
            ->from('User')
            ->whereIn('id', function (Builder $query): void {
                $query->select('user_id')->from('Post')->where('published', true);
            });

        $viaBuilder = $this->neo4jBuilder()
            ->from('User')
            ->whereIn(
                'id',
                $this->neo4jBuilder()->from('Post')->select('user_id')->where('published', true)
            );

        self::assertSame($viaClosure->toSql(), $viaBuilder->toSql());
        self::assertSame($viaClosure->getBindings(), $viaBuilder->getBindings());
    }

    public function testCompilesUnionQueries(): void
    {
        $first = $this->builder()->from('User')->where('role', 'admin')->select('name');
        $second = $this->builder()->from('User')->where('role', 'editor')->select('name');
        $first->union($second);

        self::assertSame(
            'MATCH (n:User) WHERE (n.role = $p0) RETURN n.name '
                .'UNION MATCH (n:User) WHERE (n.role = $p1) RETURN n.name',
            $first->toSql()
        );
        self::assertSame(['admin', 'editor'], $first->getBindings());
    }

    public function testCompilesMatchRelationshipAsGraphPattern(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('Bar')
            ->matchRelationship('Bar2', 'Foo');

        self::assertSame(
            'MATCH (n:Bar)-[bar2:Bar2]-(foo:Foo) RETURN n',
            $builder->toSql()
        );
    }

    public function testCompilesMatchRelationshipWithRelationshipAndRelatedWheres(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('Bar')
            ->matchRelationship('Bar2', 'Foo')
            ->where('bar2.status', 'active')
            ->where('foo.name', 'Ada');

        self::assertSame(
            'MATCH (n:Bar)-[bar2:Bar2]-(foo:Foo) WHERE ((bar2.status = $p0) AND (foo.name = $p1)) '
                .'RETURN n',
            $builder->toSql()
        );
        self::assertSame(['active', 'Ada'], $builder->getBindings());
    }

    public function testCompilesMatchRelationshipQualifiedByTypeAndLabel(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('Bar')
            ->matchRelationship('Bar2', 'Foo')
            ->where('Bar2.status', 'active')
            ->where('Foo.name', 'Ada');

        self::assertSame(
            'MATCH (n:Bar)-[bar2:Bar2]-(foo:Foo) WHERE ((bar2.status = $p0) AND (foo.name = $p1)) '
                .'RETURN n',
            $builder->toSql()
        );
    }

    public function testCompilesMatchRelationshipWithCustomAliases(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('Person')
            ->matchRelationship('ACTED_IN', 'Movie', 'role', 'film')
            ->where('role.roles', 'Neo')
            ->select(['n', 'role', 'film']);

        self::assertSame(
            'MATCH (n:Person)-[role:ACTED_IN]-(film:Movie) WHERE (role.roles = $p0) '
                .'RETURN n, role, film',
            $builder->toSql()
        );
        self::assertSame(['Neo'], $builder->getBindings());
    }

    public function testRejectsMatchRelationshipWhenAliasCollidesWithEarlierPattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already used');

        $this->neo4jBuilder()
            ->from('Person')
            ->matchRelationship('ACTED_IN>', 'Movie')
            ->matchRelationship('DIRECTED>', 'Movie');
    }

    public function testCompilesMultipleMatchRelationships(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('Person')
            ->matchRelationship('ACTED_IN>', 'Movie', 'acted', 'movie')
            ->matchRelationship('DIRECTED>', 'Movie', 'directed', 'directedMovie')
            ->select(['n', 'acted', 'movie', 'directed', 'directedMovie']);

        self::assertSame(
            'MATCH (n:Person)-[acted:ACTED_IN]->(movie:Movie), '
                .'(n)-[directed:DIRECTED]->(directedMovie:Movie) '
                .'RETURN n, acted, movie, directed, directedMovie',
            $builder->toSql()
        );
    }

    public function testRejectsMatchRelationshipCombinedWithJoin(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->neo4jBuilder()
            ->from('Bar')
            ->matchRelationship('Bar2', 'Foo')
            ->join('RoleUser', 'Bar.id', '=', 'RoleUser.bar_id')
            ->toSql();
    }

    public function testCompilesMatchRelationshipExistsAndAggregate(): void
    {
        $exists = $this->neo4jBuilder()
            ->from('Bar')
            ->matchRelationship('Bar2', 'Foo')
            ->where('bar2.status', 'active');

        self::assertSame(
            'MATCH (n:Bar)-[bar2:Bar2]-(foo:Foo) WHERE (bar2.status = $p0) RETURN true AS exists LIMIT 1',
            (new Neo4jQueryGrammar())->compileExists($exists)
        );

        $count = $this->neo4jBuilder()
            ->from('Bar')
            ->matchRelationship('Bar2', 'Foo')
            ->where('foo.name', 'Ada');
        $count->aggregate = ['function' => 'count', 'columns' => ['*']];

        // With a relationship MATCH, count(n) counts paths (not distinct nodes).
        self::assertSame(
            'MATCH (n:Bar)-[bar2:Bar2]-(foo:Foo) WHERE (foo.name = $p0) RETURN count(n) AS aggregate',
            $count->toSql()
        );
    }

    public function testCompilesMatchRelationshipOutgoingDirection(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('Foo')
            ->matchRelationship('Baz>', 'Bar');

        self::assertSame(
            'MATCH (n:Foo)-[baz:Baz]->(bar:Bar) RETURN n',
            $builder->toSql()
        );
    }

    public function testCompilesMatchRelationshipIncomingDirection(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('Foo')
            ->matchRelationship('<Baz', 'Bar');

        self::assertSame(
            'MATCH (n:Foo)<-[baz:Baz]-(bar:Bar) RETURN n',
            $builder->toSql()
        );
    }

    public function testCompilesMatchRelationshipDirectionFromWhiteboardStyleType(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('Foo')
            ->matchRelationship(':Baz >', 'Bar');

        self::assertSame(
            'MATCH (n:Foo)-[baz:Baz]->(bar:Bar) RETURN n',
            $builder->toSql()
        );
    }

    public function testCompilesMatchRelationshipWithRelatedClosureConstraints(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('Foo')
            ->matchRelationship('Baz>', 'Bar', function ($query): void {
                $query->where('x', 0)->where('y', 1);
            });

        self::assertSame(
            'MATCH (n:Foo)-[baz:Baz]->(bar:Bar) WHERE ((bar.x = $p0) AND (bar.y = $p1)) '
                .'RETURN n',
            $builder->toSql()
        );
        self::assertSame([0, 1], $builder->getBindings());
    }

    public function testCompilesMatchRelationshipWithAliasesAndClosure(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('Foo')
            ->matchRelationship('Baz>', 'Bar', 'edge', 'node', function ($query): void {
                $query->where('x', 0);
            });

        self::assertSame(
            'MATCH (n:Foo)-[edge:Baz]->(node:Bar) WHERE (node.x = $p0) RETURN n',
            $builder->toSql()
        );
        self::assertSame([0], $builder->getBindings());
    }

    public function testMatchRelationshipSameLabelKeepsPrimaryVariableMapping(): void
    {
        $builder = $this->neo4jBuilder()
            ->from('Foo')
            ->matchRelationship('KNOWS>', 'Foo', 'knows', 'friend')
            ->where('Foo.name', 'Ada')
            ->where('friend.name', 'Bob');

        self::assertSame(
            'MATCH (n:Foo)-[knows:KNOWS]->(friend:Foo) WHERE ((n.name = $p0) AND (friend.name = $p1)) '
                .'RETURN n',
            $builder->toSql()
        );
        self::assertSame(['Ada', 'Bob'], $builder->getBindings());
    }

    public function testCompilesInsertRelationshipOutgoing(): void
    {
        $grammar = new Neo4jQueryGrammar();
        $builder = $this->neo4jBuilder()->from('Person');

        $sql = $grammar->compileInsertRelationship($builder, [
            'type' => 'ACTED_IN',
            'related' => 'Movie',
            'relationship' => 'acted_in',
            'relatedAlias' => 'movie',
            'direction' => 'out',
            'fromColumns' => ['id'],
            'toColumns' => ['id'],
            'propertyColumns' => ['roles'],
        ]);

        self::assertSame(
            'MATCH (n:Person {id: $p0}), (movie:Movie {id: $p1}) '
                .'CREATE (n)-[acted_in:ACTED_IN {roles: $p2}]->(movie)',
            $sql
        );
    }

    public function testCompilesInsertRelationshipIncoming(): void
    {
        $grammar = new Neo4jQueryGrammar();
        $builder = $this->neo4jBuilder()->from('Movie');

        $sql = $grammar->compileInsertRelationship($builder, [
            'type' => 'ACTED_IN',
            'related' => 'Person',
            'relationship' => 'acted_in',
            'relatedAlias' => 'person',
            'direction' => 'in',
            'fromColumns' => ['id'],
            'toColumns' => ['id'],
            'propertyColumns' => [],
        ]);

        self::assertSame(
            'MATCH (n:Movie {id: $p0}), (person:Person {id: $p1}) '
                .'CREATE (n)<-[acted_in:ACTED_IN]-(person)',
            $sql
        );
    }

    public function testInsertRelationshipRequiresDirectedType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a directed type');

        $this->neo4jBuilder()
            ->from('Person')
            ->insertRelationship('ACTED_IN', 'Movie', ['id' => 1], ['id' => 2]);
    }

    public function testRejectsMatchRelationshipCombinedWithUpdate(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Updates with matchRelationship()');

        $builder = $this->neo4jBuilder()
            ->from('Bar')
            ->matchRelationship('Bar2', 'Foo')
            ->where('id', 1);

        (new Neo4jQueryGrammar())->compileUpdate($builder, ['status' => 'x']);
    }

    public function testRejectsMatchRelationshipCombinedWithDelete(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Deletes with matchRelationship()');

        $builder = $this->neo4jBuilder()
            ->from('Bar')
            ->matchRelationship('Bar2', 'Foo');

        (new Neo4jQueryGrammar())->compileDelete($builder);
    }

    public function testRejectsMatchRelationshipCombinedWithVectorSimilarity(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('matchRelationship() cannot be combined with whereVectorSimilarTo()');

        $this->neo4jBuilder()
            ->from('Movie')
            ->matchRelationship('SIMILAR_TO>', 'Movie')
            ->whereVectorSimilarTo('embedding', [0.1, 0.2], 0.5)
            ->toSql();
    }

    public function testRejectsMatchRelationshipWhenDefaultAliasesCollide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('aliases collide');

        $this->neo4jBuilder()
            ->from('Person')
            ->matchRelationship('FRIEND', 'Friend');
    }

    public function testRejectsMatchRelationshipWhenAliasIsReservedN(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be "n"');

        $this->neo4jBuilder()
            ->from('Person')
            ->matchRelationship('KNOWS>', 'Person', 'knows', 'n');
    }

    public function testRejectsMatchRelationshipClosureWithNonWhereClauses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('may only add WHERE constraints');

        $this->neo4jBuilder()
            ->from('Foo')
            ->matchRelationship('Baz>', 'Bar', function ($query): void {
                $query->where('x', 0)->orderBy('x');
            });
    }

    public function testInsertRelationshipRequiresNonEmptyKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty from and to property maps');

        $this->neo4jBuilder()
            ->from('Person')
            ->insertRelationship('ACTED_IN>', 'Movie', [], ['id' => 2]);
    }

    public function testRejectsInsertRelationshipCombinedWithMatchRelationship(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be combined with matchRelationship()');

        $this->neo4jBuilder()
            ->from('Person')
            ->matchRelationship('ACTED_IN>', 'Movie')
            ->insertRelationship('ACTED_IN>', 'Movie', ['id' => 1], ['id' => 2]);
    }

    public function testRejectsInsertRelationshipWhenDefaultAliasesCollide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('aliases collide');

        $this->neo4jBuilder()
            ->from('Person')
            ->insertRelationship('FRIEND>', 'Friend', ['id' => 1], ['id' => 2]);
    }

    private function vectorBuilder(): Neo4jQueryBuilder
    {
        return new Neo4jQueryBuilder(
            $this->createMock(ConnectionInterface::class),
            new Neo4jQueryGrammar(),
            new Processor()
        );
    }

    private function neo4jBuilder(): Neo4jQueryBuilder
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        {
        return new Neo4jQueryBuilder(
            $this->createMock(ConnectionInterface::class),
            new Neo4jQueryGrammar(),
            new Processor()
        );
    }

    /**
     * @return list<mixed>
     */
    private function vectorBindings(Neo4jQueryBuilder $builder): array
    {
        return array_map(
            static fn (mixed $value): mixed => $value instanceof VectorBinding ? $value->values : $value,
            $builder->getBindings()
        );
    }

    private function builder(): Builder
    {
        return new Builder(
            $this->createMock(ConnectionInterface::class),
            new Neo4jQueryGrammar(),
            new Processor()
        );
    }
}
