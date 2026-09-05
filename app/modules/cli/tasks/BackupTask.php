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

        // This instance shares its database with bulk ABR/ASIC reference
        // data (~20.4M abns rows, ~7.5M trading_names, plus the ASIC
        // registers) that is 100% re-derivable from the source XML/CSV
        // already stored in ~/Developer/xten/marketing-data/ — see
        // marketing-data/abr/README-LOAD.md (load_abn_local.py) and
        // marketing-data/asic/load_asic.sql. Dumping that data on every
        // backup would make each run large and slow for no benefit: schema
        // is kept (so views joining against these tables still restore
        // correctly), only the bulk row data is skipped. Restore path:
        // restore this dump, then re-run the two loaders against the
        // already-downloaded source files to repopulate them — faster
        // than re-downloading or re-dumping millions of unchanged rows
        // every day (2026-08-27).
        //
        // gnaf.* (Geoscape/PSMA's public G-NAF address dataset) added
        // 2026-09-05 (REQ-207) — same order-of-magnitude problem
        // (address_view/address_detail/address_default_geocode/
        // street_locality alone are ~8.4GB) and the same public-source
        // justification in principle, but UNLIKE abr/asic there is no
        // retained source extract or loader script for it anywhere on
        // this machine as of this date (checked ~/Developer/xten/marketing-data/
        // directly — only abr/ and asic/ exist there). Excluded anyway,
        // deliberately, because the unrotated growth was actively
        // breaking backups/deploys (see REQ-206) — Travis's explicit
        // call, accepting the restore-time gap over the disk-space
        // incident. If this instance is ever actually restored: G-NAF is
        // a public dataset (Geoscape/PSMA, released quarterly) — download
        // a fresh extract and write a loader before assuming this data
        // comes back from the dump.
        $excludeTableData = [
            'abn_lookup.abns',
            'abn_lookup.trading_names',
            'abn_lookup.dgr',
            'abn_lookup.asic_companies',
            'abn_lookup.asic_business_names',
            'gnaf.address_view',
            'gnaf.address_detail',
            'gnaf.address_default_geocode',
            'gnaf.street_locality',
        ];

        $excludeFlags = '';

        foreach ($excludeTableData as $table) {
            $excludeFlags .= ' --exclude-table-data=' . escapeshellarg($table);
        }

        $cmd = sprintf(
            'pg_dump -h %s -p %s -U %s -d %s --no-owner --no-privileges%s > %s 2>&1',
            escapeshellarg($db->host),
            escapeshellarg((string) $db->port),
            escapeshellarg($db->username),
            escapeshellarg($db->dbname),
            $excludeFlags,
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
