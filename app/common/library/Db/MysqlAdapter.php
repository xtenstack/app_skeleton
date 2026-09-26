<?php
declare(strict_types=1);

namespace App_skeleton\Db;

use Phalcon\Db\ResultInterface;

/**
 * The MySQL/MariaDB connection used when config.database.adapter is
 * 'Mysql' (see docs/RUNBOOK-SHARED-HOST.md). services.php only builds this
 * for MySQL; Postgres and SQLite installs never load the class.
 *
 * On top of the stock Phalcon adapter it:
 *
 * 1. Sets the session up the same way every time, whatever the server's
 *    defaults: utf8mb4 with the utf8mb4_unicode_ci collation (which both
 *    MySQL 8 and MariaDB have, so a dump moves between them), strict SQL
 *    mode, and the time zone PHP is using, so NOW()/CURRENT_TIMESTAMP and
 *    PHP's date() agree.
 * 2. Passes raw SQL through MysqlSqlTranslator (ILIKE, ::casts, INTERVAL
 *    literals, ~*, NULLS FIRST/LAST).
 * 3. Binds PHP booleans as 1/0.
 *
 * It also provides splitStatements(), which MigrateTask uses to run a
 * migration file one statement at a time: pdo_mysql executes a
 * multi-statement string but only reports an error from the first
 * statement, so a failure later in the file would go unnoticed.
 *
 * @psalm-suppress MethodSignatureMismatch Phalcon's stubs type these
 *                  parameters as mixed; the real signatures are typed.
 */
class MysqlAdapter extends \Phalcon\Db\Adapter\Pdo\Mysql
{
    public const SQL_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /**
     * @psalm-suppress UndefinedConstant PDO's MYSQL_* constants only exist
     *                  where pdo_mysql is loaded, which a Postgres/SQLite
     *                  build (and CI) may not have; this class only loads
     *                  when the adapter is Mysql.
     */
    public function __construct(array $descriptor)
    {
        $descriptor['charset'] ??= 'utf8mb4';
        $collation = (string) ($descriptor['collation'] ?? 'utf8mb4_unicode_ci');
        unset($descriptor['collation']);

        $options = (array) ($descriptor['options'] ?? []);
        $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = sprintf(
            "SET NAMES %s COLLATE %s, SESSION sql_mode = '%s', SESSION time_zone = '%s'",
            preg_replace('/[^a-z0-9_]/i', '', (string) $descriptor['charset']),
            preg_replace('/[^a-z0-9_]/i', '', $collation),
            self::SQL_MODE,
            date('P')
        );
        $descriptor['options'] = $options;

        parent::__construct($descriptor);
    }

    public function query(string $sqlStatement, array $bindParams = [], array $bindTypes = []): ResultInterface|bool
    {
        [$bindParams, $bindTypes] = self::booleansAsIntegers($bindParams, $bindTypes);

        return parent::query(MysqlSqlTranslator::translate($sqlStatement), $bindParams, $bindTypes);
    }

    public function execute(string $sqlStatement, array $bindParams = [], array $bindTypes = []): bool
    {
        [$bindParams, $bindTypes] = self::booleansAsIntegers($bindParams, $bindTypes);

        return parent::execute(MysqlSqlTranslator::translate($sqlStatement), $bindParams, $bindTypes);
    }

    /**
     * Split a migration file into statements on semicolons outside quotes
     * and comments. Comments are dropped. (No DELIMITER support: the
     * migrations don't define routines or triggers.)
     *
     * @return string[]
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current    = '';
        $len        = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $ch   = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($ch === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $i   = $end === false ? $len : $end;
                $current .= "\n";

                continue;
            }

            if ($ch === '#') {
                $end = strpos($sql, "\n", $i);
                $i   = $end === false ? $len : $end;
                $current .= "\n";

                continue;
            }

            if ($ch === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i   = $end === false ? $len : $end + 1;

                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $current .= $ch;

                for ($i++; $i < $len; $i++) {
                    $current .= $sql[$i];

                    if ($sql[$i] === '\\' && $quote !== '`' && $i + 1 < $len) {
                        $current .= $sql[++$i];

                        continue;
                    }

                    if ($sql[$i] === $quote) {
                        if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                            $current .= $sql[++$i];

                            continue;
                        }

                        break;
                    }
                }

                continue;
            }

            if ($ch === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }

                $current = '';

                continue;
            }

            $current .= $ch;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }

    /**
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
}
