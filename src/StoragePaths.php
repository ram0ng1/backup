<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup;

use Flarum\Foundation\Paths;

/**
 * Fonte única dos caminhos sob `storage/`. Centralizar evita que dois
 * controllers resolvam o mesmo token para diretórios diferentes.
 *
 * Estrutura:
 *   storage/
 *     backups/
 *       <id>-<random>.flarum         — backups prontos, servidos pelo download
 *     backup-tmp/
 *       export-<id>/                 — staging do job (apagado ao terminar)
 *         job.json                   — estado retomável do job
 *         dump.sql                   — dump do banco em montagem
 *         archive.partial            — arquivo em escrita
 *       import-<id>/
 *         upload.flarum              — arquivo enviado (inteiro ou em partes)
 *         job.json                   — estado retomável da importação
 */
class StoragePaths
{
    public function __construct(
        protected Paths $paths
    ) {
    }

    public function ensureRoot(): string
    {
        $root = rtrim($this->paths->storage, '/\\');
        return $root;
    }

    public function backupsDir(): string
    {
        $dir = $this->ensureRoot() . DIRECTORY_SEPARATOR . 'backups';
        $this->ensureDir($dir);
        return $dir;
    }

    public function tmpDir(): string
    {
        $dir = $this->ensureRoot() . DIRECTORY_SEPARATOR . 'backup-tmp';
        $this->ensureDir($dir);
        return $dir;
    }

    public function exportJobDir(string $jobId): string
    {
        $dir = $this->tmpDir() . DIRECTORY_SEPARATOR . 'export-' . $this->safe($jobId);
        $this->ensureDir($dir);
        return $dir;
    }

    public function importJobDir(string $jobId): string
    {
        $dir = $this->tmpDir() . DIRECTORY_SEPARATOR . 'import-' . $this->safe($jobId);
        $this->ensureDir($dir);
        return $dir;
    }

    public function backupFilePath(string $filename): ?string
    {
        // Só o alfabeto do nosso gerador: sem separadores nem pontos extras.
        if (! preg_match('/^[A-Za-z0-9_-]+\.flarum$/', $filename)) {
            return null;
        }
        $candidate = $this->backupsDir() . DIRECTORY_SEPARATOR . $filename;

        // realpath nos dois lados e comparação sem caixa: no Windows o
        // Paths::$storage pode vir com caixa diferente da do realpath.
        $real = realpath($candidate);
        $base = realpath($this->backupsDir());
        if ($real === false || $base === false) {
            return null;
        }

        $cmpA = strtolower($real);
        $cmpB = strtolower($base.DIRECTORY_SEPARATOR);
        if (! str_starts_with($cmpA, $cmpB)) {
            return null;
        }
        return $real;
    }

    public function generateFilename(int $backupId): string
    {
        return sprintf('flarum-backup-%05d-%s.flarum', $backupId, bin2hex(random_bytes(4)));
    }

    /**
     * Apaga recursivamente; recusa qualquer caminho fora de `tmpDir()`.
     */
    public function deleteDir(string $absolutePath): void
    {
        $real = realpath($absolutePath);
        if ($real === false) return;
        $base = realpath($this->tmpDir());
        if ($base === false || ! str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return;
        }
        $this->rrmdir($real);
    }

    /**
     * Diretórios `export-*` / `import-*` cujo `job.json` (ou o próprio
     * diretório, sem ele) não muda há mais de `$maxAgeSeconds`. Job ativo
     * nunca entra: cada tick regrava o `job.json`.
     *
     * @return list<string>
     */
    public function staleJobDirs(int $maxAgeSeconds): array
    {
        $tmp = $this->tmpDir();
        $cutoff = time() - max(0, $maxAgeSeconds);

        $out = [];
        foreach (scandir($tmp) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (! preg_match('/^(export|import)-[A-Za-z0-9_-]+$/', $entry)) continue;

            $dir = $tmp . DIRECTORY_SEPARATOR . $entry;
            if (! is_dir($dir)) continue;

            $jobFile = $dir . DIRECTORY_SEPARATOR . 'job.json';
            $mtime = is_file($jobFile) ? @filemtime($jobFile) : @filemtime($dir);
            if ($mtime === false) continue;

            if ($mtime < $cutoff) {
                $out[] = $dir;
            }
        }
        return $out;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $this->rrmdir($dir . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($dir);
    }

    /**
     * Cria o diretório com 0700. Aqui ficam os backups e o banco inteiro em
     * texto puro (dump.sql); como o download passa pelo PHP, nenhuma outra
     * conta da hospedagem compartilhada precisa ler. Diretórios que já
     * existem mantêm as permissões atuais.
     */
    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }

    private function safe(string $token): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $token) ?: 'unknown';
    }
}
