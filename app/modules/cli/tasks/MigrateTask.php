<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

/**
 * Usage: ./run migrate run
 *        ./run migrate status
 *
 * Plain-SQL migrations, one directory per database adapter under
 * db/migrations/<adapter>/*.sql (e.g. db/migrations/postgresql/), applied
 * in filename order for whichever adapter the running config uses — see
 * config.database.adapter. This exists so the skeleton can eventually be
 * deployed against MySQL or SQLite too without touching this runner, just
 * by adding sibling migration files (postgresql/, sqlite/ and mysql/ exist).
 *
 * Module-aware: besides the base engine's own db/migrations/<adapter>/,
 * every Composer-installed module package discovered by ModuleManager that
 * declares a 'migrations' path in its module.json gets its own
 * <migrations>/<adapter>/*.sql applied too, tracked separately by
 * (module, version) so two modules' "001_....sql" files can't collide.
 * Base migrations always apply first (module tables may reference base
 * tables like users), then modules in alphabetical order by key — safe
 * since the isolation requirement means inter-module ordering shouldn't
 * matter.
 *
 * Applied filenames are recorded in schema_migrations (created here if
 * missing) so a re-run only applies what's new. Each migration runs inside
 * its own transaction — a failure rolls back that file only, leaving
 * already-applied ones in place, and stops before touching later files.
 */
class MigrateTask extends \Phalcon\Cli\Task
{
    public function mainAction(): void
    {
        echo 'Usage: ./run migrate run | ./run migrate status' . PHP_EOL;
    }

    /**
     * @return void
     */
    public function runAction()
    {
        $this->ensureMigrationsTable();

        $applied = $this->appliedVersions();
        $pending = $this->pendingMigrations($applied);

        if (!$pending) {
            echo 'No pending migrations.' . PHP_EOL;

            return;
        }

        foreach ($pending as $qualified => $migration) {
            echo "  applying {$qualified}..." . PHP_EOL;

            $sql = (string) file_get_contents($migration['path']);

            // MySQL/MariaDB commit implicitly on every DDL statement, so a
            // transaction around a migration protects nothing there (and
            // PDO then fails the COMMIT with "no active transaction"). On
            // MySQL a failed file can leave its earlier statements applied;
            // see docs/RUNBOOK-SHARED-HOST.md.
            $transactional = $this->adapter() !== 'mysql';

            if ($transactional) {
                $this->db->begin();
            }

            try {
                $this->executeScript($sql);
                $this->db->execute(
                    'INSERT INTO schema_migrations (module, version) VALUES (:module, :version)',
                    ['module' => $migration['module'], 'version' => $migration['version']]
                );

                if ($transactional) {
                    $this->db->commit();
                }
            } catch (\Throwable $e) {
                if ($transactional) {
                    $this->db->rollback();
                }

                echo "  FAILED on {$qualified}: " . $e->getMessage() . PHP_EOL;
                echo '  Stopped — migrations after this one were not applied.' . PHP_EOL;

                return;
            }
        }

        echo 'Migrations complete (' . count($pending) . ' applied).' . PHP_EOL;
    }

    public function statusAction(): void
    {
        $this->ensureMigrationsTable();

        $applied = $this->appliedVersions();
        $pending = $this->pendingMigrations($applied);

        foreach ($this->allMigrationVersions() as $qualified) {
            $marker = in_array($qualified, $applied, true) ? '[applied]' : '[pending]';
            echo "  {$marker} {$qualified}" . PHP_EOL;
        }

        echo count($pending) . ' pending, ' . count($applied) . ' applied.' . PHP_EOL;
    }

    private function ensureMigrationsTable(): void
    {
        if ($this->adapter() === 'mysql') {
            // DATETIME rather than TIMESTAMP: no 2038 limit and no MariaDB
            // auto-update quirks. MySQL has no ADD COLUMN IF NOT EXISTS
            // (MariaDB does), so check information_schema like SQLite does.
            $this->db->execute(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    version     VARCHAR(255) NOT NULL PRIMARY KEY,
                    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $hasModule = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'schema_migrations' AND column_name = 'module'"
            );

            if (!$hasModule) {
                $this->db->execute("ALTER TABLE schema_migrations ADD COLUMN module VARCHAR(100) NOT NULL DEFAULT 'base'");
            }

            return;
        }

        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version     VARCHAR(255) PRIMARY KEY,
                applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        // Upgrade path for installs migrated before module-awareness existed
        // — existing rows have no way to have come from anything but the
        // base engine, so they read back correctly as module='base' with no
        // data backfill needed (see qualified-version scheme below).
        if ($this->adapter() === 'sqlite') {
            // SQLite has no ADD COLUMN IF NOT EXISTS — check the column list.
            $columns = array_column($this->db->fetchAll('PRAGMA table_info(schema_migrations)'), 'name');
            if (!in_array('module', $columns, true)) {
                $this->db->execute("ALTER TABLE schema_migrations ADD COLUMN module VARCHAR(100) NOT NULL DEFAULT 'base'");
            }

            return;
        }

        $this->db->execute(
            "ALTER TABLE schema_migrations ADD COLUMN IF NOT EXISTS module VARCHAR(100) NOT NULL DEFAULT 'base'"
        );
    }

    /**
     * Run a whole migration file. pdo_pgsql executes every statement in a
     * multi-statement string, but pdo_sqlite's prepare() compiles only the
     * first one and silently drops the rest — so SQLite goes through
     * PDO::exec(), which runs them all.
     */
    private function executeScript(string $sql): void
    {
        // pdo_mysql runs a multi-statement string but only reports an error
        // from the first statement, so MySQL goes one statement at a time.
        if ($this->adapter() === 'mysql') {
            foreach (\App_skeleton\Db\MysqlAdapter::splitStatements($sql) as $statement) {
                $this->db->getInternalHandler()->exec($statement);
            }

            return;
        }

        if ($this->adapter() === 'sqlite') {
            $this->db->getInternalHandler()->exec($sql);

            return;
        }

        $this->db->execute($sql);
    }

    private function adapter(): string
    {
        return strtolower((string) $this->config->database->adapter);
    }

    private function baseMigrationsDir(): string
    {
        return APP_PATH . '/../db/migrations/' . $this->adapter();
    }

    /**
     * Every migration file, base engine plus discovered modules, keyed by a
     * qualified version id. Base ids are the plain filename-derived version
     * ("001_initial_schema") — unchanged from before module-awareness, so
     * rows applied prior to this still match. Module ids are prefixed
     * ("lms:001_initial_schema") so they can never collide with base's or
     * another module's. Insertion order is base (already filename-sorted)
     * then modules alphabetically by key, each internally filename-sorted —
     * that's the actual apply order runAction() walks.
     */
    private function migrationFiles(): array
    {
        $files = [];

        foreach ($this->filesInDir($this->baseMigrationsDir()) as $version => $path) {
            $files[$version] = ['module' => 'base', 'version' => $version, 'path' => $path];
        }

        $modules = $this->moduleManager->discover();
        ksort($modules);

        foreach ($modules as $moduleKey => $manifest) {
            if (empty($manifest['migrations'])) {
                continue;
            }

            $dir = rtrim((string) $manifest['installPath'], '/\\')
                . '/' . ltrim((string) $manifest['migrations'], '/\\')
                . '/' . $this->adapter();

            foreach ($this->filesInDir($dir) as $version => $path) {
                $qualified = $moduleKey . ':' . $version;

                $files[$qualified] = ['module' => $moduleKey, 'version' => $version, 'path' => $path];
            }
        }

        return $files;
    }

    private function filesInDir(string $dir): array
    {
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);

        $byVersion = [];

        foreach ($files as $file) {
            $byVersion[basename($file, '.sql')] = $file;
        }

        return $byVersion;
    }

    private function allMigrationVersions(): array
    {
        return array_keys($this->migrationFiles());
    }

    private function appliedVersions(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT module, version FROM schema_migrations ORDER BY module, version',
            \Phalcon\Db\Enum::FETCH_ASSOC
        );

        $applied = [];

        foreach ($rows as $row) {
            $applied[] = $row['module'] === 'base' ? $row['version'] : $row['module'] . ':' . $row['version'];
        }

        return $applied;
    }

    private function pendingMigrations(array $applied): array
    {
        return array_diff_key($this->migrationFiles(), array_flip($applied));
    }
}
