<?php
declare(strict_types=1);

namespace App_skeleton\Db;

/**
 * Phalcon's stock SQLite dialect ignores the schema argument on every
 * introspection query (tableExists() reads main's sqlite_master only), so
 * a model with setSchema('sales') fails metadata with "Table 'orders'
 * doesn't exist". On SQLite a Postgres schema is an
 * ATTACHed database of the same name (see SqliteAdapter), and SQLite
 * already accepts "schema".sqlite_master and PRAGMA "schema".x(), so all
 * this does is pass the schema through. Used by SqliteAdapter only; the
 * Postgres connection never sees it.
 *
 * @psalm-suppress MethodSignatureMismatch Phalcon's stubs type these
 *                  parameters as mixed; the real signatures are typed.
 */
class SqliteDialect extends \Phalcon\Db\Dialect\Sqlite
{
    /**
     * Views count as tables, as they do on Postgres (information_schema.
     * tables lists both), so a model can sit on a view such as
     * sales.v_open_orders.
     */
    public function tableExists(string $tableName, ?string $schemaName = null): string
    {
        return 'SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END FROM ' . $this->master($schemaName)
            . " WHERE type IN ('table', 'view') AND tbl_name=" . $this->literal($tableName);
    }

    public function viewExists(string $viewName, ?string $schemaName = null): string
    {
        return 'SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END FROM ' . $this->master($schemaName)
            . " WHERE type='view' AND tbl_name=" . $this->literal($viewName);
    }

    public function describeColumns(string $table, ?string $schema = null): string
    {
        return 'PRAGMA ' . $this->prefix($schema) . 'table_xinfo(' . $this->literal($table) . ')';
    }

    public function describeIndexes(string $table, ?string $schema = null): string
    {
        return 'PRAGMA ' . $this->prefix($schema) . 'index_list(' . $this->literal($table) . ')';
    }

    public function describeReferences(string $table, ?string $schema = null): string
    {
        return 'PRAGMA ' . $this->prefix($schema) . 'foreign_key_list(' . $this->literal($table) . ')';
    }

    public function listTables(?string $schemaName = null): string
    {
        return 'SELECT tbl_name FROM ' . $this->master($schemaName) . " WHERE type = 'table' ORDER BY tbl_name";
    }

    public function listViews(?string $schemaName = null): string
    {
        return 'SELECT tbl_name FROM ' . $this->master($schemaName) . " WHERE type = 'view' ORDER BY tbl_name";
    }

    private function master(?string $schema): string
    {
        return $this->prefix($schema) . 'sqlite_master';
    }

    private function prefix(?string $schema): string
    {
        if ($schema === null || $schema === '' || $schema === 'main' || $schema === 'public') {
            return '';
        }

        return '"' . str_replace('"', '""', $schema) . '".';
    }

    private function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
