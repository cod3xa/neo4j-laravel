<?php

namespace Neo4j\Neo4jLaravel\Decorators;

use Laudis\Neo4j\Contracts\SessionInterface;
use Laudis\Neo4j\Contracts\TransactionInterface;
use Laudis\Neo4j\Contracts\UnmanagedTransactionInterface;
use Laudis\Neo4j\Databags\Bookmark;
use Laudis\Neo4j\Databags\Statement;
use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Databags\TransactionConfiguration;
use Laudis\Neo4j\Types\CypherList;
use Neo4j\Neo4jLaravel\Debug\CapturingUnmanagedTransaction;
use Neo4j\Neo4jLaravel\Neo4jConnection;

/**
 * Wraps a Neo4j session so run()/transactions go through Laravel query capture.
 *
 * @internal
 */
final class LaravelNeo4jSession implements SessionInterface
{
    public function __construct(
        private readonly SessionInterface $session,
        private readonly Neo4jConnection $connection
    ) {
    }

    /**
     * @param iterable<string, mixed> $parameters
     */
    public function run(string $statement, iterable $parameters = [], ?TransactionConfiguration $config = null): SummarizedResult
    {
        $bindings = ParameterBag::toArray($parameters);

        /** @var SummarizedResult */
        return $this->connection->executeCaptured(
            $statement,
            $bindings,
            function () use ($statement, $parameters, $config): SummarizedResult {
                $transaction = $this->connection->activeTransaction();
                if ($transaction !== null) {
                    return $transaction->run($statement, $parameters);
                }

                return $this->session->run($statement, $parameters, $config);
            }
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function executeQuery(string $cypher, array $parameters = []): SummarizedResult
    {
        /** @var SummarizedResult */
        return $this->connection->executeCaptured(
            $cypher,
            $parameters,
            function () use ($cypher, $parameters): SummarizedResult {
                $transaction = $this->connection->activeTransaction();
                if ($transaction !== null) {
                    return $transaction->run($cypher, $parameters);
                }

                if (method_exists($this->session, 'executeQuery')) {
                    /** @var SummarizedResult */
                    return $this->session->executeQuery($cypher, $parameters);
                }

                return $this->session->run($cypher, $parameters);
            }
        );
    }

    public function runStatement(Statement $statement, ?TransactionConfiguration $config = null): SummarizedResult
    {
        $bindings = ParameterBag::toArray($statement->getParameters());

        /** @var SummarizedResult */
        return $this->connection->executeCaptured(
            $statement->getText(),
            $bindings,
            function () use ($statement, $config): SummarizedResult {
                $transaction = $this->connection->activeTransaction();
                if ($transaction !== null) {
                    return $transaction->runStatement($statement);
                }

                return $this->session->runStatement($statement, $config);
            }
        );
    }

    /**
     * @param iterable<Statement> $statements
     *
     * @return CypherList<SummarizedResult>
     */
    public function runStatements(iterable $statements, ?TransactionConfiguration $config = null): CypherList
    {
        $results = [];
        foreach ($statements as $statement) {
            $results[] = $this->runStatement($statement, $config);
        }

        return CypherList::fromIterable($results);
    }

    public function beginTransaction(?iterable $statements = null, ?TransactionConfiguration $config = null): UnmanagedTransactionInterface
    {
        $tx = new CapturingUnmanagedTransaction(
            $this->session->beginTransaction(null, $config),
            $this->connection
        );

        if ($statements !== null) {
            foreach ($statements as $statement) {
                $tx->runStatement($statement);
            }
        }

        return $tx;
    }

    public function writeTransaction(callable $tsxHandler, ?TransactionConfiguration $config = null)
    {
        return $this->session->writeTransaction(
            fn (TransactionInterface $tx) => $tsxHandler(
                new CapturingUnmanagedTransaction($tx, $this->connection)
            ),
            $config
        );
    }

    public function readTransaction(callable $tsxHandler, ?TransactionConfiguration $config = null)
    {
        return $this->session->readTransaction(
            fn (TransactionInterface $tx) => $tsxHandler(
                new CapturingUnmanagedTransaction($tx, $this->connection)
            ),
            $config
        );
    }

    public function transaction(callable $tsxHandler, ?TransactionConfiguration $config = null)
    {
        return $this->writeTransaction($tsxHandler, $config);
    }

    public function getLastBookmark(): Bookmark
    {
        return $this->session->getLastBookmark();
    }

    public function close(): void
    {
        if (method_exists($this->session, 'close')) {
            $this->session->close();
        }
    }
}
