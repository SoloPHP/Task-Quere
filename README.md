# TaskQueue

[![Version](https://img.shields.io/badge/version-1.1.0-blue.svg)](https://github.com/solophp/task-queue)  
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)

A lightweight PHP task queue built on top of the Solo Database.  
Supports scheduled execution, retries, task expiration, and optional process-level locking via `LockGuard`.

## 📦 Installation

```bash
composer require solophp/task-queue
```

## ⚙️ Setup

```php
use Solo\Queue\TaskQueue;

$queue = new TaskQueue($db, 'tasks', 5); // table name, max retries
$queue->install(); // creates the tasks table if not exists
```

## 🚀 Usage

### Add a task:

```php
$taskId = $queue->addTask(
    'email_notification',
    ['user_id' => 123, 'template' => 'welcome'],
    new DateTimeImmutable('tomorrow')
);
```

### Process tasks:

```php
$queue->processPendingTasks(function (string $name, array $payload) {
    match ($name) {
        'email_notification' => sendEmail($payload),
        'push_notification' => sendPush($payload),
        default => throw new RuntimeException("Unknown task: $name")
    };
});
```

## 🧰 Features

- **Task Retries** – Configurable max retry attempts before marking as failed
- **Task Expiration** – Automatic expiration via `expires_at` timestamp
- **Row-Level Locking** – Prevents concurrent execution of the same task
- **Transactional Safety** – All task operations are executed within a transaction
- **Optional Process Locking** – Add `LockGuard` to prevent multiple queue runners from overlapping

## 🔒 Using `LockGuard` (optional)

```php
use Solo\TaskQueue\LockGuard;

$lock = new LockGuard('my_worker.lock');

if (!$lock->acquire()) {
    exit(0); // Another worker is already running
}

try {
    $queue->processPendingTasks(...);
} finally {
    $lock->release(); // Optional, called automatically on shutdown if not released manually
}
```

## 🧪 API Methods

| Method                                                                                                        | Description                                 |
|---------------------------------------------------------------------------------------------------------------|---------------------------------------------|
| `install()`                                                                                                   | Create the tasks table                      |
| `addTask(string $name, array $payload, DateTimeImmutable $scheduledAt, ?DateTimeImmutable $expiresAt = null)` | Add task to the queue                       |
| `getPendingTasks(int $limit = 10)`                                                                            | Retrieve ready-to-run tasks                 |
| `markCompleted(int $taskId)`                                                                                  | Mark task as completed                      |
| `markFailed(int $taskId, string $error = '')`                                                                 | Mark task as failed with error message      |
| `processPendingTasks(callable $callback, int $limit = 10)`                                                    | Process pending tasks with a custom handler |

## 📄 License

This project is open-sourced under the [MIT license](./LICENSE).