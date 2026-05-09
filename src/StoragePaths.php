<?php

namespace Ramon\Backup;

use Flarum\Foundation\Paths;

/**
 * Single source of truth for the layout under `storage/`. Keeping the
 * paths centralised makes it harder for one controller to compute a
 * different absolute path than another (which would let an attacker
 * craft a token that resolves into a different directory).
 *
 * Layout:
 *   storage/
 *     backups/
 *       <id>-<random>.flarum         — finalised backups served by download
 *     backup-tmp/
 *       export-<id>/                 — per-job staging dir (deleted on finish)
 *         job.json                   — resumable job state
 *         dump.sql                   — DB dump being assembled
 *         archive.partial            — archive being written
 *       import-<id>/
 *         upload.flarum              — uploaded archive (full or chunked)
 *         job.json                   — resumable import state
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
        // Restrict to the alphabet our generator uses — no separators,
        // no dots beyond the trailing extension. Anything weird means
        // the filename came from somewhere we don't trust.
        if (! preg_match('/^[A-Za-z0-9_-]+\.flarum$/', $filename)) {
            return null;
        }
        $candidate = $this->backupsDir() . DIRECTORY_SEPARATOR . $filename;

        // Normalise BOTH sides through realpath before comparing —
        // on Windows the Paths::$storage value can differ in case
        // (D:\laragon\... vs realpath's D:\Laragon\...), which would
        // break a naive str_starts_with even though the file is fine.
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
     * Recursively delete a directory under tmp/. Refuses to touch
     * anything outside `tmpDir()` for paranoia.
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

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    private function safe(string $token): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $token) ?: 'unknown';
    }
}
