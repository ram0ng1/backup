<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Tests\unit;

use Illuminate\Database\Connection;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Database\Dialect;
use RuntimeException;

/**
 * Detecção de motor e regras de identificador do {@see Dialect}.
 *
 * O caso principal é o driver `mariadb` do illuminate/database v13: o
 * `getDriverName()` devolve `mariadb`, não `mysql`, e o match() lançava
 * "Unsupported database driver: mariadb" em qualquer instalação com MariaDB.
 */
final class DialectTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    /** O driver mariadb do illuminate v13 resolve sem consultar VERSION(). */
    public function test_detects_dedicated_mariadb_driver(): void
    {
        $db = Mockery::mock(Connection::class);
        $db->shouldReceive('getDriverName')->andReturn('mariadb');
        // Com o driver dedicado, VERSION() NÃO pode ser consultado.
        $db->shouldNotReceive('selectOne');

        $this->assertSame(Dialect::MARIADB, Dialect::detect($db));
    }

    /** Driver mysql apontado para um MariaDB: detecta pelo VERSION(). */
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

    #[DataProvider('parseProvider')]
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
