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

/**
 * Apaga o diretório de trabalho de um teste em PHP puro, sem `rm -rf`
 * por shell. Symlink é desligado, nunca seguido.
 */
final class TempDir
{
    public static function remove(string $dir): void
    {
        if (is_link($dir) || ! is_dir($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            self::remove($dir.DIRECTORY_SEPARATOR.$entry);
        }
        @rmdir($dir);
    }
}
