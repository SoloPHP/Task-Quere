<?php declare(strict_types=1);

namespace Solo;

use DateTimeImmutable;
use Exception;
use JsonException;
use Throwable;

final readonly class TaskQueue
{
    public function __construct(
        private Database $db,
        private string   $table = 'tasks'
    )
    {
    }

    /**
     * Create tasks table if not exists
     * @throws Exception When database query fails
     */
    public function install(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS $this->table (
           id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           name VARCHAR(255) NOT NULL,
           payload JSON NOT NULL,
           scheduled_at DATETIME NOT NULL,
           status ENUM('pending', 'in_progress', 'completed', 'failed') NOT NULL DEFAULT 'pending',
           error TEXT NULL,
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
           INDEX idx_status_scheduled (status, scheduled_at)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Add a task to the queue.
     *
     * @param string $name Task identifier
     * @param array $payload Task data
     * @param DateTimeImmutable $scheduledAt When to execute the task
     * @return int The ID of the inserted task
     * @throws JsonException When JSON encoding fails
     * @throws Exception When database query fails
     */
    public function addTask(string $name, array $payload, DateTimeImmutable $scheduledAt): int
    {
        $this->db->query("INSERT INTO $this->table SET name = ?s, payload = ?s, scheduled_at = ?d",
            $name,
            json_encode($payload, JSON_THROW_ON_ERROR),
            $scheduledAt
        );

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get pending tasks ready for execution.
     *
     * @param int $limit Maximum number of tasks to retrieve
     * @return object[] Array of task objects
     * @throws Exception When database query fails
     */
    public function getPendingTasks(int $limit = 10): array
    {
        $now = new DateTimeImmutable();

        $this->db->query(
            "SELECT * FROM $this->table WHERE status = 'pending' AND scheduled_at <= ?d LIMIT ?i",
            $now,
            $limit
        );

        return $this->db->fetchAll();
    }

    /**
     * Mark a task as in progress.
     *
     * @param int $taskId Task ID to mark
     * @throws Exception When database query fails
     */
    public function markInProgress(int $taskId): void
    {
        $this->db->query("UPDATE $this->table SET status = 'in_progress' WHERE id = ?i",
            $taskId
        );
    }

    /**
     * Mark a task as completed.
     *
     * @param int $taskId Task ID to mark
     * @throws Exception When database query fails
     */
    public function markCompleted(int $taskId): void
    {
        $this->db->query("UPDATE $this->table SET status = 'completed' WHERE id = ?i",
            $taskId
        );
    }

    /**
     * Mark a task as failed with error message.
     *
     * @param int $taskId Task ID to mark
     * @param string $error Error description
     * @throws Exception When database query fails
     */
    public function markFailed(int $taskId, string $error = ''): void
    {
        $this->db->query(
            "UPDATE $this->table SET status = 'failed', error = ?s WHERE id = ?i",
            $error,
            $taskId
        );
    }

    /**
     * Process pending tasks with a callback.
     *
     * @param callable $callback fn(string $name, array $payload): void
     * @param int $limit Maximum number of tasks to process
     */
    public function processPendingTasks(callable $callback, int $limit = 10): void
    {
        $tasks = $this->getPendingTasks($limit);

        foreach ($tasks as $task) {
            try {
                $this->markInProgress($task->id);
                $payload = json_decode($task->payload, true, 512, JSON_THROW_ON_ERROR);
                $callback($task->name, $payload);
                $this->markCompleted($task->id);
            } catch (Throwable $e) {
                $this->markFailed($task->id, $e->getMessage());
            }
        }
    }
}