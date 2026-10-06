<?php
declare(strict_types=1);

namespace App_skeleton;

/**
 * Removes expired session files. Nothing else does: PHP's own collection is
 * off in the Docker image (session.gc_probability = 0), and a session file
 * is rewritten on every request that uses it, so its modified time is the
 * last time anyone used that session. Run daily by the "Session clean-up"
 * cron job (./run session gc).
 */
class SessionGc
{
    /**
     * Session files in $dir not modified in the last $maxAge seconds. Only
     * plain files qualify: dotfiles (.gitkeep), subdirectories and symlinks
     * are never touched.
     *
     * @return string[] full paths
     */
    public static function expiredFiles(string $dir, int $maxAge, ?int $now = null): array
    {
        $cutoff  = ($now ?? time()) - $maxAge;
        $expired = [];

        foreach (scandir($dir) ?: [] as $name) {
            if ($name[0] === '.') {
                continue;
            }

            $path = $dir . '/' . $name;

            if (is_link($path) || !is_file($path)) {
                continue;
            }

            $modified = filemtime($path);

            if ($modified !== false && $modified < $cutoff) {
                $expired[] = $path;
            }
        }

        return $expired;
    }

    /**
     * Deletes what expiredFiles() selects.
     *
     * @return array{removed: int, failed: int}
     */
    public static function purge(string $dir, int $maxAge, ?int $now = null): array
    {
        $removed = 0;
        $failed  = 0;

        foreach (self::expiredFiles($dir, $maxAge, $now) as $path) {
            // A request can have rewritten or removed the file since it was
            // listed; either way it is no longer this run's to count.
            if (@unlink($path)) {
                $removed++;
            } elseif (file_exists($path)) {
                $failed++;
            }
        }

        return ['removed' => $removed, 'failed' => $failed];
    }
}
