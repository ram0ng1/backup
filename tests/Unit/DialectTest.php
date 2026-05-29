<?php

namespace Ramon\Backup\Tests\Unit;

use Illuminate\Database\Connection;
use Mockery;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Database\Dialect;
use RuntimeException;

/**
 * Guards the engine-detection and identifier rules in {@see Dialect}.
 *
 * The headline case is the `mariadb` driver regression: illuminate/database
 * v13 ships a dedicated MariaDB driver whose `getDriverName()` returns the
 * literal string `mariadb` (not `mysql`), which the original match() did
 * not handle and threw "Unsupported database driver: mariadb". A live
 * install on Laravel 11+ hit that path the moment it pointed at MariaDB.
 */
final class DialectTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    /** illuminate v13's dedicated mariadb driver must resolve without a VERSION() probe. */
    public function test_detects_dedicated_mariadb_driver(): void
    {
        $db = Mockery::mock(Connection::class);
        $db->shouldReceive('getDriverName')->andReturn('mariadb');
        // Must NOT need to query VERSION() for the dedicated driver.
        $db->shouldNotReceive('selectOne');

        $this->assertSame(Dialect::MARIADB, Dialect::detect($db));
    }

    /** Legacy path: mysql driver pointed at a MariaDB server → detect via VERSION(). */
    public function test_detects_mariadb_behind_mysql_driver(): void
    {
        $db = Mockery::mock(Connection::class);
        $db->shouldReceive('getDriverName')->andReturn('mysql');
        $db->shouldReceive('selectOne')->andReturn((object) ['v' => '10.11.6-MariaDB-1:10.11.6+maria~ubu2204']);

        $this->assertSame(Dialect::MARIADB, Dialect::detect($db));
    }

    public function test_detects_plain_mysql(): void
    {
        $db = Mockery::mock(Connection::class);
        $db->shouldReceive('getDriverName')->andReturn('mysql');
        $db->shouldReceive('selectOne')->andReturn((object) ['v' => '8.0.30']);

        $this->assertSame(Dialect::MYSQL, Dialect::detect($db));
    }

    public function test_detects_postgres_and_sqlite(): void
    {
        $pg = Mockery::mock(Connection::class);
        $pg->shouldReceive('getDriverName')->andReturn('pgsql');
        $this->assertSame(Dialect::POSTGRES, Dialect::detect($pg));

        $sqlite = Mockery::mock(Connection::class);
        $sqlite->shouldReceive('getDriverName')->andReturn('sqlite');
        $this->assertSame(Dialect::SQLITE, Dialect::detect($sqlite));
    }

    public function test_unsupported_driver_throws(): void
    {
        $db = Mockery::mock(Connection::class);
        $db->shouldReceive('getDriverName')->andReturn('sqlsrv');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported database driver: sqlsrv');
        Dialect::detect($db);
    }

    /**
     * @dataProvider parseProvider
     */
    public function test_parse_accepts_friendly_aliases(string $input, Dialect $expected): void
    {
        $this->assertSame($expected, Dialect::parse($input));
    }

    public static function parseProvider(): array
    {
        return [
            ['mysql', Dialect::MYSQL],
            ['MySQL', Dialect::MYSQL],
            ['  mariadb ', Dialect::MARIADB],
            ['postgres', Dialect::POSTGRES],
            ['postgresql', Dialect::POSTGRES],
            ['pgsql', Dialect::POSTGRES],
            ['pg', Dialect::POSTGRES],
            ['sqlite', Dialect::SQLITE],
            ['sqlite3', Dialect::SQLITE],
        ];
    }

    public function test_parse_rejects_unknown(): void
    {
        $this->expectException(RuntimeException::class);
        Dialect::parse('oracle');
    }

    public function test_backticks_and_family_flags(): void
    {
        $this->assertTrue(Dialect::MYSQL->usesBackticks());
        $this->assertTrue(Dialect::MARIADB->usesBackticks());
        $this->assertFalse(Dialect::POSTGRES->usesBackticks());
        $this->assertFalse(Dialect::SQLITE->usesBackticks());

        $this->assertTrue(Dialect::MYSQL->isMysqlFamily());
        $this->assertTrue(Dialect::MARIADB->isMysqlFamily());
        $this->assertFalse(Dialect::POSTGRES->isMysqlFamily());
        $this->assertFalse(Dialect::SQLITE->isMysqlFamily());
    }
}
