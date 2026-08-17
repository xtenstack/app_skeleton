<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

/**
 * Usage: ./run backup run
 *
 * Runs inside the app container itself (postgresql-client-18 + gzip,
 * see Dockerfile) rather than depending on a separate host-crontab
 * script — so a fresh instance only needs the one
 * `* * * * * ./run cron run` OS crontab entry (see CronTask's docblock)
 * to get both audit archival and backups, driven by the same
 * cron_jobs/CronRunner system (REQ-077). Writes to /app/backups
 * (bind-mounted to ./backups on the host, docker-compose.yml) — the
 * same directory docker/backup-db.sh already used, for continuity on
 * any instance migrating from that script to this.
 */
class BackupTask extends \Phalcon\Cli\Task
{
    private const RETENTION_DAYS = 14;

    public function mainAction(): void
    {
        echo 'Usage: ./run backup run' . PHP_EOL;
    }

    /**
     * @return void
     */
    public function runAction()
    {
        $db        = $this->config->database;
        $backupDir = BASE_PATH . '/backups';

        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            throw new \RuntimeException("Could not create backup directory: {$backupDir}");
        }

        $timestamp = date('Ymd-His');
        $dumpFile  = "{$backupDir}/{$db->dbname}-{$timestamp}.sql.gz";
        $tmpSql    = "{$backupDir}/.{$db->dbname}-{$timestamp}.sql.tmp";

        putenv("PGPASSWORD={$db->password}");

        $cmd = sprintf(
            'pg_dump -h %s -p %s -U %s -d %s --no-owner --no-privileges > %s 2>&1',
            escapeshellarg($db->host),
            escapeshellarg((string) $db->port),
            escapeshellarg($db->username),
            escapeshellarg($db->dbname),
            escapeshellarg($tmpSql)
        );

        exec($cmd, $output, $exitCode);
        putenv('PGPASSWORD');

        if ($exitCode !== 0) {
            $errorOutput = is_file($tmpSql) ? file_get_contents($tmpSql) : implode("\n", $output);
            @unlink($tmpSql);

            throw new \RuntimeException('pg_dump failed: ' . trim((string) $errorOutput));
        }

        exec(sprintf('gzip -c %s > %s', escapeshellarg($tmpSql), escapeshellarg($dumpFile)), $gzipOutput, $gzipExit);
        @unlink($tmpSql);

        if ($gzipExit !== 0) {
            throw new \RuntimeException('gzip failed: ' . implode("\n", $gzipOutput));
        }

        $size = filesize($dumpFile);
        $this->pruneOldBackups($backupDir, $db->dbname);

        echo sprintf('wrote %s (%s)', basename($dumpFile), $this->formatBytes((int) $size)) . PHP_EOL;
    }

    private function pruneOldBackups(string $backupDir, string $dbName): void
    {
        $cutoff = time() - self::RETENTION_DAYS * 86400;

        foreach (glob("{$backupDir}/{$dbName}-*.sql.gz") ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes}B";
        }

        $kb = $bytes / 1024;

        if ($kb < 1024) {
            return sprintf('%.1fK', $kb);
        }

        return sprintf('%.1fM', $kb / 1024);
    }
}
