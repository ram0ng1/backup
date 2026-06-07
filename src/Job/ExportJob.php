<?php

namespace Ramon\Backup\Job;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Ramon\Backup\Archive\ArchiveWriter;
use Ramon\Backup\Archive\Format;
use Ramon\Backup\Crypto\BackupCipher;
use Ramon\Backup\Database\DatabaseDumper;
use Ramon\Backup\Database\Dialect;
use Ramon\Backup\Extensions\Inventory;
use Ramon\Backup\Models\Backup;
use Ramon\Backup\StoragePaths;
use RuntimeException;
use Throwable;

/**
 * Orchestrates a single export job across multiple HTTP ticks.
 *
 * Lifecycle ("phases" in the state file):
 *   scan      → enumerate files, persist manifest
 *   db_dump   → write SQL to dump.sql incrementally (skipped if no DB)
 *   bundle    → open archive, write entries (db + files) one at a time,
 *               possibly resuming mid-entry across ticks
 *   finalize  → close stream, rename to final filename, insert DB row,
 *               delete tmp dir
 *   done      → terminal
 *
 * Per-tick budget: at most BUDGET_BYTES of payload bytes get emitted.
 * Phase transitions are checked between work items, so a tick may end
 * partway through a single file's bytes.
 */
class ExportJob
{
    /** Bytes of payload emitted per tick before pausing. */
    public const BUDGET_BYTES = 4_194_304; // 4 MB

    /** Hard ceiling on a single file we'll bundle (skip larger). */
    public const FILE_SIZE_LIMIT = 2 * 1024 * 1024 * 1024; // 2 GB

    /**
     * Directories we never descend into while scanning extension or
     * project paths. Without these, a single workbench extension with
     * `node_modules/` can balloon a backup from a few MB to hundreds
     * of MB and tens of thousands of entries — and none of it is
     * useful at restore time (npm/yarn / git can rebuild it cleanly).
     *
     * Matched by directory basename anywhere in the tree, so e.g.
     * a nested `js/node_modules/` is also pruned.
     */
    private const PRUNE_DIR_NAMES = [
        // Package managers
        'node_modules' => true,
        // Build artifacts that are reproducible from source. We
        // intentionally KEEP `dist` because Flarum loads it at
        // runtime — it's regenerable, but the destination would
        // otherwise need `npm run build` before the extension works.
        'dist-typings' => true,
        '.parcel-cache' => true,
        // Version control
        '.git'         => true,
        '.svn'         => true,
        '.hg'          => true,
        // Tooling / IDE
        '.idea'        => true,
        '.vscode'      => true,
        '.history'     => true,
        // Test / coverage outputs
        'coverage'     => true,
        '.nyc_output'  => true,
        // Python noise
        '__pycache__'  => true,
        // We never want to recurse into a nested vendor/ inside an
        // extension dir (would pull thousands of composer files).
        'vendor'       => true,
    ];

    /**
     * The live database connection. Declared as the concrete
     * {@see Connection} because the dump path needs `getDriverName()`
     * (via {@see Dialect::detect}), which {@see ConnectionInterface}
     * does not expose. The constructor accepts the interface so Flarum's
     * container (which binds `ConnectionInterface`, not the concrete
     * class) can inject it, then narrows once here.
     */
    protected Connection $db;

    public function __construct(
        protected StoragePaths $paths,
        protected Paths $appPaths,
        ConnectionInterface $db,
        protected BackupCipher $cipher,
        protected Config $config,
        protected Inventory $inventory
    ) {
        $this->db = $db;
    }

    /**
     * Create a fresh export job, returning the new state. The caller
     * persists the JobState and pumps `runTick()` until phase=done.
     *
     * `contents.extensions` is permissive:
     *   - bool      — true bundles every installed extension, false skips
     *   - string[]  — bundle exactly these extension ids (workbench or vendor)
     *
     * @param array{db: bool, assets: bool, storage: bool, extensions: bool|list<string>} $contents
     * @param array{enabled: bool, public_key?: string|null} $encryption
     * @param string|null $targetDialect  Where the dump is meant to be restored
     *                                    (mysql, mariadb, postgres, sqlite). When
     *                                    null, falls back to the source engine —
     *                                    i.e. a same-engine restore.
     * @param int|null $userId
     */
    public function start(string $jobId, array $contents, array $encryption, ?string $targetDialect, ?int $userId): JobState
    {
        $dir = $this->paths->exportJobDir($jobId);

        $useEncryption = ! empty($encryption['enabled']);
        $providedKey = isset($encryption['public_key']) ? trim((string) $encryption['public_key']) : '';

        if ($useEncryption && ! $this->cipher->isAvailable()) {
            throw new RuntimeException('libsodium is not available — cannot encrypt the archive.');
        }
        if ($useEncryption && $providedKey === '' && ! $this->cipher->hasPublicKey()) {
            throw new RuntimeException('Encryption is enabled but no public key is configured.');
        }

        // Resolve target dialect early so we fail fast on a typo
        // rather than midway through `runDbDump`. `null` means
        // "same as source" — picked up at runtime by DatabaseDumper.
        $resolvedTarget = null;
        if ($targetDialect !== null && $targetDialect !== '') {
            $resolvedTarget = Dialect::parse($targetDialect)->value;
        }

        $initial = [
            'job_id'        => $jobId,
            'created_by'    => $userId,
            'started_at'    => time(),
            'phase'         => 'scan',
            'message'       => 'Preparing…',
            'contents'      => [
                'db'         => ! empty($contents['db']),
                'assets'     => ! empty($contents['assets']),
                'storage'    => ! empty($contents['storage']),
                // Preserve the original shape so the scan phase can
                // tell "all extensions" (true) apart from "this list".
                'extensions' => $this->normaliseExtensionSelection($contents['extensions'] ?? false),
            ],
            'encryption'    => [
                'enabled'    => $useEncryption,
                'use_external_key' => $useEncryption && $providedKey !== '',
                'external_public_key' => $useEncryption && $providedKey !== '' ? $providedKey : null,
            ],
            // Target engine the dump is being prepared for. Stored in
            // the job state so every tick uses the same emitter, and
            // travels into the archive header so the import side can
            // refuse a dump targeting a different engine than the
            // destination connection.
            'target_dialect' => $resolvedTarget,
            'progress'      => [
                'total_bytes'       => 0,
                'processed_bytes'   => 0,
                'total_files'       => 0,
                'processed_files'   => 0,
                'percent'           => 0.0,
            ],
            'paths'         => [
                'dir'         => $dir,
                'manifest'    => $dir.DIRECTORY_SEPARATOR.'manifest.json',
                'dump'        => $dir.DIRECTORY_SEPARATOR.'dump.sql',
                'archive'     => $dir.DIRECTORY_SEPARATOR.'archive.partial',
            ],
            'cursor' => [
                'tables'         => [],
                'table_idx'      => 0,
                'table_offset'   => 0,
                'table_key'      => null,
                'table_total'    => 0,
                'wrote_preamble' => false,
                'wrote_drops'    => false,
                'bundle_step'    => 'init',  // init | db_entry | files | trailer | done
                'file_idx'       => 0,
                'file_offset'    => 0,
                'db_offset'      => 0,
            ],
            // Notes from the introspector about lossy translations
            // (unsupported types, generated columns, etc.). Accumulated
            // across ticks and surfaced on the completion screen so
            // the admin knows what didn't survive verbatim.
            'db_warnings'   => [],
            // Resumable scan plan — built lazily on the first scan tick
            // (see runScan/buildScanPlan), then advanced one target per
            // tick so no single request walks the whole install.
            'scan'          => [],
        ];

        return JobState::create($dir.DIRECTORY_SEPARATOR.'job.json', $initial);
    }

    /**
     * Run one tick of work. Mutates and saves the JobState. Returns the
     * (possibly updated) state.
     */
    public function runTick(JobState $state): JobState
    {
        $phase = $state->get('phase');

        try {
            match ($phase) {
                'scan'     => $this->runScan($state),
                'db_dump'  => $this->runDbDump($state),
                'bundle'   => $this->runBundle($state),
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
     * Phase 1 — enumerate every file we'll bundle and compute total
     * size, then seed the DB cursor.
     *
     * The scan is chunked across ticks: a plan of "targets" (each asset
     * root, the storage root, one entry per selected extension, and the
     * composer manifest) is built once, then ONE target is walked per
     * tick and its files appended to an NDJSON manifest. This bounds a
     * single HTTP request to one root's stat walk instead of the whole
     * install at once, so a large multi-extension forum no longer risks
     * blowing max_execution_time on the very first tick. (A single
     * pathological root — typically a huge storage/ tree — is still
     * walked in one tick; installs that large should run the CLI
     * exporter, which has no request timeout.)
     */
    private function runScan(JobState $state): void
    {
        $scan = (array) $state->get('scan', []);
        if (empty($scan['built'])) {
            $scan = $this->buildScanPlan($state);
            $state->set('scan', $scan);
        }

        $targets = $scan['targets'];
        $idx     = (int) $scan['idx'];

        if ($idx < count($targets)) {
            $this->scanOneTarget($state, $scan, $targets[$idx]);
            $scan['idx'] = $idx + 1;
            $state->set('scan', $scan);

            $progress = $state->get('progress');
            $progress['total_files'] = (int) $scan['files'];
            $progress['total_bytes'] = (int) $scan['bytes'];
            $state->set('progress', $progress);

            $state->set('message', sprintf('Scanning… (%d/%d)', $idx + 1, count($targets)));
            $state->save();
            return; // stay in 'scan'; the next tick processes the next target
        }

        $this->finalizeScan($state, $scan);
    }

    /**
     * Build the (persisted) list of scan targets from the content
     * selection, and clear any stale manifest so the NDJSON appends
     * start from a clean slate. The selection drives WHICH extensions go
     * in; the inventory drives WHERE they live (workbench/ for local
     * dev, vendor/ for composer-managed).
     *
     * @return array{built: bool, targets: list<array<string, mixed>>, idx: int, files: int, bytes: int, counts: array{asset: int, storage: int, extension: int}, ext_descriptors: list<array<string, mixed>>, has_composer: bool}
     */
    private function buildScanPlan(JobState $state): array
    {
        $contents = $state->get('contents');
        @unlink($state->get('paths')['manifest']);

        $targets = [];

        if (! empty($contents['assets'])) {
            $targets[] = [
                'kind' => 'assets',
                'base' => rtrim($this->appPaths->public, '/\\').DIRECTORY_SEPARATOR.'assets',
            ];
        }
        if (! empty($contents['storage'])) {
            $storageBase = rtrim($this->appPaths->storage, '/\\');
            $targets[] = [
                'kind' => 'storage',
                'base' => $storageBase,
                // Skip the directories that hold OUR backup data —
                // backing up backups is circular.
                'skip' => [
                    $storageBase.DIRECTORY_SEPARATOR.'backups',
                    $storageBase.DIRECTORY_SEPARATOR.'backup-tmp',
                ],
            ];
        }

        $extSelection = $contents['extensions'] ?? false;
        if ($extSelection !== false) {
            $wantAll   = $extSelection === true;
            $wantedIds = is_array($extSelection) ? array_flip($extSelection) : [];

            foreach ($this->inventory->list() as $ext) {
                if (! $wantAll && ! isset($wantedIds[$ext['id']])) continue;
                $targets[] = [
                    'kind' => 'ext',
                    'base' => $ext['path'],
                    'ext'  => [
                        'id'       => $ext['id'],
                        'name'     => $ext['name'],
                        'title'    => $ext['title'],
                        'version'  => $ext['version'],
                        'location' => $ext['location'],
                        // Original path on the source forum, relative to
                        // the Flarum base — the import side restores into
                        // the same layout (vendor/ vs workbench/).
                        'relative' => $ext['relative'],
                    ],
                ];
            }

            // composer.json + composer.lock travel alongside whenever the
            // user is bundling extensions: vendor/ extensions are useless
            // on the destination without the matching composer manifest
            // (the next `composer install` would simply wipe them).
            $targets[] = ['kind' => 'composer'];
        }

        return [
            'built'           => true,
            'targets'         => $targets,
            'idx'             => 0,
            'files'           => 0,
            'bytes'           => 0,
            'counts'          => ['asset' => 0, 'storage' => 0, 'extension' => 0],
            'ext_descriptors' => [],
            'has_composer'    => false,
        ];
    }

    /**
     * Walk a single scan target, append its files to the NDJSON
     * manifest, and roll the running counts/descriptors into $scan.
     *
     * @param array<string, mixed> $target
     */
    private function scanOneTarget(JobState $state, array &$scan, array $target): void
    {
        $manifestPath = $state->get('paths')['manifest'];
        $files = [];
        $bytes = 0;

        switch ($target['kind']) {
            case 'assets':
                $this->collectFiles($target['base'], 'assets', $files, $bytes);
                $scan['counts']['asset'] += count($files);
                break;

            case 'storage':
                $this->collectFiles($target['base'], 'storage', $files, $bytes, $target['skip'] ?? []);
                $scan['counts']['storage'] += count($files);
                break;

            case 'ext':
                $ext = $target['ext'];
                $this->collectFiles($target['base'], 'extensions/'.$ext['id'], $files, $bytes);
                $scan['counts']['extension'] += count($files);
                $scan['ext_descriptors'][] = [
                    'id'       => $ext['id'],
                    'name'     => $ext['name'],
                    'title'    => $ext['title'],
                    'version'  => $ext['version'],
                    'location' => $ext['location'],
                    'relative' => $ext['relative'],
                    'files'    => count($files),
                    'bytes'    => $bytes,
                ];
                break;

            case 'composer':
                foreach (['composer.json', 'composer.lock'] as $rel) {
                    $abs = rtrim($this->appPaths->base, '/\\').DIRECTORY_SEPARATOR.$rel;
                    if (is_file($abs)) {
                        $size = filesize($abs) ?: 0;
                        $files[] = ['name' => 'project/'.$rel, 'absolute' => $abs, 'size' => $size];
                        $bytes += $size;
                        $scan['has_composer'] = true;
                    }
                }
                break;
        }

        $this->appendManifest($manifestPath, $files);
        $scan['files'] += count($files);
        $scan['bytes'] += $bytes;
    }

    /**
     * Append file entries to the NDJSON manifest, one JSON object per
     * line. Both a JSON-encode failure (e.g. a filename that is not
     * valid UTF-8) and a write failure throw loudly — a silently dropped
     * manifest would otherwise yield a "successful" backup missing every
     * bundled file.
     *
     * @param list<array{name: string, absolute: string, size: int}> $files
     */
    private function appendManifest(string $path, array $files): void
    {
        if (empty($files)) return;

        $lines = '';
        foreach ($files as $f) {
            $line = json_encode($f, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                throw new RuntimeException(
                    'Could not encode manifest entry for "'.($f['name'] ?? '?').'": '.json_last_error_msg()
                );
            }
            $lines .= $line."\n";
        }

        if (@file_put_contents($path, $lines, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not write file manifest to staging dir.');
        }
    }

    /**
     * Every scan target walked — assemble the summary the import UI reads
     * (per-section counts + per-extension descriptors), seed the DB table
     * cursor, fold totals into progress, and advance to the next phase.
     */
    private function finalizeScan(JobState $state, array $scan): void
    {
        $contents    = $state->get('contents');
        $bundledExts = $scan['ext_descriptors'];

        $state->set('bundled_extensions', $bundledExts);
        $state->set('has_composer', (bool) $scan['has_composer']);

        $state->set('cursor', array_merge($state->get('cursor'), [
            'tables' => ! empty($contents['db']) ? $this->listTablesSafely() : [],
        ]));

        // Compact summary that travels in the archive header so the
        // import UI can render selection checkboxes without parsing the
        // entry stream up front.
        $summary = [
            'asset_count'     => (int) $scan['counts']['asset'],
            'storage_count'   => (int) $scan['counts']['storage'],
            'extension_count' => (int) $scan['counts']['extension'],
            'extensions'      => $bundledExts,
            'has_composer'    => (bool) $scan['has_composer'],
        ];
        $state->set('manifest_summary', $summary);

        $progress = $state->get('progress');
        $progress['total_files'] = (int) $scan['files'];
        $progress['total_bytes'] = (int) $scan['bytes'];
        // We do not know SQL size up front; reflect it once dump finishes.
        $state->set('progress', $progress);

        $state->set('phase', ! empty($contents['db']) ? 'db_dump' : 'bundle');
        $state->set('message', ! empty($contents['db']) ? 'Dumping database…' : 'Bundling files…');
        $state->save();
    }

    /**
     * Phase 2 — emit SQL into dump.sql. Each tick processes a few
     * tables / rows and stops when BUDGET_BYTES is reached.
     */
    private function runDbDump(JobState $state): void
    {
        $cursor = $state->get('cursor');
        $paths = $state->get('paths');
        $dumpFile = $paths['dump'];

        $target = $state->get('target_dialect');
        $dumper = new DatabaseDumper($this->db, $target ? Dialect::parse((string) $target) : null);

        $fh = @fopen($dumpFile, 'ab');
        if ($fh === false) {
            throw new RuntimeException('Could not open dump.sql for write.');
        }

        try {
            $written = 0;

            if (! $cursor['wrote_preamble']) {
                $sql = $dumper->preamble();
                fwrite($fh, $sql);
                $written += strlen($sql);
                $cursor['wrote_preamble'] = true;
            }

            // Drop every table up front (before any CREATE) so a restore
            // is robust against whatever schema currently exists on the
            // destination. See DatabaseDumper::dropAllTables().
            if (empty($cursor['wrote_drops'])) {
                $sql = $dumper->dropAllTables();
                fwrite($fh, $sql);
                $written += strlen($sql);
                $cursor['wrote_drops'] = true;
            }

            while ($written < self::BUDGET_BYTES && $cursor['table_idx'] < count($cursor['tables'])) {
                $table = $cursor['tables'][$cursor['table_idx']];

                if ($cursor['table_offset'] === 0) {
                    // First touch on this table — count rows (once, for the
                    // progress readout) then emit DDL.
                    $cursor['table_total'] = $dumper->countRows($table);
                    $sql = $dumper->dumpSchema($table);
                    fwrite($fh, $sql);
                    $written += strlen($sql);
                }

                $batch = $dumper->dumpDataBatch($table, $cursor['table_offset'], $cursor['table_key'] ?? null);
                if ($batch['consumed'] === 0) {
                    // Table exhausted — advance to the next.
                    $cursor['table_idx']++;
                    $cursor['table_offset'] = 0;
                    $cursor['table_key'] = null;
                    $cursor['table_total'] = 0;
                    continue;
                }

                fwrite($fh, $batch['sql']);
                $written += strlen($batch['sql']);
                $cursor['table_offset'] += $batch['consumed'];
                $cursor['table_key'] = $batch['after_key'];
            }

            $allTablesDone = $cursor['table_idx'] >= count($cursor['tables']);
            if ($allTablesDone) {
                fwrite($fh, $dumper->epilogue());
            }
        } finally {
            fclose($fh);
        }

        $state->set('cursor', $cursor);

        // Roll up any lossy-translation notes the introspector raised
        // this tick. Each instance only carries warnings for the
        // tables it personally described, so the persistent list in
        // state is the union across all ticks.
        $existing = (array) $state->get('db_warnings', []);
        $merged = array_values(array_unique(array_merge(
            array_map('strval', $existing),
            $dumper->warnings(),
        )));
        $state->set('db_warnings', $merged);

        if ($allTablesDone) {
            $state->set('message', 'Database dump complete. Bundling…');
        } else {
            $tableCount = count($cursor['tables']);
            $idx        = (int) $cursor['table_idx'];
            $name       = $cursor['tables'][$idx] ?? '?';
            $rowsDone   = (int) $cursor['table_offset'];
            $rowsTotal  = (int) ($cursor['table_total'] ?? 0);

            $rows = $rowsTotal > 0
                ? number_format($rowsDone) . '/' . number_format($rowsTotal) . ' rows'
                : number_format($rowsDone) . ' rows';

            $state->set('message', sprintf(
                'Dumping database… table %d/%d (%s) — %s',
                $idx + 1,
                $tableCount,
                $name,
                $rows
            ));
        }

        // Structured per-table progress so a poller (admin UI) can render
        // its own indicator instead of parsing the message string.
        $state->set('db_progress', [
            'table_index' => (int) $cursor['table_idx'],
            'table_count' => count($cursor['tables']),
            'table_name'  => $cursor['tables'][$cursor['table_idx']] ?? null,
            'rows_done'   => (int) $cursor['table_offset'],
            'rows_total'  => (int) ($cursor['table_total'] ?? 0),
        ]);

        if ($allTablesDone) {
            // Now that the dump.sql is sized, fold its bytes into the
            // bundle progress total so the percent bar reflects work
            // remaining across both DB and files in one number.
            $progress = $state->get('progress');
            $progress['total_bytes'] = (int) $progress['total_bytes'] + (filesize($paths['dump']) ?: 0);
            $state->set('progress', $progress);

            $state->set('phase', 'bundle');
        }

        $state->save();
    }

    /**
     * Phase 3 — copy the SQL dump and every file into the archive,
     * resuming mid-entry between ticks if necessary.
     */
    private function runBundle(JobState $state): void
    {
        $cursor   = $state->get('cursor');
        $paths    = $state->get('paths');
        $progress = $state->get('progress');
        $contents = $state->get('contents');
        $enc      = $state->get('encryption');

        $archivePath = $paths['archive'];

        // Open or resume writer.
        $writer = $this->openWriter($state, $archivePath, $enc);

        try {
            $budgetLeft = self::BUDGET_BYTES;

            while ($budgetLeft > 0 && $cursor['bundle_step'] !== 'done') {
                if ($cursor['bundle_step'] === 'init') {
                    if (! empty($contents['db']) && is_file($paths['dump'])) {
                        $size = filesize($paths['dump']) ?: 0;
                        $writer->beginEntry(Format::DB_ENTRY_NAME, Format::TYPE_DB_DUMP, $size);
                        $cursor['bundle_step'] = 'db_entry';
                        $cursor['db_offset']   = 0;
                    } else {
                        $cursor['bundle_step'] = 'files';
                    }
                    continue;
                }

                if ($cursor['bundle_step'] === 'db_entry') {
                    $size = filesize($paths['dump']) ?: 0;
                    $remaining = $size - $cursor['db_offset'];
                    if ($remaining <= 0) {
                        $cursor['bundle_step'] = 'files';
                        continue;
                    }
                    $written = $this->streamRange($paths['dump'], $cursor['db_offset'], min($remaining, $budgetLeft), $writer);
                    $cursor['db_offset'] += $written;
                    $progress['processed_bytes'] += $written;
                    $budgetLeft -= $written;
                    continue;
                }

                if ($cursor['bundle_step'] === 'files') {
                    $manifest = $this->loadManifest($paths['manifest']);
                    if ($cursor['file_idx'] >= count($manifest)) {
                        $cursor['bundle_step'] = 'trailer';
                        continue;
                    }

                    $entry = $manifest[$cursor['file_idx']];
                    $abs   = $entry['absolute'];
                    $size  = $entry['size'];

                    if (! is_file($abs)) {
                        // File vanished mid-export. Skip it so the rest
                        // of the bundle can still complete.
                        $cursor['file_idx']++;
                        $cursor['file_offset'] = 0;
                        $progress['processed_files']++;
                        continue;
                    }

                    if ($cursor['file_offset'] === 0) {
                        $writer->beginEntry($entry['name'], Format::TYPE_FILE, $size);
                    }

                    $remaining = $size - $cursor['file_offset'];
                    if ($remaining <= 0) {
                        $cursor['file_idx']++;
                        $cursor['file_offset'] = 0;
                        $progress['processed_files']++;
                        continue;
                    }

                    $written = $this->streamRange($abs, $cursor['file_offset'], min($remaining, $budgetLeft), $writer);
                    $cursor['file_offset'] += $written;
                    $progress['processed_bytes'] += $written;
                    $budgetLeft -= $written;
                    if ($written === 0) {
                        // Avoid an infinite loop if the file is unreadable.
                        $cursor['file_idx']++;
                        $cursor['file_offset'] = 0;
                        $progress['processed_files']++;
                    }
                    continue;
                }

                if ($cursor['bundle_step'] === 'trailer') {
                    $writer->finalize();
                    $cursor['bundle_step'] = 'done';
                    continue;
                }
            }

            // Persist encryption state so the next tick can resume.
            $writer->flushEncryptedBuffer();
            $encState = $writer->serializeEncryptionState();
            if ($encState !== null) {
                $state->setBinary('stream_state', $encState['state']);
                // After flushEncryptedBuffer, buffer is empty — but
                // record it explicitly for completeness.
                $state->setBinary('stream_buffer', $encState['buffer']);
            }
        } finally {
            $writer->close();
        }

        $progress['percent'] = $progress['total_bytes'] > 0
            ? min(100.0, round($progress['processed_bytes'] / $progress['total_bytes'] * 100, 1))
            : 0.0;

        $state->set('cursor', $cursor);
        $state->set('progress', $progress);
        $state->set('message',
            $cursor['bundle_step'] === 'done'
                ? 'Finalising…'
                : 'Bundling files… '.$progress['processed_files'].'/'.$progress['total_files']
        );

        if ($cursor['bundle_step'] === 'done') {
            $state->set('phase', 'finalize');
        }

        $state->save();
    }

    /**
     * Phase 4 — promote the partial archive to the canonical backups
     * dir, write the DB row, and delete the per-job tmp dir.
     */
    private function runFinalize(JobState $state): void
    {
        $paths    = $state->get('paths');
        $contents = $state->get('contents');
        $enc      = $state->get('encryption');

        // Insert a placeholder row so we can derive the final filename
        // from its ID, then rename + update.
        // `target_dialect` is left NULL for same-engine backups so the
        // list UI can cheaply tell "this is a normal backup" apart from
        // "this was retargeted to a different engine" without having to
        // look up the source.
        $row = Backup::create([
            'filename'       => 'pending.flarum',
            'size_bytes'     => 0,
            'encrypted'      => ! empty($enc['enabled']),
            'contents'       => implode(',', array_keys(array_filter($contents))),
            'flarum_version' => $this->detectFlarumVersion(),
            'php_version'    => PHP_VERSION,
            'target_dialect' => $state->get('target_dialect'),
            'created_by'     => $state->get('created_by'),
        ]);

        $finalName = $this->paths->generateFilename((int) $row->id);
        $finalPath = $this->paths->backupsDir().DIRECTORY_SEPARATOR.$finalName;

        if (! @rename($paths['archive'], $finalPath)) {
            // Cleanup placeholder if the move failed.
            $row->delete();
            throw new RuntimeException('Could not move archive into place.');
        }

        $row->filename = $finalName;
        $row->size_bytes = filesize($finalPath) ?: 0;
        $row->save();

        // Tmp dir cleanup. Keep the job state file for one extra tick
        // so the JS can read terminal status; it's wiped by the JS
        // dismiss action via DELETE on the export endpoint.
        @unlink($paths['dump']);
        @unlink($paths['manifest']);

        $state->set('phase', 'done');
        $state->set('message', 'Backup complete.');
        $state->set('result', [
            'backup_id' => (int) $row->id,
            'filename'  => $finalName,
            'size'      => $row->size_bytes,
        ]);
        $state->save();
    }

    /**
     * Open the writer for the current tick — first tick creates the
     * file + header; later ticks resume by appending.
     */
    private function openWriter(JobState $state, string $archivePath, array $enc): ArchiveWriter
    {
        $exists = is_file($archivePath);

        if (! $exists) {
            // First bundle tick.
            $meta = $this->buildMeta($state);

            if (! empty($enc['enabled'])) {
                $symmetricKey = $this->cipher->generateSymmetricKey();
                $wrappedKey = ! empty($enc['use_external_key']) && ! empty($enc['external_public_key'])
                    ? $this->cipher->wrapSymmetricKeyWith($symmetricKey, $enc['external_public_key'])
                    : $this->cipher->wrapSymmetricKey($symmetricKey);

                $writer = ArchiveWriter::createEncrypted($archivePath, $meta, $wrappedKey, $symmetricKey);
                sodium_memzero($symmetricKey);
                return $writer;
            }

            return ArchiveWriter::createPlain($archivePath, $meta);
        }

        // Resume.
        if (! empty($enc['enabled'])) {
            $streamState = $state->getBinary('stream_state');
            $buffer = $state->getBinary('stream_buffer') ?? '';
            if ($streamState === null) {
                throw new RuntimeException('Encrypted bundle was interrupted but stream state is missing.');
            }
            return ArchiveWriter::resumeEncrypted($archivePath, $streamState, $buffer);
        }

        return ArchiveWriter::resumePlain($archivePath);
    }

    /**
     * Read up to $maxBytes from $sourcePath starting at $offset and
     * push them through the writer's appendEntryBytes(). Returns the
     * actual byte count consumed (≤ maxBytes).
     */
    private function streamRange(string $sourcePath, int $offset, int $maxBytes, ArchiveWriter $writer): int
    {
        if ($maxBytes <= 0) return 0;
        $fh = @fopen($sourcePath, 'rb');
        if ($fh === false) return 0;

        try {
            if (fseek($fh, $offset) !== 0) {
                return 0;
            }
            $consumed = 0;
            while ($consumed < $maxBytes) {
                $want = (int) min(Format::CHUNK_SIZE, $maxBytes - $consumed);
                $chunk = fread($fh, $want);
                if ($chunk === false || $chunk === '') break;
                $writer->appendEntryBytes($chunk);
                $consumed += strlen($chunk);
            }
            return $consumed;
        } finally {
            fclose($fh);
        }
    }

    /**
     * Recursively walk a directory, accumulating files into the manifest
     * and into $totalBytes. The iterator NEVER descends into a directory
     * whose basename is in PRUNE_DIR_NAMES (node_modules, .git, etc.) or
     * whose absolute path appears in $skipDirs.
     *
     * @param array<string, array{name: string, absolute: string, size: int}> $files
     */
    private function collectFiles(string $base, string $logicalPrefix, array &$files, int &$totalBytes, array $skipDirs = []): void
    {
        if (! is_dir($base)) return;
        $skipMap  = array_flip($skipDirs);
        $pruneMap = self::PRUNE_DIR_NAMES;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                function (\SplFileInfo $current) use ($skipMap, $pruneMap) {
                    if (isset($skipMap[$current->getPathname()])) return false;
                    // Skip the directory entirely — the iterator
                    // checks the filter BEFORE descending, so we
                    // never enumerate the contents of pruned dirs.
                    if ($current->isDir() && isset($pruneMap[$current->getFilename()])) {
                        return false;
                    }
                    return true;
                }
            )
        );

        $baseLen = strlen($base);
        foreach ($iterator as $info) {
            /** @var \SplFileInfo $info */
            if (! $info->isFile()) continue;
            $size = $info->getSize();
            if ($size === false || $size > self::FILE_SIZE_LIMIT) continue;
            $abs = $info->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($abs, $baseLen)), '/');
            $name = $logicalPrefix.'/'.$rel;
            $files[] = [
                'name'     => $name,
                'absolute' => $abs,
                'size'     => $size,
            ];
            $totalBytes += $size;
        }
    }

    /**
     * Read the NDJSON manifest written by the scan phase — one file
     * descriptor per line. Lines that don't decode are skipped rather
     * than aborting the whole bundle.
     *
     * @return list<array{name: string, absolute: string, size: int}>
     */
    private function loadManifest(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return [];

        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $row = json_decode($line, true);
            if (is_array($row)) $out[] = $row;
        }
        return $out;
    }

    private function listTablesSafely(): array
    {
        try {
            // The table list comes from the SOURCE introspection only;
            // the target dialect doesn't affect enumeration, so we can
            // skip wiring it through here.
            return (new DatabaseDumper($this->db))->listTables();
        } catch (Throwable) {
            return [];
        }
    }

    private function buildMeta(JobState $state): array
    {
        $contents = $state->get('contents');
        $target   = $state->get('target_dialect');
        $sourceDialect = '';
        try {
            $sourceDialect = Dialect::detect($this->db)->value;
        } catch (Throwable) { /* leave blank if unknown */ }

        return [
            'format_version' => 2, // 2 = adds source/target dialect tags
            'created_at'     => gmdate('c'),
            'flarum_version' => $this->detectFlarumVersion(),
            'php_version'    => PHP_VERSION,
            'contents'       => array_keys(array_filter($contents)),
            'source_dialect' => $sourceDialect,
            // The target the SQL was generated for. Equal to source on
            // a same-engine backup; differs on a cross-engine
            // migration (e.g. "I'm on MySQL, restoring to Postgres").
            'target_dialect' => $target ?: $sourceDialect,
            // Source URL goes in the archive header in plain text (not
            // a secret) so the import side can rewrite occurrences in
            // the database to the destination server's URL — without
            // it, a backup imported on a different host would break
            // every absolute link / redirect / cookie path.
            'source_url'     => $this->detectSourceUrl(),
            // Summary the import UI uses to populate selection
            // checkboxes (per-section file counts + list of extension
            // directory names found inside).
            'manifest'       => (array) $state->get('manifest_summary', []),
        ];
    }

    private function detectSourceUrl(): string
    {
        $url = $this->config['url'] ?? '';
        if (! is_string($url)) return '';
        return rtrim($url, '/');
    }

    /**
     * Normalise the user-provided extension selection to either `true`
     * (everything), `false` (nothing), or a list of extension ids.
     */
    private function normaliseExtensionSelection(mixed $raw): bool|array
    {
        if (is_bool($raw)) return $raw;
        if (is_array($raw)) {
            $ids = array_values(array_filter(array_map(
                fn ($v) => is_string($v) ? $v : null,
                $raw
            )));
            return $ids;
        }
        return (bool) $raw;
    }

    private function detectFlarumVersion(): string
    {
        $composer = $this->appPaths->base.DIRECTORY_SEPARATOR.'composer.lock';
        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);
            if (is_array($data) && isset($data['packages'])) {
                foreach ($data['packages'] as $pkg) {
                    if (($pkg['name'] ?? '') === 'flarum/core') {
                        return (string) ($pkg['version'] ?? 'unknown');
                    }
                }
            }
        }
        return 'unknown';
    }
}
