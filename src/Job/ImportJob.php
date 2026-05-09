<?php

namespace Ramon\Backup\Job;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Illuminate\Database\ConnectionInterface;
use Ramon\Backup\Archive\ArchiveReader;
use Ramon\Backup\Archive\Format;
use Ramon\Backup\Crypto\BackupCipher;
use Ramon\Backup\Database\DatabaseRestorer;
use Ramon\Backup\StoragePaths;
use RuntimeException;
use Throwable;

/**
 * Mirror of ExportJob, but reversed: pull entries from a `.flarum`
 * archive and apply them to the running install.
 *
 * Strategy is always "replace":
 *   - DB statements include `DROP TABLE IF EXISTS` per table — running
 *     them recreates the schema cleanly.
 *   - File entries overwrite anything currently at the destination
 *     path. We never touch paths outside the four bundleable roots
 *     (assets/, storage/, extensions/), so a malicious archive can't
 *     escape the install.
 *
 * Phases:
 *   inspect   → openHeader + decrypt prep (one tick)
 *   extract   → pull each entry; SQL goes into dump.sql, files go to
 *               their destination directly
 *   restore   → replay dump.sql through DatabaseRestorer
 *   finalize  → cleanup
 */
class ImportJob
{
    public const BUDGET_BYTES = 4_194_304;

    public function __construct(
        protected StoragePaths $paths,
        protected Paths $appPaths,
        protected ConnectionInterface $db,
        protected BackupCipher $cipher,
        protected Config $config
    ) {
    }

    /**
     * Begin an import. The archive must already be staged in the job
     * dir as `upload.flarum` (the upload controller does that).
     */
    /**
     * @param array{db?: bool, assets?: bool, storage?: bool, extensions?: bool|list<string>}|null $selection
     *        null = restore everything in the archive
     *        extensions: true = all, false = none, array = specific extension dirs
     */
    public function start(string $jobId, ?string $privateKey, bool $confirmReplace, ?array $selection, ?int $userId): JobState
    {
        if (! $confirmReplace) {
            throw new RuntimeException('Import requires explicit replace-confirmation.');
        }

        $dir = $this->paths->importJobDir($jobId);
        $upload = $dir.DIRECTORY_SEPARATOR.'upload.flarum';
        if (! is_file($upload)) {
            throw new RuntimeException('No archive uploaded.');
        }

        $initial = [
            'job_id'        => $jobId,
            'created_by'    => $userId,
            'started_at'    => time(),
            'phase'         => 'inspect',
            'message'       => 'Inspecting archive…',
            'paths'         => [
                'dir'     => $dir,
                'archive' => $upload,
                'dump'    => $dir.DIRECTORY_SEPARATOR.'dump.sql',
            ],
            'options' => [
                'private_key'      => $privateKey ?: null,
                'confirm_replace'  => true,
                'selection'        => $this->normaliseSelection($selection),
            ],
            'archive_meta'  => null,
            'progress' => [
                'total_bytes'        => filesize($upload) ?: 0,
                'processed_bytes'    => 0,
                'extracted_entries'  => 0,
                'restored_statements' => 0,
                'percent'            => 0.0,
            ],
            'cursor' => [
                'bytes_read'        => 0,
                'restore_offset'    => 0,
                'current_entry'     => null,
                'current_offset'    => 0,
                'extract_done'      => false,
            ],
        ];

        return JobState::create($dir.DIRECTORY_SEPARATOR.'job.json', $initial);
    }

    public function runTick(JobState $state): JobState
    {
        $phase = $state->get('phase');

        try {
            match ($phase) {
                'inspect'  => $this->runInspect($state),
                'extract'  => $this->runExtract($state),
                'restore'  => $this->runRestore($state),
                'rewrite'  => $this->runRewrite($state),
                'finalize' => $this->runFinalize($state),
                'done'     => null,
                'error'    => null,
                default    => throw new RuntimeException("Unknown phase: $phase"),
            };
        } catch (Throwable $e) {
            $state->set('phase', 'error');
            $state->set('message', 'Error: '.$e->getMessage());
            $state->save();
        }

        return $state;
    }

    /**
     * Phase 1 — read header, validate, and (for encrypted archives)
     * unwrap the symmetric key. We then close the reader and the
     * extract phase reopens it from scratch — re-reading the header is
     * cheap (~few hundred bytes).
     *
     * Validation here means: it's a real archive, we can decrypt it.
     * If something is wrong, the user gets a clear error before any
     * data is touched.
     */
    private function runInspect(JobState $state): void
    {
        $paths = $state->get('paths');
        $opts  = $state->get('options');
        $reader = ArchiveReader::openHeader($paths['archive']);
        try {
            if ($reader->isEncrypted()) {
                $reader->prepareEncrypted($this->cipher, $opts['private_key']);
            }
            $state->set('archive_meta', $reader->meta());
        } finally {
            $reader->close();
        }

        $state->set('phase', 'extract');
        $state->set('message', 'Extracting…');
        $state->save();
    }

    /**
     * Phase 2 — pull entries one at a time. SQL goes into a single
     * dump.sql in the job dir; file entries are written straight to
     * their destination paths.
     *
     * Resume strategy (deliberately simple): each tick reopens the
     * archive from the beginning and seeks past entries that were
     * already fully extracted. For encrypted archives this means
     * re-decrypting earlier chunks too — slow on huge archives, but
     * correct, predictable, and avoids needing to persist the
     * secretstream pull state across ticks.
     */
    private function runExtract(JobState $state): void
    {
        $paths    = $state->get('paths');
        $opts     = $state->get('options');
        $progress = $state->get('progress');
        $cursor   = $state->get('cursor');

        $reader = ArchiveReader::openHeader($paths['archive']);
        try {
            if ($reader->isEncrypted()) {
                $reader->prepareEncrypted($this->cipher, $opts['private_key']);
            }

            // Skip past entries we've already fully written.
            $alreadyDone = (int) ($progress['extracted_entries'] ?? 0);
            for ($i = 0; $i < $alreadyDone; $i++) {
                $entry = $reader->nextEntry();
                if ($entry === null) {
                    $cursor['extract_done'] = true;
                    break;
                }
                $remaining = $entry['length'];
                while ($remaining > 0) {
                    $want = (int) min(Format::CHUNK_SIZE, $remaining);
                    $reader->readEntryBytes($want);
                    $remaining -= $want;
                }
            }

            $selection = $opts['selection'] ?? null;

            $budget = self::BUDGET_BYTES;
            $dumpFh = fopen($paths['dump'], 'ab');
            if ($dumpFh === false) {
                throw new RuntimeException('Could not open dump.sql for write.');
            }

            try {
                while ($budget > 0) {
                    $entry = $reader->nextEntry();
                    if ($entry === null) {
                        $cursor['extract_done'] = true;
                        break;
                    }

                    $accept = $this->shouldExtract($entry['name'], $entry['type'], $selection);
                    $dest = ($accept && $entry['type'] === Format::TYPE_FILE)
                        ? $this->resolveDestination($entry['name'], $state)
                        : null;

                    // Open a sink: SQL dump file, target file on disk,
                    // or null (drain into the void). We always consume
                    // the bytes — skipping just means not writing them.
                    $sinkFh = null;
                    if ($accept && $entry['type'] === Format::TYPE_DB_DUMP) {
                        $sinkFh = $dumpFh;
                    } elseif ($accept && $dest !== null) {
                        @mkdir(dirname($dest), 0755, true);
                        $sinkFh = fopen($dest, 'wb');
                        if ($sinkFh === false) {
                            throw new RuntimeException('Could not write file: '.$dest);
                        }
                    }

                    try {
                        $remaining = $entry['length'];
                        while ($remaining > 0) {
                            $want = (int) min(Format::CHUNK_SIZE, $remaining);
                            $chunk = $reader->readEntryBytes($want);
                            if ($sinkFh !== null && $sinkFh !== $dumpFh) {
                                fwrite($sinkFh, $chunk);
                            } elseif ($sinkFh === $dumpFh) {
                                fwrite($dumpFh, $chunk);
                            }
                            $remaining -= strlen($chunk);
                            $progress['processed_bytes'] += strlen($chunk);
                        }
                    } finally {
                        if ($sinkFh !== null && $sinkFh !== $dumpFh) {
                            fclose($sinkFh);
                        }
                    }

                    $progress['extracted_entries']++;
                    $budget -= $entry['length'];
                }
            } finally {
                fclose($dumpFh);
            }
        } finally {
            $reader->close();
        }

        $progress['percent'] = $progress['total_bytes'] > 0
            ? min(100.0, round($progress['processed_bytes'] / $progress['total_bytes'] * 100, 1))
            : 0.0;

        $state->set('cursor', $cursor);
        $state->set('progress', $progress);
        $state->set('message',
            $cursor['extract_done']
                ? 'Restoring database…'
                : 'Extracting… '.$progress['extracted_entries'].' entries'
        );

        if ($cursor['extract_done']) {
            // No DB dump? Skip straight past the restore phase, but
            // still run the rewrite — files might still want URL fixups.
            $state->set('phase', is_file($paths['dump']) && filesize($paths['dump']) > 0 ? 'restore' : 'rewrite');
        }

        $state->save();
    }

    /**
     * Phase 3 — replay dump.sql through DatabaseRestorer in chunks. We
     * track restore_offset so each tick resumes from where it left off.
     */
    private function runRestore(JobState $state): void
    {
        $paths    = $state->get('paths');
        $progress = $state->get('progress');
        $cursor   = $state->get('cursor');

        if (! is_file($paths['dump'])) {
            $state->set('phase', 'rewrite');
            $state->save();
            return;
        }

        // Each tick opens a fresh PDO connection, so the FOREIGN_KEY_CHECKS
        // session variable from the previous tick is gone. We also can't
        // rely on the dump's own SET line — even though it's emitted as
        // a separate statement now, an FK check failure mid-batch would
        // still abort. Disable explicitly at the start of every tick that
        // runs SQL; re-enable when we cross EOF below.
        try {
            $this->db->unprepared('SET FOREIGN_KEY_CHECKS = 0');
        } catch (Throwable) { /* best-effort */ }

        $size = filesize($paths['dump']) ?: 0;
        $offset = (int) $cursor['restore_offset'];

        $fh = fopen($paths['dump'], 'rb');
        if ($fh === false) {
            throw new RuntimeException('Could not read dump.sql.');
        }

        try {
            if (fseek($fh, $offset) !== 0) {
                throw new RuntimeException('Could not seek dump.sql.');
            }

            $restorer = new DatabaseRestorer($this->db);
            $consumed = 0;
            while ($consumed < self::BUDGET_BYTES && $offset < $size) {
                $want = (int) min(Format::CHUNK_SIZE, self::BUDGET_BYTES - $consumed);
                $chunk = fread($fh, $want);
                if ($chunk === false || $chunk === '') break;
                $restorer->feed($chunk);
                $consumed += strlen($chunk);
                $offset += strlen($chunk);
                $restorer->executeReady();
            }

            // If we just hit EOF, drain any final partial.
            if ($offset >= $size) {
                $restorer->finish();
            }

            $progress['restored_statements'] = $restorer->statementsRun() + (int) $progress['restored_statements'];
        } finally {
            fclose($fh);
        }

        $cursor['restore_offset'] = $offset;
        $state->set('cursor', $cursor);
        $state->set('progress', $progress);
        $state->set('message', $offset >= $size
            ? 'Database restored.'
            : 'Restoring database… '.round($offset / max(1, $size) * 100, 1).'%'
        );

        if ($offset >= $size) {
            // Re-enable FK enforcement for any subsequent code paths
            // sharing this connection. Belt-and-braces: the next tick
            // would get a fresh connection anyway.
            try {
                $this->db->unprepared('SET FOREIGN_KEY_CHECKS = 1');
            } catch (Throwable) { /* best-effort */ }

            $state->set('phase', 'rewrite');
        }

        $state->save();
    }

    /**
     * Phase 4 — URL rewrite. Backups taken from one Flarum and
     * restored onto another would otherwise leave the OLD forum URL
     * everywhere — settings, post content, parsed_content. That breaks
     * redirects, login cookies, and any absolute link the old site had
     * embedded.
     *
     * We rewrite occurrences of the source URL (recorded in the
     * archive metadata at export time) to this server's URL (read from
     * config.php). When the URLs match — or one of them is missing —
     * this is a no-op.
     *
     * Best-effort: each table is wrapped in its own try so missing
     * tables on weird Flarum builds don't abort the whole import.
     */
    private function runRewrite(JobState $state): void
    {
        $meta = (array) $state->get('archive_meta');
        $sourceUrl = isset($meta['source_url']) ? rtrim((string) $meta['source_url'], '/') : '';
        $destUrl   = rtrim((string) ($this->config['url'] ?? ''), '/');

        if ($sourceUrl !== '' && $destUrl !== '' && $sourceUrl !== $destUrl) {
            $stats = ['settings' => 0, 'posts_content' => 0, 'posts_parsed' => 0];

            // Settings — `forum.url` and any extension setting that
            // happens to embed the old URL. Run as REPLACE() so a
            // single value can rewrite multiple substrings.
            try {
                $stats['settings'] = $this->db->update(
                    'UPDATE `settings` SET `value` = REPLACE(`value`, ?, ?) WHERE `value` LIKE ?',
                    [$sourceUrl, $destUrl, '%'.$sourceUrl.'%']
                );
            } catch (Throwable) { /* ignore — table missing or schema differs */ }

            // Post content — Flarum 2 stores XML-tagged formatted text
            // here. Absolute URLs inside that XML are usually only the
            // forum's own canonical URL, so a textual replace is safe
            // for the vast majority of forums.
            try {
                $stats['posts_content'] = $this->db->update(
                    'UPDATE `posts` SET `content` = REPLACE(`content`, ?, ?) WHERE `content` LIKE ?',
                    [$sourceUrl, $destUrl, '%'.$sourceUrl.'%']
                );
            } catch (Throwable) { /* ignore */ }

            // Post parsed content — exists on Flarum 1.x, may be
            // absent on Flarum 2+. Try both column variants.
            try {
                $stats['posts_parsed'] = $this->db->update(
                    'UPDATE `posts` SET `parsed_content` = REPLACE(`parsed_content`, ?, ?) WHERE `parsed_content` LIKE ?',
                    [$sourceUrl, $destUrl, '%'.$sourceUrl.'%']
                );
            } catch (Throwable) { /* ignore */ }

            $state->set('rewrite_stats', $stats);
            $state->set('message', 'Rewrote URL: '.$sourceUrl.' → '.$destUrl);
        } else {
            $state->set('message', 'Skipping URL rewrite (URLs match or unknown).');
        }

        $state->set('phase', 'finalize');
        $state->save();
    }

    private function runFinalize(JobState $state): void
    {
        $paths = $state->get('paths');
        @unlink($paths['dump']);
        @unlink($paths['archive']);
        $state->set('phase', 'done');
        $state->set('message', 'Restore complete.');
        $state->save();
    }

    /**
     * Normalise the user's selection into a stable shape:
     *   ['db' => bool, 'assets' => bool, 'storage' => bool,
     *    'extensions' => true | false | list<string>]
     *
     * `null` short-circuits to restore-everything for backwards
     * compatibility with older import requests.
     *
     * @param array<string, mixed>|null $raw
     * @return array{db: bool, assets: bool, storage: bool, extensions: bool|list<string>}|null
     */
    private function normaliseSelection(?array $raw): ?array
    {
        if ($raw === null) return null;

        $extensions = $raw['extensions'] ?? true;
        if (is_array($extensions)) {
            $extensions = array_values(array_filter(array_map(
                fn ($v) => is_string($v) ? $v : null,
                $extensions
            )));
        } elseif (! is_bool($extensions)) {
            $extensions = (bool) $extensions;
        }

        return [
            'db'         => ! empty($raw['db']),
            'assets'     => ! empty($raw['assets']),
            'storage'    => ! empty($raw['storage']),
            'extensions' => $extensions,
        ];
    }

    /**
     * Decide whether an entry should be extracted, based on the user's
     * import selection. The DB is all-or-nothing; file roots
     * (assets/storage) flip per checkbox; extensions can be filtered
     * down to specific top-level directory names. Unknown roots fall
     * back to "restore" so future archive shapes don't get silently
     * dropped.
     */
    private function shouldExtract(string $name, int $type, ?array $selection): bool
    {
        if ($selection === null) return true;

        if ($type === Format::TYPE_DB_DUMP) {
            return ! empty($selection['db']);
        }

        $slash = strpos($name, '/');
        if ($slash === false) return false;

        $root = substr($name, 0, $slash);
        return match ($root) {
            'assets'  => ! empty($selection['assets']),
            'storage' => ! empty($selection['storage']),
            'extensions' => $this->isExtensionAllowed($name, $selection['extensions'] ?? null),
            // composer.json / composer.lock follow the extensions
            // toggle: if the admin opted out of all extensions, they
            // didn't ask to restore composer either.
            'project' => $this->hasAnyExtensionSelected($selection),
            default   => true,
        };
    }

    private function hasAnyExtensionSelected(array $selection): bool
    {
        $ext = $selection['extensions'] ?? false;
        if ($ext === true) return true;
        if (is_array($ext) && count($ext) > 0) return true;
        return false;
    }

    private function isExtensionAllowed(string $name, mixed $extSelection): bool
    {
        if ($extSelection === true)  return true;
        if ($extSelection === false || $extSelection === null) return false;
        if (! is_array($extSelection)) return false;

        $rest = substr($name, strlen('extensions/'));
        $cut  = strpos($rest, '/');
        $extDir = $cut === false ? $rest : substr($rest, 0, $cut);
        return in_array($extDir, $extSelection, true);
    }

    /**
     * Build a `extensionId → absolute base directory` map from the
     * archive metadata. Used by `resolveDestination` to know where to
     * restore vendor/ extensions vs workbench/ ones.
     *
     * Backwards compatible with the v1 manifest shape, where
     * `manifest.extensions` was a flat `string[]` of workbench
     * directory names. Those always restore to `workbench/<name>`.
     *
     * @return array<string, string>  id → absolute path
     */
    private function extensionDestinationMap(JobState $state): array
    {
        $meta = (array) $state->get('archive_meta');
        $manifest = (array) ($meta['manifest'] ?? []);
        $exts = (array) ($manifest['extensions'] ?? []);

        $base = rtrim($this->appPaths->base, '/\\');
        $map = [];
        foreach ($exts as $ext) {
            if (is_string($ext)) {
                // v1 archive — dirname → workbench/<dirname>
                $map[$ext] = $base.DIRECTORY_SEPARATOR.'workbench'.DIRECTORY_SEPARATOR.$ext;
                continue;
            }
            if (! is_array($ext)) continue;
            $id = (string) ($ext['id'] ?? '');
            $rel = (string) ($ext['relative'] ?? '');
            if ($id === '' || $rel === '') continue;
            // Forbid funny stuff in the recorded path (we trust the
            // meta header but it travelled across servers).
            if (str_contains($rel, '..') || str_contains($rel, "\0")) continue;
            $map[$id] = $base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
        }
        return $map;
    }

    /**
     * Map a logical entry name like "assets/avatars/1.png" or
     * "extensions/ramon-verified/extend.php" to the absolute path on
     * this install. Returns null for any name that escapes the
     * whitelisted roots, or for an unknown extension id.
     */
    private function resolveDestination(string $name, JobState $state): ?string
    {
        $name = ltrim($name, '/');
        if (str_contains($name, '..') || str_contains($name, "\0") || str_contains($name, '\\')) {
            return null;
        }

        $slash = strpos($name, '/');
        if ($slash === false) return null;

        $root = substr($name, 0, $slash);
        $rest = substr($name, $slash + 1);
        if ($rest === '' || $rest === false) return null;

        if ($root === 'extensions') {
            // For an entry like "extensions/<id>/<inner>", look up
            // <id> in the manifest map to learn where this extension
            // originally lived (workbench/<name> or vendor/<vendor>/<name>).
            $cut = strpos($rest, '/');
            if ($cut === false) return null;
            $extId = substr($rest, 0, $cut);
            $inner = substr($rest, $cut + 1);
            if ($inner === '' || $inner === false) return null;

            $map = $this->extensionDestinationMap($state);
            $base = $map[$extId] ?? null;
            if ($base === null) return null;
        } elseif ($root === 'project') {
            // Project-root files. Only composer.json / composer.lock
            // are accepted here — any other path is rejected outright
            // so a hostile archive can't drop, say, a `.htaccess` or
            // a public/ override into the install.
            if (! in_array($rest, ['composer.json', 'composer.lock'], true)) {
                return null;
            }
            $base = rtrim($this->appPaths->base, '/\\');
            $inner = $rest;
        } else {
            $base = match ($root) {
                'assets'  => rtrim($this->appPaths->public, '/\\').DIRECTORY_SEPARATOR.'assets',
                'storage' => rtrim($this->appPaths->storage, '/\\'),
                default   => null,
            };
            $inner = $rest;
        }
        if ($base === null) return null;

        if (! is_dir($base)) {
            @mkdir($base, 0755, true);
        }

        return $base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $inner);
    }
}
