<?php
declare(strict_types=1);

namespace App_skeleton\Db;

use Phalcon\Db\ResultInterface;

/**
 * The SQLite connection used when config.database.adapter is 'Sqlite'
 * (shared-host installs, see docs/RUNBOOK-SHARED-HOST.md).
 * services.php only builds this for SQLite; a Postgres install never
 * loads the class, so nothing here can change Postgres behaviour.
 *
 * On top of the stock Phalcon adapter it does three things:
 *
 * 1. Postgres schemas become ATTACHed databases. Each name listed in
 *    config.database.schemas (none by default) is attached under the same
 *    name from its own file next to the main one (schemas => ['sales']:
 *    app.sqlite -> app.sales.sqlite), so `sales.orders` in module SQL
 *    resolves with no rewriting at all. SqliteDialect makes Phalcon's
 *    model metadata look in the right attached file. Limits that come
 *    with it: a view or trigger can only reference tables in its own
 *    file, and there are no foreign keys across files.
 *
 * 2. Postgres functions module SQL relies on are registered as PHP
 *    functions: now(), greatest(), least(), split_part(), btrim(),
 *    left(), right(), initcap(), date_trunc(), md5(), similarity() and
 *    regexp()/REGEXP (case-sensitive), iregexp() (for ~*) and
 *    regexp_substr() (for substring(x, 'regex')), plus
 *    pg_try_advisory_lock()/pg_advisory_unlock() backed by flock() on a
 *    lock file next to the database (<db file>.advisory-lock-<key>), used
 *    by CronRunner's overlap guard. A lock is held until unlocked or the
 *    PHP process ends, like a Postgres session-level lock.
 *
 * 3. Raw SQL passes through PgSqlTranslator (ILIKE, ::casts, FOR UPDATE,
 *    INTERVAL arithmetic). See that class for exactly what it does and
 *    does not rewrite.
 *
 * @psalm-suppress MethodSignatureMismatch Phalcon's stubs type these
 *                  parameters as mixed; the real signatures are typed.
 */
class SqliteAdapter extends \Phalcon\Db\Adapter\Pdo\Sqlite
{
    /** No schemas are attached unless config.database.schemas lists them. */
    public const DEFAULT_SCHEMAS = [];

    /** @var array<string, string> schema name => file path */
    private array $attachments = [];

    /** @var array<string, resource> advisory lock key => open, flock()ed handle */
    private static array $advisoryLocks = [];

    /** Lock files are '<main db file>.advisory-lock-<key>', so the db/ .gitignore rule covers them. */
    private static string $lockPrefix = '';

    public function __construct(array $descriptor)
    {
        $schemas = $descriptor['schemas'] ?? self::DEFAULT_SCHEMAS;
        unset($descriptor['schemas']);

        $this->attachments = self::attachmentPaths((string) $descriptor['dbname'], (array) $schemas);
        self::$lockPrefix  = $descriptor['dbname'] === ':memory:' ? sys_get_temp_dir() . '/sqlite' : (string) $descriptor['dbname'];

        $descriptor['dialectClass'] = SqliteDialect::class;

        parent::__construct($descriptor);
    }

    /**
     * @param string[]|array<string, string> $schemas list of names, or name => file path
     *
     * @return array<string, string>
     */
    public static function attachmentPaths(string $mainFile, array $schemas): array
    {
        $paths = [];
        $base  = preg_replace('/\.(sqlite3?|db)$/i', '', $mainFile);

        foreach ($schemas as $key => $value) {
            if (is_int($key)) {
                $paths[(string) $value] = $mainFile === ':memory:' ? ':memory:' : $base . '.' . $value . '.sqlite';
            } else {
                $paths[$key] = (string) $value;
            }
        }

        return $paths;
    }

    public function connect(array $descriptor = []): void
    {
        parent::connect($descriptor);

        $pdo = $this->getInternalHandler();

        // Wait rather than fail when the cron runner and a web request write
        // at the same moment.
        $pdo->exec('PRAGMA busy_timeout = 5000');

        foreach ($this->attachments as $schema => $path) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $schema)) {
                continue;
            }

            $pdo->exec('ATTACH DATABASE ' . $pdo->quote($path) . ' AS "' . $schema . '"');
        }

        self::registerFunctions($pdo);
    }

    public function query(string $sqlStatement, array $bindParams = [], array $bindTypes = []): ResultInterface|bool
    {
        [$bindParams, $bindTypes] = self::booleansAsIntegers($bindParams, $bindTypes);

        return parent::query(PgSqlTranslator::translate($sqlStatement), $bindParams, $bindTypes);
    }

    public function execute(string $sqlStatement, array $bindParams = [], array $bindTypes = []): bool
    {
        [$bindParams, $bindTypes] = self::booleansAsIntegers($bindParams, $bindTypes);

        return parent::execute(PgSqlTranslator::translate($sqlStatement), $bindParams, $bindTypes);
    }

    /**
     * pdo_sqlite binds PHP false as '' (an empty string), so a model saving
     * `$row->enabled = false` stored '' and the next save of that row failed
     * Phalcon's not-null check ("enabled is required"): `./run modules sync`
     * on any disabled module. Bind booleans as 1/0 instead, as Postgres
     * stores them.
     *
     * @return array{0: array, 1: array}
     */
    private static function booleansAsIntegers(array $bindParams, array $bindTypes): array
    {
        foreach ($bindParams as $key => $value) {
            if (is_bool($value)) {
                $bindParams[$key] = (int) $value;

                if (($bindTypes[$key] ?? null) === \Phalcon\Db\Column::BIND_PARAM_BOOL) {
                    $bindTypes[$key] = \Phalcon\Db\Column::BIND_PARAM_INT;
                }
            }
        }

        return [$bindParams, $bindTypes];
    }

    /**
     * Phalcon's SQLite describeColumns() drops a column default of 0 (it
     * tests the PRAGMA's dflt_value with empty(), and "0" is empty), so
     * a NOT NULL DEFAULT 0 column looks like it has no default and model
     * saves fail with "<column> is required". Put those defaults back.
     */
    public function describeColumns(string $table, ?string $schema = null): array
    {
        $columns = parent::describeColumns($table, $schema);
        $raw     = [];

        foreach ($this->fetchAll($this->getDialect()->describeColumns($table, $schema), \Phalcon\Db\Enum::FETCH_ASSOC) as $row) {
            $raw[$row['name']] = $row['dflt_value'];
        }

        foreach ($columns as $i => $column) {
            $default = $raw[$column->getName()] ?? null;

            if ($column->getDefault() !== null || $default === null || !in_array(trim((string) $default, "'"), ['0', ''], true)) {
                continue;
            }

            $definition = [
                'type'          => $column->getType(),
                'notNull'       => $column->isNotNull(),
                'autoIncrement' => $column->isAutoIncrement(),
                'primary'       => $column->isPrimary(),
                'first'         => $column->isFirst(),
                'after'         => $column->getAfterPosition(),
                'unsigned'      => $column->isUnsigned(),
                'isNumeric'     => $column->isNumeric(),
                'bindType'      => $column->getBindType(),
                'default'       => $default,
            ];

            // Column's constructor rejects a scale (even 0) on types that
            // have none, so only carry size/scale over when they're set.
            if ($column->getSize()) {
                $definition['size'] = $column->getSize();
            }

            if ($column->getScale()) {
                $definition['scale'] = $column->getScale();
            }

            $columns[$i] = new \Phalcon\Db\Column($column->getName(), $definition);
        }

        return $columns;
    }

    /** @return array<string, string> */
    public function getAttachedSchemas(): array
    {
        return $this->attachments;
    }

    public static function registerFunctions(\PDO $pdo): void
    {
        $fns = [
            'now'       => [static fn () => date('Y-m-d H:i:s'), 0],
            'greatest'  => [static function (...$a) {
                $a = array_filter($a, static fn ($v) => $v !== null);

                return $a ? max($a) : null;
            }, -1],
            'least'     => [static function (...$a) {
                $a = array_filter($a, static fn ($v) => $v !== null);

                return $a ? min($a) : null;
            }, -1],
            'split_part' => [static function ($s, $d, $n) {
                if ($s === null) {
                    return null;
                }
                $parts = explode((string) $d, (string) $s);

                return $parts[(int) $n - 1] ?? '';
            }, 3],
            'btrim'     => [static fn ($s, $c = " \t\n\r") => $s === null ? null : trim((string) $s, (string) $c), -1],
            'left'      => [static fn ($s, $n) => $s === null ? null : ((int) $n >= 0 ? mb_substr((string) $s, 0, (int) $n) : mb_substr((string) $s, 0, (int) $n)), 2],
            'right'     => [static fn ($s, $n) => $s === null ? null : ((int) $n >= 0 ? ((int) $n === 0 ? '' : mb_substr((string) $s, -(int) $n)) : mb_substr((string) $s, -(int) $n)), 2],
            'initcap'   => [static fn ($s) => $s === null ? null : ucwords(mb_strtolower((string) $s)), 1],
            'md5'       => [static fn ($s) => $s === null ? null : md5((string) $s), 1],
            'date_trunc' => [static function ($unit, $ts) {
                if ($ts === null) {
                    return null;
                }
                $t = strtotime((string) $ts);
                $f = ['year' => 'Y-01-01 00:00:00', 'month' => 'Y-m-01 00:00:00', 'day' => 'Y-m-d 00:00:00',
                    'hour' => 'Y-m-d H:00:00', 'minute' => 'Y-m-d H:i:00'][strtolower((string) $unit)] ?? 'Y-m-d H:i:s';
                if (strtolower((string) $unit) === 'week') {
                    $t = strtotime('monday this week', $t);
                    $f = 'Y-m-d 00:00:00';
                }

                return date($f, $t);
            }, 2],
            'similarity' => [static fn ($a, $b) => self::similarity((string) $a, (string) $b), 2],
            'regexp'    => [static fn ($pattern, $value) => $value === null ? null : (int) preg_match('/' . str_replace('/', '\/', (string) $pattern) . '/u', (string) $value), 2],
            'iregexp'   => [static fn ($pattern, $value) => $value === null ? null : (int) preg_match('/' . str_replace('/', '\/', (string) $pattern) . '/iu', (string) $value), 2],
            // Postgres substring(value FROM 'regex'): the first capture group
            // if the pattern has one, else the whole match; NULL if none.
            'regexp_substr' => [static function ($value, $pattern) {
                if ($value === null || !preg_match('/' . str_replace('/', '\/', (string) $pattern) . '/u', (string) $value, $m)) {
                    return null;
                }

                return $m[1] ?? $m[0];
            }, 2],
        ];

        $fns['pg_try_advisory_lock'] = [static fn ($key) => self::advisoryLock((string) $key) ? 1 : 0, 1];
        $fns['pg_advisory_unlock']   = [static fn ($key) => self::advisoryUnlock((string) $key) ? 1 : 0, 1];

        foreach ($fns as $name => [$callback, $argc]) {
            $pdo->sqliteCreateFunction($name, $callback, $argc);
        }
    }

    private static function advisoryLock(string $key): bool
    {
        if (isset(self::$advisoryLocks[$key])) {
            return true;
        }

        $prefix = self::$lockPrefix !== '' ? self::$lockPrefix : sys_get_temp_dir() . '/sqlite';
        $handle = @fopen($prefix . '.advisory-lock-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $key), 'c');

        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            return false;
        }

        self::$advisoryLocks[$key] = $handle;

        return true;
    }

    private static function advisoryUnlock(string $key): bool
    {
        if (!isset(self::$advisoryLocks[$key])) {
            return false;
        }

        flock(self::$advisoryLocks[$key], LOCK_UN);
        fclose(self::$advisoryLocks[$key]);
        unset(self::$advisoryLocks[$key]);

        return true;
    }

    /** pg_trgm's similarity(): shared trigrams / all trigrams, on lower-cased words. */
    public static function similarity(string $a, string $b): float
    {
        $ta = self::trigrams($a);
        $tb = self::trigrams($b);

        if (!$ta || !$tb) {
            return 0.0;
        }

        $shared = count(array_intersect_key($ta, $tb));

        return $shared / (count($ta) + count($tb) - $shared);
    }

    /** @return array<string, true> */
    private static function trigrams(string $s): array
    {
        $out = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $w = '  ' . $word . ' ';
            $n = mb_strlen($w);

            for ($i = 0; $i < $n - 2; $i++) {
                $out[mb_substr($w, $i, 3)] = true;
            }
        }

        return $out;
    }
}
