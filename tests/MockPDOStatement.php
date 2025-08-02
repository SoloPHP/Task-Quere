<?php

declare(strict_types=1);

namespace Solo\TaskQueue\Tests;

use PDO;

class MockPDOStatement extends \PDOStatement
{
    private array $fetchAllResult;

    public function __construct(array $fetchAllResult)
    {
        $this->fetchAllResult = $fetchAllResult;
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->fetchAllResult;
    }
}
