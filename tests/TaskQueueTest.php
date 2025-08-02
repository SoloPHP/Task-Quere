<?php

declare(strict_types=1);

namespace Solo\TaskQueue\Tests;

use DateTimeImmutable;
use Exception;
use JsonException;
use PHPUnit\Framework\TestCase;
use Solo\TaskQueue\TaskQueue;
use PDO;

class TaskQueueTest extends TestCase
{
    private TaskQueue $queue;
    private MockPDO $db;

    protected function setUp(): void
    {
        $this->db = new MockPDO();
        $this->queue = new TaskQueue($this->db, 'test_tasks', 3, false);
    }

    public function testInstallCreatesTable(): void
    {
        $this->queue->install();

        $this->assertTrue($this->db->wasQueryCalled());
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS test_tasks', $this->db->getLastQuery());
    }

    public function testAddTaskWithDefaultSchedule(): void
    {
        $taskId = $this->queue->addTask('test_task', ['data' => 'value']);

        $this->assertEquals(1, $taskId);
        $this->assertStringContainsString('INSERT INTO test_tasks', $this->db->getLastQuery());
    }

    public function testAddTaskWithCustomSchedule(): void
    {
        $scheduledAt = new DateTimeImmutable('tomorrow');
        $expiresAt = new DateTimeImmutable('+1 week');

        $taskId = $this->queue->addTask('test_task', ['data' => 'value'], $scheduledAt, $expiresAt);

        $this->assertEquals(1, $taskId);
        $this->assertStringContainsString('INSERT INTO test_tasks', $this->db->getLastQuery());
    }

    public function testAddTaskWithPayloadType(): void
    {
        $taskId = $this->queue->addTask('test_task', ['type' => 'email', 'data' => 'value']);

        $this->assertEquals(1, $taskId);
        $this->assertStringContainsString('INSERT INTO test_tasks', $this->db->getLastQuery());
    }

    public function testGetPendingTasks(): void
    {
        $this->db->setFetchAllResult([(object)['id' => 1, 'name' => 'test', 'payload' => '{"data":"value"}']]);

        $tasks = $this->queue->getPendingTasks(5);

        $this->assertCount(1, $tasks);
        $this->assertStringContainsString('SELECT * FROM test_tasks', $this->db->getLastQuery());
    }

    public function testGetPendingTasksWithTypeFilter(): void
    {
        $this->db->setFetchAllResult([(object)['id' => 1, 'name' => 'test', 'payload' => '{"data":"value"}']]);

        $tasks = $this->queue->getPendingTasks(5, 'email');

        $this->assertCount(1, $tasks);
        $this->assertStringContainsString('payload_type = ?', $this->db->getLastQuery());
    }

    public function testMarkCompleted(): void
    {
        $this->queue->markCompleted(1);

        $this->assertStringContainsString('UPDATE test_tasks SET status = \'completed\'', $this->db->getLastQuery());
    }

    public function testMarkCompletedWithDeleteOnSuccess(): void
    {
        $queue = new TaskQueue($this->db, 'test_tasks', 3, true);
        $queue->markCompleted(1);

        $this->assertStringContainsString('DELETE FROM test_tasks', $this->db->getLastQuery());
    }

    public function testMarkFailed(): void
    {
        $this->queue->markFailed(1, 'Test error');

        $this->assertStringContainsString('UPDATE test_tasks SET status = CASE', $this->db->getLastQuery());
    }

    public function testProcessPendingTasks(): void
    {
        $this->db->setFetchAllResult([(object)['id' => 1, 'name' => 'test', 'payload' => '{"data":"value"}']]);

        $processed = false;
        $this->queue->processPendingTasks(function (string $name, array $payload) use (&$processed) {
            $processed = true;
            $this->assertEquals('test', $name);
            $this->assertEquals(['data' => 'value'], $payload);
        });

        $this->assertTrue($processed);
    }

    public function testProcessPendingTasksWithError(): void
    {
        $this->db->setFetchAllResult([(object)['id' => 1, 'name' => 'test', 'payload' => '{"data":"value"}']]);

        $this->queue->processPendingTasks(function (string $name, array $payload) {
            throw new Exception('Test exception');
        });

        $this->assertStringContainsString('UPDATE test_tasks SET status = CASE', $this->db->getLastQuery());
    }
}
