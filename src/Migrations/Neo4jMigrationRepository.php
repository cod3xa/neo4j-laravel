<?php

namespace Neo4j\Neo4jLaravel\Migrations;

use Illuminate\Database\ConnectionResolverInterface as Resolver;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use RuntimeException;

/**
 * Neo4j-backed migration history that reuses the package query builder for CRUD.
 *
 * Repository bootstrap (create / exists / delete) uses a uniqueness constraint on
 * :_LaravelMigration(migration). History rows are normal nodes managed via
 * {@see Neo4jConnection::table()}.
 *
 * @api
 */
final class Neo4jMigrationRepository implements MigrationRepositoryInterface
{
    public const LABEL = '_LaravelMigration';

    public const UNIQUE_CONSTRAINT = '_laravel_migration_migration_unique';

    /**
     * @var string|null
     */
    protected $connection;

    public function __construct(
        protected Resolver $resolver,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getRan()
    {
        return $this->table()
            ->orderBy('batch', 'asc')
            ->orderBy('migration', 'asc')
            ->pluck('migration')
            ->all();
    }

    /**
     * @param  int  $steps
     * @return list<object{migration: string, batch: int}>
     */
    public function getMigrations($steps)
    {
        return $this->mapRows($this->table()
            ->where('batch', '>=', 1)
            ->orderBy('batch', 'desc')
            ->orderBy('migration', 'desc')
            ->take($steps)
            ->get()
            ->all());
    }

    /**
     * @param  int  $batch
     * @return list<object{migration: string, batch: int}>
     */
    public function getMigrationsByBatch($batch)
    {
        return $this->mapRows($this->table()
            ->where('batch', $batch)
            ->orderBy('migration', 'desc')
            ->get()
            ->all());
    }

    /**
     * @return list<object{migration: string, batch: int}>
     */
    public function getLast()
    {
        return $this->mapRows($this->table()
            ->where('batch', $this->getLastBatchNumber())
            ->orderBy('migration', 'desc')
            ->get()
            ->all());
    }

    /**
     * @return array<string, int>
     */
    public function getMigrationBatches()
    {
        return $this->table()
            ->orderBy('batch', 'asc')
            ->orderBy('migration', 'asc')
            ->pluck('batch', 'migration')
            ->all();
    }

    /**
     * @param  string  $file
     * @param  int  $batch
     */
    public function log($file, $batch)
    {
        $this->table()->insert([
            'migration' => $file,
            'batch' => (int) $batch,
            'migrated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  object{migration: string}  $migration
     */
    public function delete($migration)
    {
        $this->table()->where('migration', $migration->migration)->delete();
    }

    /**
     * @return int
     */
    public function getNextBatchNumber()
    {
        return $this->getLastBatchNumber() + 1;
    }

    /**
     * @return int
     */
    public function getLastBatchNumber()
    {
        return (int) ($this->table()->max('batch') ?? 0);
    }

    public function createRepository()
    {
        $this->connection()->statement(sprintf(
            'CREATE CONSTRAINT %s IF NOT EXISTS FOR (m:%s) REQUIRE m.migration IS UNIQUE',
            self::UNIQUE_CONSTRAINT,
            self::LABEL
        ));
    }

    public function repositoryExists()
    {
        // SHOW CONSTRAINTS does not reliably accept query parameters on all Neo4j 5 builds.
        $rows = $this->connection()->select('SHOW CONSTRAINTS YIELD name RETURN name AS name');

        foreach ($rows as $row) {
            if (($row->name ?? null) === self::UNIQUE_CONSTRAINT) {
                return true;
            }
        }

        return false;
    }

    public function deleteRepository()
    {
        $this->table()->delete();

        $this->connection()->statement(sprintf(
            'DROP CONSTRAINT %s IF EXISTS',
            self::UNIQUE_CONSTRAINT
        ));
    }

    /**
     * @param  string  $name
     */
    public function setSource($name)
    {
        $this->connection = $name;
    }

    protected function table()
    {
        return $this->connection()->table(self::LABEL)->useWritePdo();
    }

    /**
     * Neo4j query-builder rows are associative arrays; Laravel's Migrator expects objects.
     *
     * @param  list<array<string, mixed>|object>  $rows
     * @return list<object>
     */
    private function mapRows(array $rows): array
    {
        return array_map(
            static fn (array|object $row): object => is_object($row) ? $row : (object) $row,
            $rows
        );
    }

    protected function connection(): Neo4jConnection
    {
        $connection = $this->resolver->connection($this->connection);

        if (! $connection instanceof Neo4jConnection) {
            throw new RuntimeException(sprintf(
                'Neo4jMigrationRepository requires a neo4j connection, got [%s].',
                $connection->getName() ?? 'unknown'
            ));
        }

        return $connection;
    }
}
