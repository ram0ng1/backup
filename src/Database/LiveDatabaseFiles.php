<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Database;

use Illuminate\Database\Connection;

/**
 * O arquivo do banco SQLite em uso, quando ele mora em storage/.
 *
 * Copiar esse arquivo para o backup pega um banco no meio da escrita, e
 * gravá-lo de volta no restore sobrescreve o banco que o próprio restore
 * está usando ("database disk image is malformed" no tick seguinte). O
 * banco já viaja no dump SQL; o arquivo fica de fora nos dois sentidos.
 */
final class LiveDatabaseFiles
{
    /**
     * Caminhos normalizados do banco e dos auxiliares (-wal, -shm,
     * -journal). Vazio fora do SQLite ou em banco em memória.
     *
     * @return list<string>
     */
    public static function of(Connection $db): array
    {
        if ($db->getDriverName() !== 'sqlite') {
            return [];
        }

        $path = (string) $db->getConfig('database');
        if ($path === '' || $path === ':memory:' || str_starts_with($path, 'file::memory:')) {
            return [];
        }

        $file = self::normalise($path);

        return [$file, $file.'-wal', $file.'-shm', $file.'-journal'];
    }

    /**
     * Resolve o diretório (o arquivo pode ainda não existir) e mantém o
     * nome, para comparar caminhos que chegam por symlink ou com `..`.
     */
    public static function normalise(string $path): string
    {
        $dir = realpath(dirname($path));

        return ($dir === false ? dirname($path) : $dir).DIRECTORY_SEPARATOR.basename($path);
    }
}
