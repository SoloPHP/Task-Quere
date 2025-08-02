<?php

declare(strict_types=1);

namespace Solo\TaskQueue\Tests;

use PHPUnit\Framework\TestCase;
use Solo\TaskQueue\LockGuard;

class LockGuardTest extends TestCase
{
    private string $lockFile;

    protected function setUp(): void
    {
        $this->lockFile = sys_get_temp_dir() . '/test_lock_' . uniqid() . '.lock';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }

    public function testAcquireCreatesLockFile(): void
    {
        $lock = new LockGuard($this->lockFile);

        $result = $lock->acquire();

        $this->assertTrue($result);
        $this->assertTrue(file_exists($this->lockFile));
        $this->assertEquals(getmypid(), file_get_contents($this->lockFile));
    }

    public function testAcquireCreatesDirectoryIfNotExists(): void
    {
        $lockDir = sys_get_temp_dir() . '/test_lock_dir_' . uniqid();
        $lockFile = $lockDir . '/test.lock';

        $lock = new LockGuard($lockFile);
        $lock->acquire();

        $this->assertTrue(is_dir($lockDir));
        $this->assertTrue(file_exists($lockFile));

        // Cleanup
        unlink($lockFile);
        rmdir($lockDir);
    }

    public function testAcquireReturnsFalseIfLockExists(): void
    {
        // Create a lock file with a valid PID
        file_put_contents($this->lockFile, getmypid());

        $lock = new LockGuard($this->lockFile);

        $result = $lock->acquire();

        $this->assertFalse($result);
    }

    public function testAcquireRemovesStaleLock(): void
    {
        // Create a lock file with an invalid PID
        file_put_contents($this->lockFile, '999999');

        $lock = new LockGuard($this->lockFile);

        $result = $lock->acquire();

        $this->assertTrue($result);
        $this->assertEquals(getmypid(), file_get_contents($this->lockFile));
    }

    public function testReleaseRemovesLockFile(): void
    {
        $lock = new LockGuard($this->lockFile);
        $lock->acquire();

        $this->assertTrue(file_exists($this->lockFile));

        $lock->release();

        $this->assertFalse(file_exists($this->lockFile));
    }

    public function testReleaseDoesNothingIfNotActive(): void
    {
        $lock = new LockGuard($this->lockFile);

        // Should not throw any exception
        $lock->release();

        $this->assertFalse(file_exists($this->lockFile));
    }

    public function testReleaseDoesNothingIfFileDoesNotExist(): void
    {
        $lock = new LockGuard($this->lockFile);
        $lock->acquire();

        // Remove the file manually
        unlink($this->lockFile);

        // Should not throw any exception
        $lock->release();
    }
}
