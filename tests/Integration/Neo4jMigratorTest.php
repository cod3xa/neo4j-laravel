<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;
use Neo4j\Neo4jLaravel\Migrations\ConnectionAwareMigrationRepository;
use Neo4j\Neo4jLaravel\Migrations\Neo4jMigrationRepository;
use Neo4j\Neo4jLaravel\Tests\TestCase;

final class Neo4jMigratorTest extends TestCase
{
    private string $path;

    private Neo4jMigrationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/neo4j-laravel-migrations-'.uniqid('', true);
        mkdir($this->path);

        $this->repository = $this->app->make(Neo4jMigrationRepository::class);
        $this->repository->setSource('neo4j');

        if ($this->repository->repositoryExists()) {
            $this->repository->deleteRepository();
        }

        Schema::connection('neo4j')->dropConstraint('mig_person_email_unique');
        Schema::connection('neo4j')->dropIndex('mig_person_name_index');
    }

    protected function tearDown(): void
    {
        Schema::connection('neo4j')->dropConstraint('mig_person_email_unique');
        Schema::connection('neo4j')->dropIndex('mig_person_name_index');

        if ($this->repository->repositoryExists()) {
            $this->repository->deleteRepository();
        }

        foreach (glob($this->path.'/*.php') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->path);

        parent::tearDown();
    }

    public function testMigrationRepositoryBindingIsConnectionAware(): void
    {
        $repository = $this->app->make('migration.repository');

        self::assertInstanceOf(ConnectionAwareMigrationRepository::class, $repository);
    }

    public function testMigratorRunsStatusAndRollsBackUsingLaravelMigrator(): void
    {
        $this->writeMigration('2024_01_01_000000_create_person_schema', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('neo4j')->node('Person', function ($node) {
            $node->unique('email', 'mig_person_email_unique');
            $node->index('name', 'mig_person_name_index');
        });
    }

    public function down(): void
    {
        Schema::connection('neo4j')->dropConstraint('mig_person_email_unique');
        Schema::connection('neo4j')->dropIndex('mig_person_name_index');
    }
};
PHP);

        /** @var Migrator $migrator */
        $migrator = $this->app->make('migrator');
        $migrator->setConnection('neo4j');
        $migrator->path($this->path);

        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        self::assertSame([], $migrator->getRepository()->getRan());

        $migrator->run([$this->path]);

        self::assertSame(['2024_01_01_000000_create_person_schema'], $migrator->getRepository()->getRan());

        $constraintNames = array_map(
            static fn (object $row): string => (string) $row->name,
            $this->app->make('db')->connection('neo4j')->select('SHOW CONSTRAINTS YIELD name RETURN name AS name')
        );
        self::assertContains('mig_person_email_unique', $constraintNames);

        $migrator->rollback([$this->path]);

        self::assertSame([], $migrator->getRepository()->getRan());

        $constraintNames = array_map(
            static fn (object $row): string => (string) $row->name,
            $this->app->make('db')->connection('neo4j')->select('SHOW CONSTRAINTS YIELD name RETURN name AS name')
        );
        self::assertNotContains('mig_person_email_unique', $constraintNames);
    }

    private function writeMigration(string $name, string $contents): void
    {
        file_put_contents($this->path.'/'.$name.'.php', $contents);
    }
}
