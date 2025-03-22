<?php declare(strict_types=1);

namespace Solo\TaskQueue;

final class LockGuard
{
    private string $file;

    private bool $active = false;

    public function __construct(string $name = 'task_worker.lock', ?string $dir = null)
    {
        $dir ??= dirname(__DIR__, 2) . '/storage/locks';
        $this->file = rtrim($dir, '/') . '/' . ltrim($name, '/');
    }

    public function acquire(): bool
    {
        if (!is_dir(dirname($this->file))) {
            mkdir(dirname($this->file), 0775, true);
        }

        if (file_exists($this->file)) {
            $pid = file_get_contents($this->file);
            if ($pid && posix_kill((int)$pid, 0)) {
                return false;
            }
            unlink($this->file);
        }

        file_put_contents($this->file, getmypid());
        $this->active = true;

        register_shutdown_function(fn() => $this->release());

        return true;
    }

    public function release(): void
    {
        if ($this->active && file_exists($this->file)) {
            unlink($this->file);
        }
        $this->active = false;
    }
}