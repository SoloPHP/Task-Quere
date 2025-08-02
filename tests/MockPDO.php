<?php

declare(strict_types=1);

namespace Solo\TaskQueue\Tests;

use PDO;

class MockPDO extends PDO
{
    private array $queries = [];
    private array $fetchAllResult = [];
    private int $lastInsertId = 1;
    private bool $inTransaction = false;

    public function __construct()
    {
        // Mock PDO constructor
    }

    public function exec(string $sql): int
    {
        $this->queries[] = $sql;
        return 1;
    }

    public function prepare(string $query, array $options = []): MockPDOStatement|false
    {
        $this->queries[] = $query;
        return new MockPDOStatement($this->fetchAllResult);
    }

    public function lastInsertId(?string $name = null): string
    {
        return (string)$this->lastInsertId;
    }

    public function beginTransaction(): bool
    {
        $this->inTransaction = true;
        return true;
    }

    public function commit(): bool
    {
        $this->inTransaction = false;
        return true;
    }

    public function rollback(): bool
    {
        $this->inTransaction = false;
        return true;
    }

    public function setFetchAllResult(array $result): void
    {
        $this->fetchAllResult = $result;
    }

    public function wasQueryCalled(): bool
    {
        return !empty($this->queries);
    }

    public function getLastQuery(): string
    {
        return end($this->queries) ?: '';
    }
}
