<?php

namespace Ramon\Backup\Database;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * MySQL-specific, resumable database dumper.
 *
 * Output is plain SQL with one statement per logical block, separated by
 * `\n-- @@END@@\n`. The sentinel comment is what `DatabaseRestorer`
 * splits on — we don't try to parse semicolons because string literals
 * can contain them. A SQL comment is a no-op for any tooling that might
 * read the file directly, so the dump stays usable as a regular .sql.
 *
 * Resumable shape:
 *   - The dumper produces output incrementally via `dumpChunk()`, which
 *     returns up to ~$budgetBytes of SQL and updates the progress
 *     cursor it was given. The caller writes the SQL to a temp file
 *     and calls again on the next tick.
 *   - State persisted between ticks: `phase` (schema | data | done),
 *     remaining tables, and the offset into the current table.
 */
class DatabaseDumper
{
    public const STATEMENT_DELIMITER = "\n-- @@END@@\n";

    public const PHASE_SCHEMA = 'schema';
    public const PHASE_DATA   = 'data';
    public const PHASE_DONE   = 'done';

    /** Rows pulled from a table per SELECT, regardless of byte budget. */
    private const ROWS_PER_QUERY = 200;

    public function __construct(
        protected Connection $db
    ) {
        $driver = $db->getDriverName();
        if ($driver !== 'mysql') {
            throw new RuntimeException(
                "Database driver `$driver` is not supported by the backup extension. Only MySQL is supported."
            );
        }
    }

    /**
     * Enumerate user tables (excluding views). Returned in a stable order
     * so the dump is reproducible and resumable.
     *
     * @return list<string>
     */
    public function listTables(): array
    {
        $rows = $this->db->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = [];
        foreach ($rows as $row) {
            // SHOW FULL TABLES returns columns with names like
            // "Tables_in_<db>" and "Table_type" — pull the first scalar
            // value defensively.
            $vals = array_values((array) $row);
            $tables[] = (string) $vals[0];
        }
        sort($tables, SORT_STRING);
        return $tables;
    }

    public function preamble(): string
    {
        $now = gmdate('Y-m-d H:i:s');
        // Each statement is delimited individually so the restorer
        // can execute them via independent unprepared() calls. Bundling
        // multiple statements into one block would lean on PDO::exec
        // multi-statement support, which is configuration-dependent
        // and silently drops everything past the first SQL on stricter
        // setups — leaving FOREIGN_KEY_CHECKS at 1 and breaking the
        // first CREATE TABLE that references a not-yet-created table.
        return implode(self::STATEMENT_DELIMITER, [
            "-- Flarum backup — database dump",
            "-- Generated at $now UTC",
            "SET NAMES utf8mb4",
            "SET FOREIGN_KEY_CHECKS = 0",
            "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'",
        ]) . self::STATEMENT_DELIMITER;
    }

    public function epilogue(): string
    {
        return "SET FOREIGN_KEY_CHECKS = 1" . self::STATEMENT_DELIMITER;
    }

    /**
     * Drop + recreate DDL for one table. Single line where possible so
     * the restorer doesn't choke on embedded newlines in column comments.
     * MySQL's SHOW CREATE TABLE preserves backticks and quoting; we just
     * collapse runs of whitespace and strip newlines.
     */
    public function dumpSchema(string $table): string
    {
        $tableQ = $this->quoteIdent($table);
        $row = $this->db->selectOne("SHOW CREATE TABLE $tableQ");
        if (! $row) {
            throw new RuntimeException("Could not read DDL for $table");
        }
        $vals  = array_values((array) $row);
        $ddl   = (string) ($vals[1] ?? '');

        // Collapse the multi-line DDL onto a single line. The dumper's
        // statement delimiter is the only newline boundary the restorer
        // recognises, so we cannot leave structural newlines in here.
        $ddl = preg_replace('/\s+/', ' ', $ddl);

        $sql = "DROP TABLE IF EXISTS $tableQ;" . self::STATEMENT_DELIMITER;
        $sql .= $ddl . ";" . self::STATEMENT_DELIMITER;
        return $sql;
    }

    /**
     * Pull the next batch of rows from `$table` starting at `$offset`,
     * returning the SQL string and the number of rows consumed. The
     * caller is responsible for accumulating offsets across ticks.
     *
     * Empty SQL with `consumed == 0` signals "table exhausted".
     *
     * @return array{sql: string, consumed: int}
     */
    public function dumpDataBatch(string $table, int $offset): array
    {
        $tableQ = $this->quoteIdent($table);
        $rows = $this->db->select("SELECT * FROM $tableQ LIMIT ? OFFSET ?", [self::ROWS_PER_QUERY, $offset]);
        if (empty($rows)) {
            return ['sql' => '', 'consumed' => 0];
        }

        // INSERT INTO `table` (`col1`, `col2`) VALUES (...), (...);
        $first = (array) $rows[0];
        $columns = array_keys($first);
        $colList = implode(', ', array_map([$this, 'quoteIdent'], $columns));

        $valueGroups = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $vals = [];
            foreach ($columns as $col) {
                $vals[] = $this->quoteValue($row[$col] ?? null);
            }
            $valueGroups[] = '(' . implode(',', $vals) . ')';
        }

        $sql = "INSERT INTO $tableQ ($colList) VALUES " . implode(',', $valueGroups) . ';';
        return [
            'sql'      => $sql . self::STATEMENT_DELIMITER,
            'consumed' => count($rows),
        ];
    }

    /**
     * Quote a SQL value the way mysqldump does: NULL stays NULL,
     * integers/floats are bare, strings are escaped and wrapped in
     * single quotes, and binary blobs round-trip via 0xHEX.
     */
    public function quoteValue($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (! is_string($value)) {
            $value = (string) $value;
        }

        // Detect binary content. Anything non-UTF-8 or containing NULs
        // travels as a hex literal so we never need to worry about
        // encoding round-trips.
        if (preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
            return '0x' . bin2hex($value);
        }

        // Use a real prepared-statement quoter to escape the string
        // safely, including handling of \, ', NUL, \n, \r, etc.
        return $this->db->getPdo()->quote($value);
    }

    private function quoteIdent(string $ident): string
    {
        return '`' . str_replace('`', '``', $ident) . '`';
    }
}
