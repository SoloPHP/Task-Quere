# TaskQueue

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/solophp/database)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)

Enhanced PHP task queue using Solo Database with retry mechanism and task expiration support.

## Installation

```bash
composer require solophp/task-queue
```

## Setup

```php
use Solo\Queue\TaskQueue;

$queue = new TaskQueue($db, 'tasks', 5); // Table name and max retries
$queue->install(); // Create tasks table
```

## Usage

Add task:
```php
$taskId = $queue->addTask(
    'email_notification',
    ['user_id' => 123, 'template' => 'welcome'],
    new DateTimeImmutable('tomorrow')
);
```

Process tasks:
```php
$queue->processPendingTasks(function (string $name, array $payload) {
    match($name) {
        'email_notification' => sendEmail($payload),
        'push_notification' => sendPush($payload),
        default => throw new RuntimeException("Unknown task: $name")
    };
});
```

## Features

- **Task Retries** - Configurable max retry attempts before marking a task as failed
- **Task Expiration** - Supports automatic expiration of tasks
- **Database Locking** - Prevents duplicate task execution
- **Transaction Safety** - Ensures atomic operations

## Methods

- `install()` - Create tasks table
- `addTask(string $name, array $payload, DateTimeImmutable $scheduledAt, ?DateTimeImmutable $expiresAt = null)` - Add task to queue
- `getPendingTasks(int $limit = 10)` - Get pending tasks ready for execution
- `markCompleted(int $taskId)` - Mark task as completed
- `markFailed(int $taskId, string $error = '')` - Mark task as failed with error message
- `processPendingTasks(callable $callback, int $limit = 10)` - Process pending tasks with callback

## License

MIT