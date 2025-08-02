# TaskQueue

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/task-queue.svg)](https://packagist.org/packages/solophp/task-queue)
[![License](https://img.shields.io/packagist/l/solophp/task-queue.svg)](https://github.com/solophp/task-queue/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/task-queue.svg)](https://packagist.org/packages/solophp/task-queue)

A lightweight PHP task queue built on top of PDO.  
Supports scheduled execution, retries, task expiration, indexed task types, automatic deletion of completed tasks, and optional process-level locking via `LockGuard`.

## 📦 Installation

```bash
composer require solophp/task-queue
```

## 📋 Requirements

- **PHP**: >= 8.2
- **Extensions**: 
  - `ext-json` - for JSON payload handling
  - `ext-pdo` - for database operations
  - `ext-posix` - for LockGuard process locking (optional)

This package uses standard PHP extensions and has minimal external dependencies. No external database libraries required - works with any PDO-compatible database (MySQL, PostgreSQL, SQLite, etc.).

## ⚙️ Setup

```php
use Solo\TaskQueue\TaskQueue;

$pdo = new PDO('mysql:host=localhost;dbname=test', 'username', 'password');
$queue = new TaskQueue($pdo, table: 'tasks', maxRetries: 5, deleteOnSuccess: true);
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

## 🧪 Testing

```bash
# Run tests
composer test

# Run code sniffer
composer cs

# Fix code style issues
composer cs-fix
```

## 📄 License

This project is open-sourced under the [MIT license](./LICENSE).