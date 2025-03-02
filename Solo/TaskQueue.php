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
        private string   $table = 'tasks',
        private int      $lockTimeout = 900 // 15 minutes
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
           locked_at DATETIME NULL,
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
           INDEX idx_status_scheduled (status, scheduled_at),
           INDEX idx_locked_at (locked_at)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Add a task to the queue.
     *
     * @param string $name Task identifier
     * @param array $payload Task data
     * @param DateTimeImmutable $scheduledAt When to execute the task
     * @return int The ID of the inserted task
     * @throws JsonException|Exception
     */
    public function addTask(string $name, array $payload, DateTimeImmutable $scheduledAt): int
    {
        $this->db->query("INSERT INTO $this->table SET 
            name = ?s, 
            payload = ?s, 
            scheduled_at = ?d,
            status = 'pending'",
            $name,
            json_encode($payload, JSON_THROW_ON_ERROR),
            $scheduledAt
        );

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get and lock pending tasks atomically
     *
     * @param int $limit Maximum number of tasks to retrieve
     * @return object[] Array of task objects
     * @throws Exception
     */
    public function getPendingTasks(int $limit = 10): array
    {
        $this->db->beginTransaction();

        try {
            $now = new DateTimeImmutable();
            $timeout = $now->modify("-$this->lockTimeout seconds");

            $this->db->query("SELECT * FROM $this->table 
                WHERE status = 'pending' 
                AND scheduled_at <= ?d 
                AND (locked_at IS NULL OR locked_at <= ?d)
                ORDER BY scheduled_at 
                LIMIT ?i 
                FOR UPDATE SKIP LOCKED",
                $now,
                $timeout,
                $limit
            );

            $tasks = $this->db->fetchAll();

            foreach ($tasks as $task) {
                $this->db->query(
                    "UPDATE $this->table SET 
                    status = 'in_progress',
                    locked_at = NOW() 
                    WHERE id = ?i",
                    $task->id
                );
            }

            $this->db->commit();
            return $tasks;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Mark a task as completed.
     *
     * @param int $taskId Task ID to mark
     * @throws Exception
     */
    public function markCompleted(int $taskId): void
    {
        $this->db->query(
            "UPDATE $this->table SET 
            status = 'completed',
            locked_at = NULL 
            WHERE id = ?i",
            $taskId
        );
    }

    /**
     * Mark a task as failed with error message.
     *
     * @param int $taskId Task ID to mark
     * @param string $error Error description
     * @throws Exception
     */
    public function markFailed(int $taskId, string $error = ''): void
    {
        $this->db->query(
            "UPDATE $this->table SET 
            status = 'failed',
            error = ?s,
            locked_at = NULL 
            WHERE id = ?i",
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
                $payload = json_decode($task->payload, true, 512, JSON_THROW_ON_ERROR);
                $callback($task->name, $payload);
                $this->markCompleted($task->id);
            } catch (Throwable $e) {
                $this->markFailed($task->id, $e->getMessage());

                if ($this->isRetryableError($e)) {
                    $this->retryTask($task);
                }
            }
        }
    }

    /**
     * Check if error is retryable
     */
    private function isRetryableError(Throwable $e): bool
    {
        return $e->getCode() === 429 // Too Many Requests
            || str_contains($e->getMessage(), 'Connection timed out');
    }

    /**
     * Reschedule task for retry
     */
    private function retryTask(object $task): void
    {
        $retryDelay = match (true) {
            $task->retry_count < 3 => 300, // 5 minutes
            $task->retry_count < 5 => 1800, // 30 minutes
            default => 3600 // 1 hour
        };

        $this->db->query("UPDATE $this->table SET 
            status = 'pending',
            scheduled_at = DATE_ADD(NOW(), INTERVAL ?i SECOND),
            locked_at = NULL 
            WHERE id = ?i",
            $retryDelay,
            $task->id
        );
    }
}