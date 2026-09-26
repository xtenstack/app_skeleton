<?php
declare(strict_types=1);

namespace App_skeleton\Db;

/**
 * MySQL/MariaDB counterpart of PgSqlTranslator: rewrites the purely
 * syntactic Postgres-isms in raw SQL (and in the SQL Phalcon generates for
 * PHQL ILIKE) so the same query text runs on MySQL 8.0+ and MariaDB 10.6+.
 * Used by MysqlAdapter only; the Postgres and SQLite connections never
 * load it.
 *
 *   - ILIKE / NOT ILIKE  -> LIKE / NOT LIKE (the tables use a
 *                           case-insensitive utf8mb4_unicode_ci collation)
 *   - expr::type         -> CAST(expr AS CHAR|SIGNED|DECIMAL|DATE|DATETIME),
 *                           dropped for bool/json/regclass/uuid casts
 *   - INTERVAL 'N unit'  -> INTERVAL N UNIT
 *   - a ~* b / a ~ b     -> a REGEXP b (case-insensitive under the
 *                           collation, so ~ loses its case sensitivity)
 *   - x [ASC|DESC] NULLS FIRST|LAST -> MySQL's equivalent ordering
 *
 * FOR UPDATE and `UPDATE t alias SET` are valid MySQL and left alone.
 * Anything with different semantics (RETURNING, DISTINCT ON, arrays,
 * IS DISTINCT FROM, stored functions) is NOT translated: code branches on
 * $db->getType() === 'mysql' for those.
 */
class MysqlSqlTranslator extends PgSqlTranslator
{
    private const MYSQL_CASTS = [
        'text' => 'CHAR', 'varchar' => 'CHAR', 'character varying' => 'CHAR', 'char' => 'CHAR', 'citext' => 'CHAR', 'name' => 'CHAR',
        'int' => 'SIGNED', 'integer' => 'SIGNED', 'int2' => 'SIGNED', 'int4' => 'SIGNED', 'int8' => 'SIGNED',
        'bigint' => 'SIGNED', 'smallint' => 'SIGNED',
        'numeric' => 'DECIMAL(65,10)', 'decimal' => 'DECIMAL(65,10)', 'real' => 'DECIMAL(65,10)', 'float' => 'DECIMAL(65,10)',
        'float4' => 'DECIMAL(65,10)', 'float8' => 'DECIMAL(65,10)', 'double precision' => 'DECIMAL(65,10)',
        'date' => 'DATE',
        'timestamp' => 'DATETIME', 'timestamptz' => 'DATETIME', 'timestamp without time zone' => 'DATETIME',
        'timestamp with time zone' => 'DATETIME',
    ];

    private const MYSQL_DROPPED = ['boolean', 'bool', 'json', 'jsonb', 'regclass', 'uuid', 'interval'];

    private const MYSQL_UNITS = ['second' => 'SECOND', 'sec' => 'SECOND', 'minute' => 'MINUTE', 'min' => 'MINUTE',
        'hour' => 'HOUR', 'day' => 'DAY', 'week' => 'WEEK', 'month' => 'MONTH', 'mon' => 'MONTH', 'year' => 'YEAR'];

    /** @var array<string, string> */
    private static array $mysqlCache = [];

    public static function translate(string $sql): string
    {
        if (isset(self::$mysqlCache[$sql])) {
            return self::$mysqlCache[$sql];
        }

        if (!preg_match('/::|ILIKE|INTERVAL\s*\'|~|NULLS\s+(FIRST|LAST)/i', $sql)) {
            return $sql;
        }

        $out = self::mapCode($sql, static function (string $code): string {
            $code = preg_replace('/\bILIKE\b/i', 'LIKE', $code);
            $code = preg_replace_callback(
                '/([a-z_][\w]*(?:\.[a-z_][\w]*)?)\s*(!?)~\*?\s*(:[a-z_]\w*|\?)/i',
                static fn (array $m) => $m[1] . ($m[2] === '!' ? ' NOT REGEXP ' : ' REGEXP ') . $m[3],
                $code
            );
            // MySQL sorts NULLs first ascending and last descending.
            $code = preg_replace_callback(
                '/([a-z_][\w]*(?:\.[a-z_][\w]*)?)(\s+(ASC|DESC))?\s+NULLS\s+(FIRST|LAST)\b/i',
                static function (array $m): string {
                    $dir   = strtoupper($m[3] ?? '') ?: 'ASC';
                    $nulls = strtoupper($m[4]);

                    if (($dir === 'ASC' && $nulls === 'FIRST') || ($dir === 'DESC' && $nulls === 'LAST')) {
                        return $m[1] . ' ' . $dir;
                    }

                    return '(' . $m[1] . ' IS NULL) ' . ($nulls === 'LAST' ? 'ASC' : 'DESC') . ', ' . $m[1] . ' ' . $dir;
                },
                $code
            );

            return $code;
        });

        $out = preg_replace_callback(
            "/INTERVAL\s*'\s*(\d+)\s*([a-z]+?)s?\s*'/i",
            static fn (array $m) => 'INTERVAL ' . (int) $m[1] . ' ' . (self::MYSQL_UNITS[strtolower($m[2])] ?? strtoupper($m[2])),
            $out
        );

        $out = self::rewriteMysqlCasts($out);

        if (count(self::$mysqlCache) > 500) {
            self::$mysqlCache = [];
        }

        return self::$mysqlCache[$sql] = $out;
    }

    private static function rewriteMysqlCasts(string $sql): string
    {
        $types = implode('|', array_map(static fn ($t) => str_replace(' ', '\s+', preg_quote($t, '/')), array_merge(
            array_keys(self::MYSQL_CASTS),
            self::MYSQL_DROPPED
        )));

        $pattern = '/::\s*(' . $types . ')\b(\s*\(\s*\d+(\s*,\s*\d+)?\s*\))?/i';
        $guard   = 0;

        while ($guard++ < 200) {
            $masked = self::mask($sql);

            if (!preg_match($pattern, $masked, $m, PREG_OFFSET_CAPTURE)) {
                break;
            }

            $castStart = $m[0][1];
            $castEnd   = $castStart + strlen($m[0][0]);
            $type      = strtolower(preg_replace('/\s+/', ' ', $m[1][0]));
            $opStart   = self::operandStart($masked, $castStart);
            $operand   = substr($sql, $opStart, $castStart - $opStart);

            $replacement = isset(self::MYSQL_CASTS[$type])
                ? 'CAST(' . $operand . ' AS ' . self::MYSQL_CASTS[$type] . ')'
                : $operand;

            $sql = substr($sql, 0, $opStart) . $replacement . substr($sql, $castEnd);
        }

        return $sql;
    }
}
