# TaskQueue

[![Version](https://img.shields.io/badge/version-1.1.0-blue.svg)](https://github.com/solophp/database)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)

Robust PHP task queue with atomic locking and automatic retries, powered by Solo Database.

## Features
- 🛡️ **Atomic task locking** prevents duplicate processing
- 🔄 **Automatic retries** for transient errors (429/503/timeouts)
- ⏱️ **Lock timeout** for stuck tasks (default: 15 minutes)
- 📦 **Batched processing** with configurable limits
- 📈 **Progress tracking** with status updates
- 🔍 **Thread-safe** operations using database-level locks

## Installation

```bash
composer require solophp/task-queue
```

## Setup

```php
use Solo\Queue\TaskQueue;

// Initialize with database connection
$queue = new TaskQueue(
    $db, // Solo Database instance
    'tasks', // table name (optional)
    900 // lock timeout in seconds (optional)
);

// Create tasks table
$queue->install();
```

## Usage

### Adding Tasks
```php
$taskId = $queue->addTask(
    'telegram_notification',
    ['chat_id' => 123, 'text' => 'Hello World'],
    new DateTimeImmutable('+5 minutes')
);
```

### Processing Tasks
```php
$queue->processPendingTasks(
    function (string $name, array $payload) {
    match($name) {
            'telegram_notification' => sendToTelegram($payload),
            'email_alert' => sendEmail($payload),
        default => throw new RuntimeException("Unknown task: $name")
    };
    },
    5 // Process 5 tasks per batch (optional)
);
```

### Automatic Recovery
- Failed tasks are automatically retried with exponential backoff
- Network errors (429/503) trigger automatic rescheduling
- Stuck tasks (>15 min lock) are automatically unlocked

## Methods

| Method | Description |
|--------|-------------|
| `install()` | Creates the tasks table |
| `addTask(string $name, array $payload, DateTimeImmutable $scheduledAt)` | Adds a new task to queue |
| `getPendingTasks(int $limit)` | **Atomically** locks and retrieves pending tasks |
| `markCompleted(int $taskId)` | Marks task as successfully completed |
| `markFailed(int $taskId, string $error)` | Marks task as failed with error message |
| `processPendingTasks(callable $callback, int $limit)` | Processes tasks with automatic error handling |

## Best Practices
```php
// Run as daemon with lock safety
while (true) {
    $queue->processPendingTasks(
        fn($name, $payload) => handleTask($name, $payload),
        10 // Tasks per iteration
    );
    sleep(1); // Reduce CPU usage
}

// Handle permanent failures
try {
    // ... task logic ...
} catch (PermanentError $e) {
    $queue->markFailed($taskId, $e->getMessage());
    // No retry for permanent errors
}
```

## Database Schema
```sql
CREATE TABLE tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    payload JSON NOT NULL,
    scheduled_at DATETIME NOT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'failed') DEFAULT 'pending',
    error TEXT NULL,
    locked_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
);
```

## License
MIT
```

MIT