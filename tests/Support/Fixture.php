<?php

namespace Ramon\Backup\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * A small, deliberately gnarly two-table fixture used by every transfer
 * test. It exercises the column shapes and value edge-cases most likely
 * to break a cross-engine dump/restore:
 *
 *   - auto-increment integer primary keys (MySQL AUTO_INCREMENT vs PG
 *     serial/identity vs SQLite rowid)
 *   - an unsigned FK column + a real FOREIGN KEY between the two tables
 *   - boolean (tinyint(1) ↔ boolean ↔ integer)
 *   - nullable columns (NULL must survive, not become '')
 *   - negative and zero integers
 *   - nullable TIMESTAMP
 *   - strings with single quotes, commas, newlines, accented characters
 *     and a 4-byte emoji (utf8mb4 / UTF-8 round-trip)
 *
 * The schema is declared once with Laravel's Blueprint so each engine
 * gets idiomatic DDL; the dumper then re-derives it by introspection.
 */
final class Fixture
{
    /** Drop order (children first) — used when resetting. */
    public const TABLES = ['bkp_posts', 'bkp_authors'];

    /**
     * Drop the fixture tables in child-first order. Used to give a
     * transfer target a clean slate between directions so a leftover
     * child table (e.g. with a differently-signed FK column from a
     * previous engine's dump) can't trip the next CREATE.
     */
    public static function reset(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        foreach (self::TABLES as $table) {
            $schema->dropIfExists($table);
        }
    }

    public static function build(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();

        // Full wipe first: several test classes share the same physical
        // databases (one per engine), and the transfer tests dump the
        // WHOLE database — so any table left behind by another test
        // (e.g. a `backups` registry table) would leak into the dump.
        // Starting from an empty schema keeps every direction isolated
        // and deterministic regardless of execution order.
        $schema->dropAllTables();

        $schema->create('bkp_authors', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->string('email')->nullable();
            $t->boolean('active')->default(true);
            $t->integer('reputation')->default(0);
            $t->timestamp('joined_at')->nullable();
        });

        $schema->create('bkp_posts', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('author_id');
            $t->string('title');
            $t->text('body')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->foreign('author_id')->references('id')->on('bkp_authors');
        });
    }

    public static function seed(Connection $db): void
    {
        $db->table('bkp_authors')->insert([
            ['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'active' => true,  'reputation' => 120, 'joined_at' => '2024-01-02 03:04:05'],
            ['id' => 2, 'name' => "O'Brien, the \"Tester\"", 'email' => null,    'active' => false, 'reputation' => -5,  'joined_at' => null],
            ['id' => 3, 'name' => 'José da Silva ☃',         'email' => 'jose@example.com', 'active' => true, 'reputation' => 0, 'joined_at' => '2025-12-31 23:59:59'],
        ]);

        $db->table('bkp_posts')->insert([
            ['id' => 1, 'author_id' => 1, 'title' => 'Hello', 'body' => "Line 1\nLine 2 with 'quotes', a comma, and a backslash \\ end", 'created_at' => '2024-02-01 10:00:00'],
            ['id' => 2, 'author_id' => 1, 'title' => 'Empty body', 'body' => null, 'created_at' => null],
            ['id' => 3, 'author_id' => 3, 'title' => 'Unicode 😀', 'body' => 'Emoji 😀 and ünïcödë text', 'created_at' => '2025-06-15 12:30:00'],
        ]);
    }

    /**
     * Read both tables back into a normalised, engine-agnostic shape so
     * two snapshots can be compared with assertEquals regardless of how
     * a given engine renders booleans, timestamps or numeric strings.
     *
     * @return array{authors: list<array<string,mixed>>, posts: list<array<string,mixed>>}
     */
    public static function snapshot(Connection $db): array
    {
        $authors = [];
        foreach ($db->table('bkp_authors')->orderBy('id')->get() as $r) {
            $r = (array) $r;
            $authors[] = [
                'id'         => (int) $r['id'],
                'name'       => (string) $r['name'],
                'email'      => $r['email'] === null ? null : (string) $r['email'],
                'active'     => self::toBool($r['active']),
                'reputation' => (int) $r['reputation'],
                'joined_at'  => self::normalizeTimestamp($r['joined_at']),
            ];
        }

        $posts = [];
        foreach ($db->table('bkp_posts')->orderBy('id')->get() as $r) {
            $r = (array) $r;
            $posts[] = [
                'id'         => (int) $r['id'],
                'author_id'  => (int) $r['author_id'],
                'title'      => (string) $r['title'],
                'body'       => $r['body'] === null ? null : (string) $r['body'],
                'created_at' => self::normalizeTimestamp($r['created_at']),
            ];
        }

        return ['authors' => $authors, 'posts' => $posts];
    }

    private static function toBool(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        $s = strtolower((string) $v);
        return in_array($s, ['1', 't', 'true', 'yes'], true);
    }

    /**
     * Collapse the various engine timestamp renderings (with/without
     * fractional seconds, with/without timezone suffix) to a single
     * `Y-m-d H:i:s` string. NULL stays NULL.
     */
    private static function normalizeTimestamp(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        $ts = strtotime((string) $v);
        return $ts === false ? (string) $v : date('Y-m-d H:i:s', $ts);
    }
}
