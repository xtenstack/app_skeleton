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

    // Current backups run ~150MB, driven by legitimate/growing operational
    // data (leads, contacts, corelist_staging, peppol_participants) — this
    // isn't a tight bound, it's a tripwire: the excluded national-reference
    // datasets (gnaf.* + abn_lookup.abns/trading_names/asic_*) are multiple
    // GB each, so a future schema change that adds a new large reference
    // table without adding it to $excludeTableData would blow well past this
    // long before it became the multi-GB problem REQ-206 already happened
    // once (2026-09-13).
    private const SIZE_WARNING_BYTES = 750 * 1024 * 1024;

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

        if ($db->adapter === 'Sqlite') {
            $this->runSqlite($backupDir);

            return;
        }

        if ($db->adapter === 'Mysql') {
            $this->runMysql($backupDir);

            return;
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

        if ($size > self::SIZE_WARNING_BYTES) {
            echo sprintf(
                'WARNING: backup is %s, more than %s larger than the ~150MB this normally runs — '
                . 'check whether a new large table needs adding to $excludeTableData before this '
                . 'becomes another REQ-206.',
                $this->formatBytes($size),
                $this->formatBytes(self::SIZE_WARNING_BYTES)
            ) . PHP_EOL;
        }
    }

    /**
     * MySQL/MariaDB installs. Uses mysqldump (or mariadb-dump) when the host
     * lets PHP run it; many shared hosts disable exec() or don't ship the
     * client, so otherwise it writes the dump itself in PHP: CREATE TABLE
     * from SHOW CREATE TABLE, then batched INSERTs, read inside one
     * consistent-snapshot transaction. Either way the result is a gzipped
     * .sql file in backups/ that phpMyAdmin or `mysql` can import, with the
     * same 14-day retention as the Postgres path.
     */
    private function runMysql(string $backupDir): void
    {
        $db       = $this->config->database;
        $dumpFile = "{$backupDir}/{$db->dbname}-" . date('Ymd-His') . '.sql.gz';
        $method   = $this->mysqldumpBinary() !== null ? $this->mysqlDumpWithClient($dumpFile) : false;

        if ($method === false) {
            $this->mysqlDumpWithPhp($dumpFile);
            $method = 'php';
        }

        $this->pruneOldBackups($backupDir, (string) $db->dbname);

        echo sprintf('wrote %s (%s, via %s)', basename($dumpFile), $this->formatBytes((int) filesize($dumpFile)), $method) . PHP_EOL;
    }

    private function mysqldumpBinary(): ?string
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (!function_exists('exec') || in_array('exec', $disabled, true)) {
            return null;
        }

        foreach (['mysqldump', 'mariadb-dump'] as $binary) {
            $path = trim((string) @exec('command -v ' . $binary . ' 2>/dev/null'));

            if ($path !== '') {
                return $path;
            }
        }

        return null;
    }

    /** @return string|false the method used, or false to fall back to PHP */
    private function mysqlDumpWithClient(string $dumpFile)
    {
        $db = $this->config->database;

        // Credentials go in a private option file, not on the command line
        // where other users on a shared host could see them in `ps`.
        $optionFile = tempnam(sys_get_temp_dir(), 'mysqldump');
        chmod($optionFile, 0600);
        $lines = ['[client]', 'user="' . addcslashes((string) $db->username, '"\\') . '"', 'password="' . addcslashes((string) $db->password, '"\\') . '"'];

        if (!empty($db->socket)) {
            $lines[] = 'socket="' . addcslashes((string) $db->socket, '"\\') . '"';
        } else {
            $lines[] = 'host="' . addcslashes((string) $db->host, '"\\') . '"';
            $port = (int) $db->port === 5432 || empty($db->port) ? 3306 : (int) $db->port;
            $lines[] = 'port=' . $port;
        }

        file_put_contents($optionFile, implode("\n", $lines) . "\n");

        // MySQL's client writes a GTID line when the server uses GTIDs, which
        // a shared host's import would reject (needs SUPER); MariaDB's
        // client doesn't know the option.
        $binary  = (string) $this->mysqldumpBinary();
        $mariadb = stripos((string) @exec(escapeshellarg($binary) . ' --version 2>/dev/null'), 'mariadb') !== false;

        $cmd = sprintf(
            '%s --defaults-extra-file=%s --single-transaction --no-tablespaces --skip-lock-tables --default-character-set=utf8mb4%s %s 2>&1 | gzip -c > %s; echo "${PIPESTATUS[0]}"',
            escapeshellarg($binary),
            escapeshellarg($optionFile),
            $mariadb ? '' : ' --set-gtid-purged=OFF',
            escapeshellarg((string) $db->dbname),
            escapeshellarg($dumpFile)
        );

        exec('bash -c ' . escapeshellarg($cmd), $output, $exitCode);
        @unlink($optionFile);

        $status = (int) trim((string) end($output));

        if ($exitCode !== 0 || $status !== 0) {
            @unlink($dumpFile);

            return false;
        }

        return 'mysqldump';
    }

    /**
     * @psalm-suppress UndefinedConstant PDO::MYSQL_* only exists where
     *                  pdo_mysql is loaded; this only runs for Mysql.
     */
    private function mysqlDumpWithPhp(string $dumpFile): void
    {
        $db  = $this->config->database;
        $dsn = 'mysql:dbname=' . $db->dbname . ';charset=utf8mb4';
        $dsn .= !empty($db->socket)
            ? ';unix_socket=' . $db->socket
            : ';host=' . $db->host . ';port=' . ((int) $db->port === 5432 || empty($db->port) ? 3306 : (int) $db->port);

        // A connection of its own: under `./run cron run` the shared one is
        // busy with the cron runner's own queries.
        $pdo = new \PDO($dsn, (string) $db->username, (string) $db->password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, SESSION time_zone = '+00:00'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        $out = gzopen($dumpFile, 'wb6');
        gzwrite($out, "-- Dump of `{$db->dbname}` written by ./run backup run (PHP fallback), " . gmdate('Y-m-d H:i:s') . " UTC\n"
            . "SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_COLUMN, 0);

        foreach ($tables as $table) {
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(\PDO::FETCH_NUM)[1];
            gzwrite($out, 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . "`;\n" . $create . ";\n\n");

            // Generated columns can't be given a value on restore, so list
            // the real columns explicitly and leave those out.
            $columnsStmt = $pdo->prepare(
                "SELECT column_name FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ? AND extra NOT LIKE '%GENERATED%'
                  ORDER BY ordinal_position"
            );
            $columnsStmt->execute([$table]);
            $columns = $columnsStmt->fetchAll(\PDO::FETCH_COLUMN, 0);
            $columnsStmt->closeCursor();

            $quoted = implode(', ', array_map(static fn ($c) => '`' . str_replace('`', '``', $c) . '`', $columns));
            $insert = 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . $quoted . ') VALUES ';
            $rows   = $pdo->query('SELECT ' . $quoted . ' FROM `' . str_replace('`', '``', $table) . '`', \PDO::FETCH_NUM);
            $batch  = [];

            foreach ($rows as $row) {
                $batch[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';

                if (count($batch) >= 200) {
                    gzwrite($out, $insert . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }

            if ($batch) {
                gzwrite($out, $insert . implode(",\n", $batch) . ";\n");
            }

            gzwrite($out, "\n");
        }

        gzwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
        gzclose($out);
        $pdo->exec('COMMIT');
    }

    /**
     * SQLite installs (shared-host demos): no pg_dump. VACUUM INTO writes a
     * consistent, compacted copy of the main file and of every ATTACHed
     * schema file (see App_skeleton\Db\SqliteAdapter) while the site stays
     * up; each copy is then gzipped. Same backups/ directory and 14-day
     * retention as the Postgres path.
     */
    private function runSqlite(string $backupDir): void
    {
        $timestamp = date('Ymd-His');
        $files     = ['main' => (string) $this->config->database->dbname];

        if ($this->db instanceof \App_skeleton\Db\SqliteAdapter) {
            $files += $this->db->getAttachedSchemas();
        }

        // VACUUM refuses to run on a connection with a statement still open,
        // and under `./run cron run` the shared connection is mid-way through
        // CronRunner's job list ("cannot VACUUM - SQL statements in
        // progress"). Copy each file through its own short-lived connection.
        foreach ($files as $schema => $source) {
            $base    = preg_replace('/\.(sqlite3?|db)$/i', '', basename($source));
            $copy    = "{$backupDir}/{$base}-{$timestamp}.sqlite";
            $gzipped = $copy . '.gz';

            $pdo = new \PDO('sqlite:' . $source, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('VACUUM INTO ' . $pdo->quote($copy));
            $pdo = null;

            $in  = fopen($copy, 'rb');
            $out = gzopen($gzipped, 'wb6');

            while (!feof($in)) {
                gzwrite($out, (string) fread($in, 1 << 20));
            }

            fclose($in);
            gzclose($out);
            unlink($copy);

            $cutoff = time() - self::RETENTION_DAYS * 86400;

            foreach (glob("{$backupDir}/{$base}-*.sqlite.gz") ?: [] as $old) {
                if (filemtime($old) < $cutoff) {
                    unlink($old);
                }
            }

            echo sprintf('wrote %s (%s)', basename($gzipped), $this->formatBytes((int) filesize($gzipped))) . PHP_EOL;
        }
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
