<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * Fixture de duas tabelas, propositalmente chata, usada por todos os
 * testes de transferência. Cobre o que mais quebra um dump entre motores:
 *
 *   - PK auto-increment (AUTO_INCREMENT do MySQL, serial/identity do PG,
 *     rowid do SQLite)
 *   - coluna FK unsigned + FOREIGN KEY real entre as tabelas
 *   - boolean (tinyint(1) ↔ boolean ↔ integer)
 *   - colunas nullable (NULL tem que continuar NULL, não '')
 *   - inteiros negativos e zero
 *   - TIMESTAMP nullable
 *   - strings com aspas simples, vírgulas, quebras de linha, acentos e um
 *     emoji de 4 bytes (utf8mb4 / UTF-8)
 *
 * O schema é declarado uma vez com o Blueprint, para cada motor receber
 * DDL nativo; o dumper o reconstrói por introspecção.
 */
final class Fixture
{
    /** Ordem de DROP (filhas primeiro). */
    public const TABLES = ['bkp_posts', 'bkp_authors'];

    /**
     * Apaga as tabelas da fixture, filhas primeiro. Uma filha que sobrasse
     * (com a FK de outro sinal, vinda do dump de outro motor) quebraria o
     * próximo CREATE.
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

        // Limpeza total primeiro: as classes de teste dividem o mesmo banco por
        // motor e os testes de transferência fazem dump do banco INTEIRO, então
        // qualquer tabela deixada por outro teste (ex.: `backups`) entraria no
        // dump.
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
     * Lê as duas tabelas num formato neutro, para comparar snapshots com
     * assertEquals sem depender de como cada motor devolve booleanos, datas
     * ou números em string.
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
     * Reduz as várias formas de timestamp dos motores (com ou sem fração de
     * segundo e fuso) a `Y-m-d H:i:s`. NULL continua NULL.
     */
    private static function normalizeTimestamp(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        $ts = strtotime((string) $v);
        return $ts === false ? (string) $v : date('Y-m-d H:i:s', $ts);
    }
}
