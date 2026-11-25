<?php

namespace Neo4j\Neo4jLaravel\Migrations;

use Illuminate\Database\ConnectionResolverInterface as Resolver;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Neo4j\Neo4jLaravel\Neo4jConnection;

/**
 * Delegates to Laravel's SQL migration repository or {@see Neo4jMigrationRepository}
 * based on the active connection driver after {@see setSource()}.
 *
 * @api
 */
final class ConnectionAwareMigrationRepository implements MigrationRepositoryInterface
{
    /**
     * @var string|null
     */
    private $connection;

    public function __construct(
        private readonly MigrationRepositoryInterface $relational,
        private readonly MigrationRepositoryInterface $neo4j,
        private readonly Resolver $resolver,
    ) {
    }

    public function getRan()
    {
        return $this->repository()->getRan();
    }

    public function getMigrations($steps)
    {
        return $this->repository()->getMigrations($steps);
    }

    public function getMigrationsByBatch($batch)
    {
        return $this->repository()->getMigrationsByBatch($batch);
    }

    public function getLast()
    {
        return $this->repository()->getLast();
    }

    public function getMigrationBatches()
    {
        return $this->repository()->getMigrationBatches();
    }

    public function log($file, $batch)
    {
        $this->repository()->log($file, $batch);
    }

    public function delete($migration)
    {
        $this->repository()->delete($migration);
    }

    public function getNextBatchNumber()
    {
        return $this->repository()->getNextBatchNumber();
    }

    public function createRepository()
    {
        $this->repository()->createRepository();
    }

    public function repositoryExists()
    {
        return $this->repository()->repositoryExists();
    }

    public function deleteRepository()
    {
        $this->repository()->deleteRepository();
    }

    public function setSource($name)
    {
        $this->connection = $name;
        $this->relational->setSource($name);
        $this->neo4j->setSource($name);
    }

    private function repository(): MigrationRepositoryInterface
    {
        $connection = $this->resolver->connection($this->connection);

        if ($connection instanceof Neo4jConnection || ($connection->getConfig('driver') ?? null) === 'neo4j') {
            return $this->neo4j;
        }

        return $this->relational;
    }
}
