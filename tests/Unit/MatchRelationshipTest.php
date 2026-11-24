<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit;

use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;
use Neo4j\Neo4jLaravel\Tests\TestCase;
use RuntimeException;

final class MatchRelationshipTest extends TestCase
{
    public function testCompilesLazyMatchRelationshipFromRelatedSide(): void
    {
        $person = new ErPerson(['id' => 'person-1']);
        $person->exists = true;

        $sql = $person->movies()->toBase()->toSql();

        self::assertSame(
            'MATCH (n:ErMovie)<-[neo4j_rel:ACTED_IN]-(neo4j_parent:ErPerson) '
                .'WHERE (neo4j_parent.id = $p0) RETURN n',
            $sql
        );
        self::assertSame(['person-1'], $person->movies()->toBase()->getBindings());
    }

    public function testCompilesAdditionalConstraintsOnRelatedNode(): void
    {
        $person = new ErPerson(['id' => 'person-1']);
        $person->exists = true;

        $builder = $person->movies()->where('title', 'The Matrix')->toBase();

        self::assertSame(
            'MATCH (n:ErMovie)<-[neo4j_rel:ACTED_IN]-(neo4j_parent:ErPerson) '
                .'WHERE ((neo4j_parent.id = $p0) AND (n.title = $p1)) RETURN n',
            $builder->toSql()
        );
        self::assertSame(['person-1', 'The Matrix'], $builder->getBindings());
    }

    public function testCompilesEagerMatchRelationshipWithParentKeyProjection(): void
    {
        $person = new ErPerson(['id' => 'person-1']);
        $other = new ErPerson(['id' => 'person-2']);

        $relation = MatchRelationship::noConstraints(
            fn () => $person->movies()
        );
        $relation->addEagerConstraints([$person, $other]);

        $builder = $relation->toBase();

        self::assertSame(
            'MATCH (n:ErMovie)<-[neo4j_rel:ACTED_IN]-(neo4j_parent:ErPerson) '
                .'WHERE (neo4j_parent.id IN [$p0, $p1]) '
                .'RETURN n, neo4j_parent.id AS neo4j_parent_key',
            $builder->toSql()
        );
        self::assertSame(['person-1', 'person-2'], $builder->getBindings());
    }

    public function testInvertsIncomingRelationshipTypeForRelatedQuery(): void
    {
        $movie = new ErMovie(['id' => 'movie-1']);
        $movie->exists = true;

        $sql = $movie->actors()->toBase()->toSql();

        self::assertSame(
            'MATCH (n:ErPerson)-[neo4j_rel:ACTED_IN]->(neo4j_parent:ErMovie) '
                .'WHERE (neo4j_parent.id = $p0) RETURN n',
            $sql
        );
    }

    public function testWhereHasCompilesCorrelatedMatchRelationshipExists(): void
    {
        $sql = ErPerson::query()->whereHas('movies')->toSql();

        self::assertSame(
            'MATCH (n:ErPerson) WHERE EXISTS { '
                .'MATCH (n)-[exists_rel:ACTED_IN]->(exists_related:ErMovie) '
               .'} RETURN n',
            $sql
        );
    }

    public function testWhereHasCallbackConstraintsRelatedNode(): void
    {
        $builder = ErPerson::query()->whereHas('movies', function ($query): void {
            $query->where('title', 'The Matrix');
        });

        self::assertSame(
            'MATCH (n:ErPerson) WHERE EXISTS { '
                .'MATCH (n)-[exists_rel:ACTED_IN]->(exists_related:ErMovie) '
               .'WHERE (exists_related.title = $p0) '
               .'} RETURN n',
            $builder->toSql()
        );
        self::assertSame(['The Matrix'], $builder->getBindings());
    }

    public function testWhereHasIncomingRelationship(): void
    {
        $sql = ErMovie::query()->whereHas('actors')->toSql();

        self::assertSame(
            'MATCH (n:ErMovie) WHERE EXISTS { '
                .'MATCH (n)<-[exists_rel:ACTED_IN]-(exists_related:ErPerson) '
               .'} RETURN n',
            $sql
        );
    }

    public function testWhereDoesntHaveCompilesNotExists(): void
    {
        $sql = ErPerson::query()->whereDoesntHave('movies')->toSql();

        self::assertSame(
            'MATCH (n:ErPerson) WHERE NOT EXISTS { '
                .'MATCH (n)-[exists_rel:ACTED_IN]->(exists_related:ErMovie) '
               .'} RETURN n',
            $sql
        );
    }

    public function testCountBasedHasIsNotSupported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Count-based has()');

        ErPerson::query()->has('movies', '>=', 2)->toSql();
    }

    public function testWithCountIsNotSupported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('withCount()');

        ErPerson::query()->withCount('movies')->toSql();
    }
}

final class ErPerson extends Neo4jModel
{
    protected $table = 'ErPerson';

    protected $guarded = [];

    public $timestamps = false;

    public function movies(): MatchRelationship
    {
        return $this->matchRelationship(ErMovie::class, 'ACTED_IN>');
    }
}

final class ErMovie extends Neo4jModel
{
    protected $table = 'ErMovie';

    protected $guarded = [];

    public $timestamps = false;

    public function actors(): MatchRelationship
    {
        return $this->matchRelationship(ErPerson::class, '<ACTED_IN');
    }
}
