<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

use Neo4j\Neo4jLaravel\Migrations\Neo4jMigrationRepository;
use Neo4j\Neo4jLaravel\Tests\TestCase;
use stdClass;

final class Neo4jMigrationRepositoryTest extends TestCase
{
    private Neo4jMigrationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(Neo4jMigrationRepository::class);
        $this->repository->setSource('neo4j');

        if ($this->repository->repositoryExists()) {
            $this->repository->deleteRepository();
        }
    }

    protected function tearDown(): void
    {
        if ($this->repository->repositoryExists()) {
            $this->repository->deleteRepository();
        }

        parent::tearDown();
    }

    public function testCreateAndDetectRepository(): void
    {
        self::assertFalse($this->repository->repositoryExists());

        $this->repository->createRepository();

        self::assertTrue($this->repository->repositoryExists());
    }

    public function testLogRetrieveBatchesAndDelete(): void
    {
        $this->repository->createRepository();

        $this->repository->log('2024_01_01_000000_one', 1);
        $this->repository->log('2024_01_02_000000_two', 1);
        $this->repository->log('2024_01_03_000000_three', 2);

        self::assertSame([
            '2024_01_01_000000_one',
            '2024_01_02_000000_two',
            '2024_01_03_000000_three',
        ], $this->repository->getRan());

        self::assertSame(2, $this->repository->getLastBatchNumber());
        self::assertSame(3, $this->repository->getNextBatchNumber());

        $batches = $this->repository->getMigrationBatches();
        self::assertSame(1, $batches['2024_01_01_000000_one']);
        self::assertSame(2, $batches['2024_01_03_000000_three']);

        $last = $this->repository->getLast();
        self::assertCount(1, $last);
        self::assertSame('2024_01_03_000000_three', $last[0]->migration);

        $byBatch = $this->repository->getMigrationsByBatch(1);
        self::assertCount(2, $byBatch);

        $migration = new stdClass();
        $migration->migration = '2024_01_03_000000_three';
        $this->repository->delete($migration);

        self::assertSame([
            '2024_01_01_000000_one',
            '2024_01_02_000000_two',
        ], $this->repository->getRan());
    }

    public function testDeleteRepositoryRemovesHistoryAndConstraint(): void
    {
        $this->repository->createRepository();
        $this->repository->log('2024_01_01_000000_one', 1);
        $this->repository->deleteRepository();

        self::assertFalse($this->repository->repositoryExists());
        self::assertSame([], $this->repository->getRan());
    }
}
