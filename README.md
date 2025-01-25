# TaskQueue

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/solophp/database)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)

Simple PHP task queue using Solo Database.

## Installation

```bash
composer require solophp/task-queue
```

## Setup

```php
use Solo\Queue\TaskQueue;

$queue = new TaskQueue($db);
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

## Methods

- `install()` - Create tasks table
- `addTask(string $name, array $payload, DateTimeImmutable $scheduledAt)` - Add task to queue
- `getPendingTasks(int $limit = 10)` - Get pending tasks ready for execution
- `markInProgress(int $taskId)` - Mark task as in progress
- `markCompleted(int $taskId)` - Mark task as completed
- `markFailed(int $taskId, string $error = '')` - Mark task as failed with error message
- `processPendingTasks(callable $callback, int $limit = 10)` - Process pending tasks with callback

## License

MIT