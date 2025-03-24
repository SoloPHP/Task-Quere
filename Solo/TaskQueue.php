<?php declare(strict_types=1);

namespace Solo;

use DateTimeImmutable;
use Exception;
use JsonException;
use Throwable;

/**
 * Task Queue for managing asynchronous tasks.
 */
final readonly class TaskQueue
{
    public function __construct(
        private Database $db,
        private string   $table = 'tasks',
        private int      $maxRetries = 3,
        private bool     $deleteOnSuccess = false
    )
    {
    }

    /**
     * Create tasks table if not exists.
     *
     * @throws Exception When database query fails
     */
    public function install(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS $this->table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            payload JSON NOT NULL,
            payload_type VARCHAR(64) NOT NULL DEFAULT 'default',
            scheduled_at DATETIME NOT NULL,
            status ENUM('pending', 'in_progress', 'completed', 'failed') NOT NULL DEFAULT 'pending',
            retry_count INT UNSIGNED NOT NULL DEFAULT 0,
            error TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            locked_at TIMESTAMP NULL DEFAULT NULL,
            expires_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_status_scheduled (status, scheduled_at),
            INDEX idx_locked_at (locked_at),
            INDEX idx_payload_type (payload_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

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
    public function addTask(string $name, array $payload, ?DateTimeImmutable $scheduledAt = null, ?DateTimeImmutable $expiresAt = null): int
    {
        $scheduledAt ??= new DateTimeImmutable();

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $payloadType = $payload['type'] ?? 'default';

        $this->db->query(
            "INSERT INTO $this->table SET name = ?s, payload = ?s, payload_type = ?s, scheduled_at = ?d, expires_at = ?d",
            $name,
            $payloadJson,
            $payloadType,
            $scheduledAt,
            $expiresAt
        );

        return (int)$this->db->lastInsertId();
    }

    /**
     * Retrieve pending tasks ready for execution.
     *
     * @param int $limit Maximum number of tasks to retrieve
     * @param string|null $onlyType If provided, only tasks with this payload_type column value will be returned
     * @return object[] Array of task records as objects
     * @throws Exception When database query fails
     */
    public function getPendingTasks(int $limit = 10, ?string $onlyType = null): array
    {
        $now = new DateTimeImmutable();

        $query = "SELECT * FROM $this->table WHERE status = 'pending' AND scheduled_at <= ?d AND (expires_at IS NULL OR expires_at > ?d) AND locked_at IS NULL";

        if ($onlyType) {
            $query .= " AND payload_type = ?s LIMIT ?i FOR UPDATE";
            $this->db->query($query, $now, $now, $onlyType, $limit);
        } else {
            $query .= " LIMIT ?i FOR UPDATE";
            $this->db->query($query, $now, $now, $limit);
        }

        return $this->db->fetchAll();
    }

    /**
     * Lock a task for processing by updating its status and lock timestamp.
     *
     * @param int $taskId ID of the task to lock
     * @throws Exception When database query fails
     */
    private function lockTask(int $taskId): void
    {
        $this->db->query("UPDATE $this->table SET locked_at = NOW(), status = 'in_progress' WHERE id = ?i", $taskId);
    }

    /**
     * Mark a task as completed or delete it based on configuration.
     *
     * @param int $taskId ID of the task
     * @throws Exception When database query fails
     */
    public function markCompleted(int $taskId): void
    {
        if ($this->deleteOnSuccess) {
            $this->db->query("DELETE FROM $this->table WHERE id = ?i", $taskId);
        } else {
            $this->db->query("UPDATE $this->table SET status = 'completed', locked_at = NULL WHERE id = ?i", $taskId);
        }
    }

    /**
     * Mark a task as failed and increment its retry counter.
     * If the retry count exceeds the max retry limit, the task is marked as 'failed'; otherwise, it is returned to 'pending'.
     *
     * @param int $taskId ID of the task
     * @param string $error Optional error message
     * @throws Exception When database query fails
     */
    public function markFailed(int $taskId, string $error = ''): void
    {
        $this->db->query(
            "UPDATE $this->table SET status = CASE WHEN retry_count >= ?i THEN 'failed' ELSE 'pending' END, retry_count = retry_count + 1, error = ?s, locked_at = NULL WHERE id = ?i",
            $this->maxRetries,
            $error,
            $taskId
        );
    }

    /**
     * Process pending tasks using a callback.
     * The callback receives the task name and decoded payload array.
     *
     * @param callable $callback Function to process each task: fn(string $name, array $payload): void
     * @param int $limit Maximum number of tasks to process
     * @param string|null $onlyType If provided, only tasks with this value in the 'payload_type' column will be processed
     * @throws Exception When database operations fail
     */
    public function processPendingTasks(callable $callback, int $limit = 10, ?string $onlyType = null): void
    {
        $this->db->beginTransaction();
        try {
            $tasks = $this->getPendingTasks($limit, $onlyType);

            foreach ($tasks as $task) {
                try {
                    $this->lockTask($task->id);
                    $payload = json_decode($task->payload, true, 512, JSON_THROW_ON_ERROR);
                    $callback($task->name, $payload);
                    $this->markCompleted($task->id);
                } catch (Throwable $e) {
                    $this->markFailed($task->id, $e->getMessage());
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
