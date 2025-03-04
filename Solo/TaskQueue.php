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
    /** @var Database Database connection */
    private Database $db;

    /** @var string Table name for storing tasks */
    private string $table;

    /** @var int Maximum retry attempts before marking a task as failed */
    private int $maxRetries;

    /**
     * TaskQueue constructor.
     *
     * @param Database $db Database instance
     * @param string $table Table name for storing tasks
     * @param int $maxRetries Maximum retry attempts before failure
     */
    public function __construct(Database $db, string $table = 'tasks', int $maxRetries = 3)
    {
        $this->db = $db;
        $this->table = $table;
        $this->maxRetries = $maxRetries;
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
            scheduled_at DATETIME NOT NULL,
            status ENUM('pending', 'in_progress', 'completed', 'failed') NOT NULL DEFAULT 'pending',
            retry_count INT UNSIGNED NOT NULL DEFAULT 0,
            error TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            locked_at TIMESTAMP NULL DEFAULT NULL,
            expires_at TIMESTAMP NULL DEFAULT NULL,
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
     * @param DateTimeImmutable|null $expiresAt When the task expires
     * @return int The ID of the inserted task
     * @throws JsonException When JSON encoding fails
     * @throws Exception When database query fails
     */
    public function addTask(string $name, array $payload, DateTimeImmutable $scheduledAt, ?DateTimeImmutable $expiresAt = null): int
    {
        $this->db->query("INSERT INTO $this->table SET name = ?s, payload = ?s, scheduled_at = ?d, expires_at = ?d",
            $name,
            json_encode($payload, JSON_THROW_ON_ERROR),
            $scheduledAt,
            $expiresAt
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
            "SELECT * FROM $this->table WHERE status = 'pending' AND scheduled_at <= ?d AND (expires_at IS NULL OR expires_at > ?d) AND locked_at IS NULL LIMIT ?i FOR UPDATE",
            $now,
            $now,
            $limit
        );

        return $this->db->fetchAll();
    }

    /**
     * Lock a task for processing.
     *
     * @param int $taskId Task ID to lock
     * @throws Exception When database query fails
     */
    private function lockTask(int $taskId): void
    {
        $this->db->query("UPDATE $this->table SET locked_at = NOW(), status = 'in_progress' WHERE id = ?i", $taskId);
    }

    /**
     * Mark a task as completed.
     *
     * @param int $taskId Task ID to mark
     * @throws Exception When database query fails
     */
    public function markCompleted(int $taskId): void
    {
        $this->db->query("UPDATE $this->table SET status = 'completed', locked_at = NULL WHERE id = ?i", $taskId);
    }

    /**
     * Mark a task as failed and increment retry count.
     *
     * @param int $taskId Task ID to mark
     * @param string $error Error description
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
     * Process pending tasks with a callback.
     *
     * @param callable $callback Function to process each task (fn(string $name, array $payload): void)
     * @param int $limit Maximum number of tasks to process
     * @throws Exception When database query fails
     */
    public function processPendingTasks(callable $callback, int $limit = 10): void
    {
        $this->db->beginTransaction();
        try {
            $tasks = $this->getPendingTasks($limit);

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
