<?php

namespace Ramon\Backup\Database;

use Illuminate\Database\Connection;
use Throwable;

/**
 * Plays SQL produced by `DatabaseDumper` back into a database.
 *
 * Resumable across ticks via a "buffer + cursor" model: the caller
 * pumps SQL bytes in via `feed()` and calls `executeReady()` to flush
 * whatever complete statements are already terminated. Anything past
 * the last `STATEMENT_DELIMITER` is held back in the buffer.
 *
 * CONTRATO COM O CHAMADOR: a instância NÃO sobrevive ao tick — cada tick
 * constrói um restorer novo, de buffer vazio. Logo, o cursor que o
 * chamador persiste jamais pode passar do último byte executado. É para
 * isso que existe {@see pendingBytes()}: ou o tick só termina com ele
 * zerado (fronteira de statement), ou o cursor recua essa mesma
 * quantidade antes de ser salvo. Avançar o cursor por cima do buffer
 * descarta a cabeça de um statement e faz o tick seguinte reabrir o dump
 * no meio de um literal — o rabo do INSERT chega sozinho ao banco e volta
 * como erro de sintaxe.
 *
 * The restorer is engine-aware: it detects the destination's dialect
 * and uses the right "disable FK enforcement" toggle for the duration
 * of the load. Static helpers `disableForeignKeys()` /
 * `enableForeignKeys()` are exposed so the surrounding job code can
 * apply the toggle once per tick (each tick gets a fresh PDO session).
 *
 * Designed to be safe for repeated restores: every dumped table starts
 * with `DROP TABLE IF EXISTS`, so we never need to truncate up front.
 */
class DatabaseRestorer
{
    private string $buffer = '';

    private int $statementsRun = 0;

    public function __construct(
        protected Connection $db
    ) {
    }

    /** Append more SQL bytes to the internal buffer. */
    public function feed(string $sqlChunk): void
    {
        $this->buffer .= $sqlChunk;
    }

    /**
     * Execute every complete statement currently in the buffer. Returns
     * how many were run. The remainder (everything past the last
     * delimiter) stays in the buffer.
     */
    public function executeReady(): int
    {
        $delim = DatabaseDumper::STATEMENT_DELIMITER;
        $lastIdx = strrpos($this->buffer, $delim);
        if ($lastIdx === false) {
            return 0;
        }

        $ready = substr($this->buffer, 0, $lastIdx + strlen($delim));
        $this->buffer = substr($this->buffer, $lastIdx + strlen($delim));

        $statements = explode($delim, $ready);
        $count = 0;
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            $this->db->unprepared($stmt);
            $count++;
        }

        $this->statementsRun += $count;
        return $count;
    }

    /**
     * Drain whatever is left in the buffer (only meaningful at the end
     * of the stream — the dumper always finishes with a delimiter, but
     * dumps from older versions / external tools might not).
     */
    public function finish(): int
    {
        if (trim($this->buffer) === '') {
            $this->buffer = '';
            return 0;
        }
        $stmt = trim($this->buffer);
        $this->buffer = '';
        if ($stmt !== '' && ! str_starts_with($stmt, '--')) {
            $this->db->unprepared($stmt);
            $this->statementsRun++;
            return 1;
        }
        return 0;
    }

    public function statementsRun(): int
    {
        return $this->statementsRun;
    }

    /**
     * Bytes ainda no buffer — a cauda depois do último delimitador, que
     * `executeReady()` segurou por não estar terminada. Zero significa que
     * o stream parou exatamente numa fronteira de statement, que é a única
     * posição em que o chamador pode persistir seu cursor.
     */
    public function pendingBytes(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Best-effort "suspend FK enforcement for this connection". Engine
     * matrix:
     *   - MySQL/MariaDB: `SET FOREIGN_KEY_CHECKS = 0`
     *   - SQLite:        `PRAGMA foreign_keys = OFF`
     *   - PostgreSQL:    no-op — `session_replication_role = 'replica'`
     *                    requires superuser, so we cannot rely on it
     *                    on managed hosts (RDS/Neon/Supabase). Instead,
     *                    PG dumps emit FK constraints as separate
     *                    `ALTER TABLE ADD CONSTRAINT` statements AFTER
     *                    all data is loaded, so there is nothing to
     *                    suspend during the INSERT phase to begin with.
     *
     * Each tick gets a fresh PDO session, so callers must invoke this
     * at the start of every tick that runs SQL.
     */
    public static function disableForeignKeys(Connection $db): void
    {
        try {
            match (Dialect::detect($db)) {
                Dialect::MYSQL,
                Dialect::MARIADB  => $db->unprepared('SET FOREIGN_KEY_CHECKS = 0'),
                Dialect::SQLITE   => $db->unprepared('PRAGMA foreign_keys = OFF'),
                Dialect::POSTGRES => null, // see docblock
            };
        } catch (Throwable) { /* best-effort */ }
    }

    public static function enableForeignKeys(Connection $db): void
    {
        try {
            match (Dialect::detect($db)) {
                Dialect::MYSQL,
                Dialect::MARIADB  => $db->unprepared('SET FOREIGN_KEY_CHECKS = 1'),
                Dialect::SQLITE   => $db->unprepared('PRAGMA foreign_keys = ON'),
                Dialect::POSTGRES => null, // see docblock on disableForeignKeys
            };
        } catch (Throwable) { /* best-effort */ }
    }
}
