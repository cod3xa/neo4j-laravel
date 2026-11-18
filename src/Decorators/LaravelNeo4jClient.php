<?php

namespace Neo4j\Neo4jLaravel\Decorators;

use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\DriverInterface;
use Laudis\Neo4j\Contracts\TransactionInterface;
use Laudis\Neo4j\Contracts\UnmanagedTransactionInterface;
use Laudis\Neo4j\Databags\Statement;
use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Databags\TransactionConfiguration;
use Laudis\Neo4j\Types\CypherList;
use Neo4j\Neo4jLaravel\Debug\CapturingUnmanagedTransaction;
use Neo4j\Neo4jLaravel\Neo4jConnection;

/**
 * Wraps the Neo4j client so DI usage (Client / Driver / Session) still
 * records Cypher through {@see Neo4jConnection::executeCaptured()}.
 *
 * @internal
 */
final class LaravelNeo4jClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly Neo4jConnection $connection
    ) {
    }

    /**
     * @param iterable<string, mixed> $parameters
     */
    public function run(string $statement, iterable $parameters = [], ?string $alias = null): SummarizedResult
    {
        $bindings = ParameterBag::toArray($parameters);
        $alias ??= $this->connection->driverAlias();

        /** @var SummarizedResult */
        return $this->connection->executeCaptured(
            $statement,
            $bindings,
            function () use ($statement, $parameters, $alias): SummarizedResult {
                $transaction = $this->connectionTransaction($alias);
                if ($transaction !== null) {
                    return $transaction->run($statement, $parameters);
                }

                return $this->client->run($statement, $parameters, $alias);
            }
        );
    }

    public function runStatement(Statement $statement, ?string $alias = null): SummarizedResult
    {
        $bindings = ParameterBag::toArray($statement->getParameters());
        $alias ??= $this->connection->driverAlias();

        /** @var SummarizedResult */
        return $this->connection->executeCaptured(
            $statement->getText(),
            $bindings,
            function () use ($statement, $alias): SummarizedResult {
                $transaction = $this->connectionTransaction($alias);
                if ($transaction !== null) {
                    return $transaction->runStatement($statement);
                }

                return $this->client->runStatement($statement, $alias);
            }
        );
    }

    /**
     * @param iterable<Statement> $statements
     *
     * @return CypherList<SummarizedResult>
     */
    public function runStatements(iterable $statements, ?string $alias = null): CypherList
    {
        $results = [];
        foreach ($statements as $statement) {
            $results[] = $this->runStatement($statement, $alias);
        }

        return CypherList::fromIterable($results);
    }

    public function beginTransaction(?iterable $statements = null, ?string $alias = null, ?TransactionConfiguration $config = null): UnmanagedTransactionInterface
    {
        $alias ??= $this->connection->driverAlias();

        if ($alias === $this->connection->driverAlias()) {
            $tx = $this->connection->beginTransaction();

            if ($statements !== null) {
                foreach ($statements as $statement) {
                    $tx->runStatement($statement);
                }
            }

            return $tx;
        }

        $tx = new CapturingUnmanagedTransaction(
            $this->client->beginTransaction(null, $alias, $config),
            $this->connection
        );

        if ($statements !== null) {
            foreach ($statements as $statement) {
                $tx->runStatement($statement);
            }
        }

        return $tx;
    }

    public function getDriver(?string $alias): DriverInterface
    {
        $alias ??= $this->connection->driverAlias();

        return new LaravelNeo4jDriver(
            $this->client->getDriver($alias),
            $this->connection
        );
    }

    public function hasDriver(string $alias): bool
    {
        return $this->client->hasDriver($alias);
    }

    public function writeTransaction(callable $tsxHandler, ?string $alias = null, ?TransactionConfiguration $config = null)
    {
        $alias ??= $this->connection->driverAlias();

        if ($alias === $this->connection->driverAlias()) {
            return $this->connection->transaction(
                fn (): mixed => $tsxHandler(
                    new CapturingUnmanagedTransaction(
                        $this->connection->activeTransaction()
                            ?? throw new \LogicException('Expected an open Neo4j transaction.'),
                        $this->connection
                    )
                )
            );
        }

        return $this->client->writeTransaction(
            fn (TransactionInterface $tx) => $tsxHandler(
                new CapturingUnmanagedTransaction($tx, $this->connection)
            ),
            $alias,
            $config
        );
    }

    public function readTransaction(callable $tsxHandler, ?string $alias = null, ?TransactionConfiguration $config = null)
    {
        $alias ??= $this->connection->driverAlias();
        $transaction = $this->connectionTransaction($alias);
        if ($transaction !== null) {
            return $tsxHandler(new CapturingUnmanagedTransaction($transaction, $this->connection));
        }

        return $this->client->readTransaction(
            fn (TransactionInterface $tx) => $tsxHandler(
                new CapturingUnmanagedTransaction($tx, $this->connection)
            ),
            $alias,
            $config
        );
    }

    private function connectionTransaction(?string $alias): ?UnmanagedTransactionInterface
    {
        if ($alias !== null && $alias !== $this->connection->driverAlias()) {
            return null;
        }

        return $this->connection->activeTransaction();
    }

    public function transaction(callable $tsxHandler, ?string $alias = null, ?TransactionConfiguration $config = null)
    {
        return $this->writeTransaction($tsxHandler, $alias, $config);
    }

    public function verifyConnectivity(?string $driver = null): bool
    {
        return $this->client->verifyConnectivity($driver);
    }

    public function bindTransaction(?string $alias = null, ?TransactionConfiguration $config = null): void
    {
        $this->client->bindTransaction($alias, $config);
    }

    public function commitBoundTransaction(?string $alias = null, int $depth = 1): void
    {
        $this->client->commitBoundTransaction($alias, $depth);
    }

    public function rollbackBoundTransaction(?string $alias = null, int $depth = 1): void
    {
        $this->client->rollbackBoundTransaction($alias, $depth);
    }
}
