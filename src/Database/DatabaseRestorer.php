<?php

namespace Ramon\Backup\Database;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Plays SQL produced by `DatabaseDumper` back into a database.
 *
 * Resumable across ticks via a "buffer + cursor" model: the caller pumps
 * SQL bytes in via `feed()` and calls `executeReady()` to flush whatever
 * complete statements are already terminated. Anything past the last
 * `STATEMENT_DELIMITER` is held back for the next tick.
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
}
