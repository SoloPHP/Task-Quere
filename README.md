# TaskQueue

[![Version](https://img.shields.io/badge/version-1.1.0-blue.svg)](https://github.com/solophp/task-queue)  
[![License](https://img.shields.io/badge/license-MIT-green.svg)](./LICENSE)

A lightweight PHP task queue built on top of the Solo Database.  
Supports scheduled execution, retries, task expiration, indexed task types, automatic deletion of completed tasks, and optional process-level locking via `LockGuard`.

## 📦 Installation

```bash
composer require solophp/task-queue
```

## ⚙️ Setup

```php
use Solo\Queue\TaskQueue;

$queue = new TaskQueue($db, table: 'tasks', maxRetries: 5, deleteOnSuccess: true);
$queue->install(); // creates the tasks table if not exists
```

## 🚀 Usage

### Add a task:

```php
$taskId = $queue->addTask(
    'email_notification',
    ['type' => 'email_notification', 'user_id' => 123, 'template' => 'welcome'],
    new DateTimeImmutable('tomorrow') // optional, defaults to now
);
```

### Process all tasks:

```php
$queue->processPendingTasks(function (string $name, array $payload) {
    match ($name) {
        'email_notification' => sendEmail($payload),
        'push_notification' => sendPush($payload),
        default => throw new RuntimeException("Unknown task: $name")
    };
});
```

### Process only specific type:

```php
$queue->processPendingTasks(function (string $name, array $payload) {
    sendEmail($payload);
}, 20, 'email_notification'); // only tasks where payload_type column = 'email_notification'
```

## 🔒 Using `LockGuard` (optional)

```php
use Solo\TaskQueue\LockGuard;

$lockFile = __DIR__ . '/storage/locks/my_worker.lock';
$lock = new LockGuard($lockFile); // path to lock file

if (!$lock->acquire()) {
    exit(0); // Another worker is already running
}

try {
    $queue->processPendingTasks(...);
} finally {
    $lock->release(); // Optional, auto-released on shutdown
}
```

## 🧰 Features

- **Task Retries** – Configurable max retry attempts before marking as failed  
- **Task Expiration** – Automatic expiration via `expires_at` timestamp  
- **Indexed Task Types** – Fast filtering by `payload_type`  
- **Row-Level Locking** – Prevents concurrent execution of the same task  
- **Transactional Safety** – All task operations are executed within a transaction  
- **Optional Process Locking** – Prevent overlapping workers using `LockGuard`  
- **Optional Deletion on Success** – Set `deleteOnSuccess: true` to automatically delete tasks after success  

## 🧪 API Methods

| Method                                                                                                                | Description                                                    |
|-----------------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------|
| `install()`                                                                                                           | Create the tasks table                                         |
| `addTask(string $name, array $payload, ?DateTimeImmutable $scheduledAt = null, ?DateTimeImmutable $expiresAt = null)` | Add task to the queue (default schedule: now)                 |
| `getPendingTasks(int $limit = 10, ?string $onlyType = null)`                                                         | Retrieve ready-to-run tasks, optionally filtered by type       |
| `markCompleted(int $taskId)`                                                                                          | Mark task as completed                                         |
| `markFailed(int $taskId, string $error = '')`                                                                         | Mark task as failed with error message                         |
| `processPendingTasks(callable $callback, int $limit = 10, ?string $onlyType = null)`                                 | Process pending tasks with a custom handler                    |

## 📄 License

This project is open-sourced under the [MIT license](./LICENSE).