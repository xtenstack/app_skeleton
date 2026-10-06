<?php
declare(strict_types=1);

use App_skeleton\SessionGc;
use PHPUnit\Framework\TestCase;

/**
 * Which files the session clean-up (./run session gc) is allowed to delete.
 * A wrong answer either logs people out early or lets the directory grow
 * without bound (ticket 75), so the selection is tested on a real
 * temporary directory with files of known age.
 */
final class SessionGcTest extends TestCase
{
    private const DAY = 86400;

    private string $dir;
    private int $now;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/session_gc_test_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->now = time();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path) && basename($path) !== '.' && basename($path) !== '..') {
                rmdir($path);
            }
        }

        rmdir($this->dir);
    }

    private function file(string $name, int $ageSeconds): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, 'x');
        touch($path, $this->now - $ageSeconds);

        return $path;
    }

    public function testSelectsOnlyFilesOlderThanTheLifetime(): void
    {
        $old    = $this->file('old', 8 * self::DAY);
        $stale  = $this->file('just-over', 7 * self::DAY + 60);
        $this->file('just-under', 7 * self::DAY - 60);
        $this->file('fresh', 0);

        $expired = SessionGc::expiredFiles($this->dir, 7 * self::DAY, $this->now);
        sort($expired);

        $this->assertEqualsCanonicalizing([$old, $stale], $expired);
    }

    public function testNeverSelectsDotfilesDirectoriesOrSymlinks(): void
    {
        $this->file('.gitkeep', 30 * self::DAY);
        $outside = $this->file('real-old', 30 * self::DAY);
        mkdir($this->dir . '/subdir');
        touch($this->dir . '/subdir', $this->now - 30 * self::DAY);
        symlink($outside, $this->dir . '/link-to-old');

        $expired = SessionGc::expiredFiles($this->dir, 7 * self::DAY, $this->now);

        $this->assertSame([$outside], $expired);
    }

    public function testPurgeDeletesExpiredFilesKeepsTheRestAndCounts(): void
    {
        $this->file('old-1', 10 * self::DAY);
        $this->file('old-2', 9 * self::DAY);
        $keep = $this->file('fresh', 60);
        $dot  = $this->file('.gitkeep', 10 * self::DAY);

        $result = SessionGc::purge($this->dir, 7 * self::DAY, $this->now);

        $this->assertSame(['removed' => 2, 'failed' => 0], $result);
        $this->assertFileExists($keep);
        $this->assertFileExists($dot);
        $this->assertFileDoesNotExist($this->dir . '/old-1');
        $this->assertFileDoesNotExist($this->dir . '/old-2');
    }

    public function testEmptyDirectoryRemovesNothing(): void
    {
        $this->assertSame(['removed' => 0, 'failed' => 0], SessionGc::purge($this->dir, self::DAY, $this->now));
    }
}
