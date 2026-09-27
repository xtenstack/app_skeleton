<?php
declare(strict_types=1);

namespace App_skeleton\Db;

/**
 * Rewrites the handful of purely syntactic Postgres-isms that module code
 * sends as raw SQL into their SQLite equivalents, so the same query text
 * can run on a SQLite demo install. SQLite connection only (SqliteAdapter);
 * on Postgres nothing here runs and the SQL goes out untouched.
 *
 * Deliberately small. It only handles rewrites that keep the meaning:
 *
 *   - ILIKE / NOT ILIKE  -> LIKE / NOT LIKE (SQLite's LIKE is already
 *                           case-insensitive for ASCII)
 *   - expr::type         -> CAST(expr AS <affinity>) for scalar types,
 *                           date(expr) for ::date, dropped for timestamp/
 *                           json/bool/regclass casts SQLite has no use for
 *   - FOR UPDATE [SKIP LOCKED|NOWAIT] -> removed (SQLite has one writer
 *                           at a time, so the row lock is implicit)
 *   - <now|column> +/- INTERVAL 'N unit' -> datetime(x, '+/-N unit')
 *   - UPDATE tbl alias SET  -> UPDATE tbl AS alias SET (SQLite needs the AS)
 *   - a ~* b / a !~* b / a ~ b / a !~ b, when a is a column and b a bound
 *                           placeholder -> [NOT] iregexp(b, a) /
 *                           regexp(b, a) (PHP functions, see SqliteAdapter)
 *
 * Anything with different semantics (DISTINCT ON, LATERAL, arrays,
 * materialized views, trigram operators) is NOT translated. Module code
 * branches on $db->getType() === 'sqlite' for those, so the Postgres
 * query stays as it is. Quoted literals, quoted identifiers and comments
 * are never rewritten.
 */
class PgSqlTranslator
{
    private const CASTS = [
        'text' => 'TEXT', 'varchar' => 'TEXT', 'character varying' => 'TEXT', 'char' => 'TEXT', 'citext' => 'TEXT', 'name' => 'TEXT',
        'int' => 'INTEGER', 'integer' => 'INTEGER', 'int2' => 'INTEGER', 'int4' => 'INTEGER', 'int8' => 'INTEGER',
        'bigint' => 'INTEGER', 'smallint' => 'INTEGER',
        'numeric' => 'REAL', 'decimal' => 'REAL', 'real' => 'REAL', 'float' => 'REAL', 'float4' => 'REAL',
        'float8' => 'REAL', 'double precision' => 'REAL',
    ];

    /** Casts that SQLite doesn't need: the value is stored as-is. */
    private const DROPPED = ['timestamp', 'timestamptz', 'timestamp without time zone', 'timestamp with time zone',
        'boolean', 'bool', 'json', 'jsonb', 'regclass', 'uuid', 'interval'];

    private const UNITS = ['second' => 'seconds', 'minute' => 'minutes', 'hour' => 'hours', 'day' => 'days',
        'month' => 'months', 'year' => 'years', 'sec' => 'seconds', 'min' => 'minutes', 'mon' => 'months'];

    /** @var array<string, string> */
    private static array $cache = [];

    public static function translate(string $sql): string
    {
        if (isset(self::$cache[$sql])) {
            return self::$cache[$sql];
        }

        // Fast path: nothing to do.
        if (!preg_match('/::|ILIKE|FOR\s+(NO\s+KEY\s+)?UPDATE|INTERVAL|~|UPDATE\s+[\w.]+\s+\w+\s+SET/i', $sql)) {
            return $sql;
        }

        $out = self::mapCode($sql, static function (string $code): string {
            $code = preg_replace('/\bILIKE\b/i', 'LIKE', $code);
            $code = preg_replace('/\bUPDATE\s+([\w.]+)\s+(?!SET\b|AS\b)([a-z_]\w*)\s+SET\b/i', 'UPDATE $1 AS $2 SET', $code);
            $code = preg_replace_callback(
                '/([a-z_][\w]*(?:\.[a-z_][\w]*)?)\s*(!?)~(\*?)\s*(:[a-z_]\w*|\?)/i',
                static fn (array $m) => ($m[2] === '!' ? 'NOT ' : '') . ($m[3] === '*' ? 'iregexp(' : 'regexp(') . $m[4] . ', ' . $m[1] . ')',
                $code
            );
            $code = preg_replace('/\s+FOR\s+(NO\s+KEY\s+)?UPDATE(\s+OF\s+[\w", .]+?)?(\s+(SKIP\s+LOCKED|NOWAIT))?(?=\s*(;|\)|$))/i', '', $code);

            return $code;
        });

        $out = self::rewriteIntervals($out);
        $out = self::rewriteCasts($out);

        if (count(self::$cache) > 500) {
            self::$cache = [];
        }

        return self::$cache[$sql] = $out;
    }

    /**
     * Apply $fn to the parts of $sql outside '...' literals, "..." identifiers
     * and comments.
     */
    protected static function mapCode(string $sql, callable $fn): string
    {
        $parts = preg_split("/('(?:[^']|'')*'|\"(?:[^\"]|\"\")*\"|--[^\n]*|\/\*.*?\*\/)/s", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out   = '';

        foreach ($parts as $i => $part) {
            $out .= $i % 2 === 0 ? $fn($part) : $part;
        }

        return $out;
    }

    /** Mask literals/comments with same-length placeholders so offsets line up. */
    protected static function mask(string $sql): string
    {
        return preg_replace_callback(
            "/('(?:[^']|'')*'|\"(?:[^\"]|\"\")*\"|--[^\n]*|\/\*.*?\*\/)/s",
            static fn (array $m) => $m[0][0] . str_repeat("\x01", max(0, strlen($m[0]) - 2)) . ($m[0] !== '' ? substr($m[0], -1) : ''),
            $sql
        );
    }

    private static function rewriteIntervals(string $sql): string
    {
        // <operand> +/- INTERVAL 'N unit'   (operand: now(), CURRENT_TIMESTAMP, CURRENT_DATE, a column)
        return preg_replace_callback(
            "/(\bNOW\(\)|\bCURRENT_TIMESTAMP\b|\bCURRENT_DATE\b|\b[a-z_][\w]*(?:\.[a-z_][\w]*)?)\s*([+-])\s*INTERVAL\s*'\s*(\d+)\s*([a-z]+?)s?\s*'/i",
            static function (array $m): string {
                $unit = strtolower($m[4]);
                $n    = (int) $m[3];

                if ($unit === 'week') {
                    [$unit, $n] = ['days', $n * 7];
                } else {
                    $unit = self::UNITS[$unit] ?? $unit . 's';
                }

                $fn = strtoupper($m[1]) === 'CURRENT_DATE' ? 'date' : 'datetime';

                return $fn . '(' . $m[1] . ", '" . $m[2] . $n . ' ' . $unit . "')";
            },
            $sql
        );
    }

    private static function rewriteCasts(string $sql): string
    {
        $types = implode('|', array_map(static fn ($t) => str_replace(' ', '\s+', preg_quote($t, '/')), array_merge(
            array_keys(self::CASTS),
            self::DROPPED,
            ['date']
        )));

        // Longest type names first so "timestamp with time zone" wins over "timestamp".
        $pattern = '/::\s*(' . $types . ')\b(\s*\(\s*\d+(\s*,\s*\d+)?\s*\))?/i';

        $guard = 0;

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

            if ($type === 'date') {
                $replacement = 'date(' . $operand . ')';
            } elseif (isset(self::CASTS[$type])) {
                $replacement = 'CAST(' . $operand . ' AS ' . self::CASTS[$type] . ')';
            } else {
                $replacement = $operand;
            }

            $sql = substr($sql, 0, $opStart) . $replacement . substr($sql, $castEnd);
        }

        return $sql;
    }

    /** Walk back from a '::' to the start of the operand it casts. */
    protected static function operandStart(string $masked, int $pos): int
    {
        $i = $pos - 1;

        while ($i >= 0 && ctype_space($masked[$i])) {
            $i--;
        }

        if ($i < 0) {
            return $pos;
        }

        $ch = $masked[$i];

        if ($ch === ')') {
            $depth = 0;

            for (; $i >= 0; $i--) {
                if ($masked[$i] === ')') {
                    $depth++;
                } elseif ($masked[$i] === '(') {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }
                }
            }

            // Include a function name directly before the '('.
            $j = $i - 1;

            while ($j >= 0 && (ctype_alnum($masked[$j]) || $masked[$j] === '_' || $masked[$j] === '.')) {
                $j--;
            }

            return $j + 1;
        }

        if ($ch === "'" || $ch === '"') {
            // Literal or quoted identifier (masked body): find the opening quote.
            $j = $i - 1;

            while ($j >= 0 && $masked[$j] !== $ch) {
                $j--;
            }

            // Possibly "schema"."table"."col" chains / E'' prefixes: keep it simple.
            return max(0, $j);
        }

        // Identifier, number, :placeholder or ?, possibly dotted.
        $j = $i;

        while ($j >= 0 && (ctype_alnum($masked[$j]) || in_array($masked[$j], ['_', '.', ':', '?', '$'], true))) {
            $j--;
        }

        return $j + 1;
    }
}
