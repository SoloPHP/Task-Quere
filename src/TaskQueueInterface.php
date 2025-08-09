<?php

declare(strict_types=1);

namespace Solo\TaskQueue;

use DateTimeImmutable;
use Exception;
use JsonException;

/**
 * Interface for task queue implementations.
 */
interface TaskQueueInterface
{
    /**
     * Add a task to the queue.
     *
     * @param string $name Task identifier
     * @param array $payload Task data (should include 'type' key if filtering by type is needed)
     * @param DateTimeImmutable|null $scheduledAt When the task should be executed (default: now)
     * @param DateTimeImmutable|null $expiresAt When the task becomes invalid (optional)
     * @return int ID of the newly inserted task
     * @throws JsonException When payload encoding fails
     * @throws Exception When database query fails
     */
    public function addTask(
        string $name,
        array $payload,
        ?DateTimeImmutable $scheduledAt = null,
        ?DateTimeImmutable $expiresAt = null
    ): int;

    /**
     * Retrieve pending tasks ready for execution.
     *
     * @param int $limit Maximum number of tasks to retrieve
     * @param string|null $onlyType If provided, only tasks with this payload_type column value will be returned
     * @return object[] Array of task records as objects
     * @throws Exception When database query fails
     */
    public function getPendingTasks(int $limit = 10, ?string $onlyType = null): array;

    /**
     * Mark a task as completed or delete it based on configuration.
     *
     * @param int $taskId ID of the task
     * @throws Exception When database query fails
     */
    public function markCompleted(int $taskId): void;

    /**
     * Mark a task as failed and increment its retry counter.
     * If the retry count exceeds the max retry limit, the task is marked as 'failed'; otherwise, it is returned to 'pending'.
     *
     * @param int $taskId ID of the task
     * @param string $error Optional error message
     * @throws Exception When database query fails
     */
    public function markFailed(int $taskId, string $error = ''): void;

    /**
     * Process pending tasks using a callback.
     * The callback receives the task name and decoded payload array.
     *
     * @param callable $callback Function to process each task: fn(string $name, array $payload): void
     * @param int $limit Maximum number of tasks to process
     * @param string|null $onlyType If provided, only tasks with this value in the 'payload_type' column will be processed
     * @throws Exception When database operations fail
     */
    public function processPendingTasks(callable $callback, int $limit = 10, ?string $onlyType = null): void;
}