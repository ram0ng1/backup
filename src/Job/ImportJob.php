<?php

namespace Ramon\Backup\Job;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Ramon\Backup\Archive\ArchiveReader;
use Ramon\Backup\Archive\Format;
use Ramon\Backup\Crypto\BackupCipher;
use Ramon\Backup\Database\DatabaseRestorer;
use Ramon\Backup\Database\Dialect;
use Ramon\Backup\Environment\StackSnapshot;
use Ramon\Backup\Project\ProjectReconciler;
use Ramon\Backup\Settings\SettingsPreserver;
use Ramon\Backup\StoragePaths;
use RuntimeException;
use Throwable;

/**
 * Mirror of ExportJob, but reversed: pull entries from a `.flarum`
 * archive and apply them to the running install.
 *
 * Strategy is "replace" for o conteúdo do fórum e "reconcilia" para o
 * que descreve o servidor:
 *   - DB statements include `DROP TABLE IF EXISTS` per table — running
 *     them recreates the schema cleanly.
 *   - File entries overwrite anything currently at the destination
 *     path. We never touch paths outside the four bundleable roots
 *     (assets/, storage/, extensions/), so a malicious archive can't
 *     escape the install.
 *   - `project/*` (composer.json, composer.lock, extend.php da raiz) NÃO
 *     é gravado direto: vai para um staging dentro do job e passa pelo
 *     {@see ProjectReconciler}, que faz merge em vez de overwrite. Um
 *     manifesto de outro servidor sobrescrito é o que já derrubou um
 *     fórum inteiro — poda o pacote, o extend.php fica apontando para a
 *     classe podada, e o boot morre antes do handler de erro.
 *   - As chaves de `settings` que descrevem ESTE servidor (SMTP, fila,
 *     tokens) são fotografadas antes do restore e regravadas depois —
 *     ver {@see SettingsPreserver}.
 *
 * Phases:
 *   inspect   → openHeader + decrypt prep (one tick)
 *   extract   → pull each entry; SQL goes into dump.sql, files go to
 *               their destination directly, `project/*` vai para staging
 *   reconcile → merge do composer.json, extend.php opcional, snapshot
 *               das settings preservadas
 *   restore   → replay dump.sql through DatabaseRestorer
 *   rewrite   → troca a URL de origem pela do destino e regrava as
 *               settings preservadas
 *   verify    → procura classe referenciada pelo extend.php da raiz que
 *               o autoloader não resolve (o modo de falha silencioso)
 *   finalize  → cleanup
 */
class ImportJob
{
    public const BUDGET_BYTES = 4_194_304;

    /** Nomes de entradas não resolvidas guardados para diagnóstico. */
    public const UNRESOLVED_SAMPLE_LIMIT = 20;

    /** Subdiretório do job onde os `project/*` esperam a reconciliação. */
    public const PROJECT_STAGING_DIR = 'project';

    /**
     * Arquivo do snapshot de settings preservadas. Fica fora do job.json
     * porque carrega segredo do destino (senha de SMTP, token OAuth) e o
     * job.json é reescrito a cada tick. Mesma exposição do dump.sql, que
     * já vive neste diretório 0700 e é apagado no finalize.
     */
    public const PRESERVED_SETTINGS_FILE = 'preserved-settings.json';

    /**
     * Decryption private key for the current run. Held ONLY in memory
     * and NEVER written to the job-state file: persisting a user-pasted
     * private key in plaintext under storage/ would let any process that
     * can read that directory steal it (and with it every past encrypted
     * backup). The CLI keeps the same job instance across all ticks, so
     * setting it once in start() is enough; the web flow re-supplies it
     * on each tick request (over HTTPS) and TickImportController calls
     * withPrivateKey() before runTick().
     */
    private ?string $privateKey = null;

    /**
     * The live database connection. Declared as the concrete
     * {@see Connection} because the dump/restore path needs
     * `getDriverName()` (via {@see Dialect::detect}), which the broader
     * {@see ConnectionInterface} does not expose. The constructor takes
     * the interface so Flarum's container (which binds
     * `ConnectionInterface`, not the concrete class) can still inject it,
     * then narrows once at this boundary.
     */
    protected Connection $db;

    public function __construct(
        protected StoragePaths $paths,
        protected Paths $appPaths,
        ConnectionInterface $db,
        protected BackupCipher $cipher,
        protected Config $config,
        protected ProjectReconciler $project,
        protected SettingsPreserver $preserver
    ) {
        $this->db = $db;
    }

    /**
     * Provide (or refresh) the in-memory decryption key for the next
     * tick. A null/empty value clears it. Returns $this for chaining.
     */
    public function withPrivateKey(?string $privateKey): self
    {
        $this->privateKey = ($privateKey !== null && $privateKey !== '') ? $privateKey : null;
        return $this;
    }

    /**
     * Begin an import. The archive must already be staged in the job
     * dir as `upload.flarum` (the upload controller does that).
     */
    /**
     * @param array{db?: bool, assets?: bool, storage?: bool, extensions?: bool|list<string>, root_extend?: bool, preserve_settings?: bool}|null $selection
     *        null = restore everything in the archive
     *        extensions: true = all, false = none, array = specific extension dirs
     *        root_extend: sobrescrever o extend.php da raiz (opt-in)
     *        preserve_settings: manter as settings de infraestrutura deste
     *                           servidor por cima do restore (opt-out)
     */
    public function start(string $jobId, ?string $privateKey, bool $confirmReplace, ?array $selection, ?int $userId): JobState
    {
        if (! $confirmReplace) {
            throw new RuntimeException('Import requires explicit replace-confirmation.');
        }

        // Hold the decryption key in memory only — see the $privateKey
        // property docblock. The CLI reuses this instance for every
        // tick; the web flow re-sends the key per tick.
        $this->withPrivateKey($privateKey);

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
                // NB: the decryption private key is deliberately NOT
                // stored here — it lives only in memory ($this->privateKey).
                'confirm_replace'  => true,
                'selection'        => $this->normaliseSelection($selection),
            ],
            'archive_meta'  => null,
            'warnings'      => [],
            // Amostra limitada dos nomes que não resolveram destino,
            // para o operador saber O QUE ficou fora sem carregar o
            // state com dezenas de milhares de caminhos.
            'unresolved_sample' => [],
            'unwritable_sample' => [],
            'progress' => [
                'total_bytes'        => filesize($upload) ?: 0,
                'processed_bytes'    => 0,
                'extracted_entries'  => 0,
                // Entradas que o arquivo trazia e o operador desmarcou.
                'skipped_entries'    => 0,
                // Entradas aceitas na seleção cujo destino não pôde ser
                // resolvido — restauração INCOMPLETA. Separado de
                // `skipped_entries` porque um valor > 0 aqui é defeito,
                // não escolha.
                'unresolved_entries' => 0,
                // Entradas cujo destino resolveu mas o disco recusou a
                // escrita (permissão, read-only, disco cheio). Também é
                // defeito, não escolha — só que do ambiente, não do
                // arquivo.
                'unwritable_entries' => 0,
                'restored_statements' => 0,
                'percent'            => 0.0,
            ],
            'cursor' => [
                'bytes_read'        => 0,
                'restore_offset'    => 0,
                'current_entry'     => null,
                'current_offset'    => 0,
                'extract_done'      => false,
                // Persisted reader position (file offset + secretstream
                // pull state) so each extract tick resumes in place
                // instead of re-decrypting earlier entries. Null until
                // the first extract tick records it.
                'reader'            => null,
            ],
        ];

        return JobState::create($dir.DIRECTORY_SEPARATOR.'job.json', $initial);
    }

    public function runTick(JobState $state): JobState
    {
        $phase = $state->get('phase');

        try {
            match ($phase) {
                'inspect'   => $this->runInspect($state),
                'extract'   => $this->runExtract($state),
                'reconcile' => $this->runReconcile($state),
                'restore'   => $this->runRestore($state),
                'rewrite'   => $this->runRewrite($state),
                'verify'    => $this->runVerify($state),
                'finalize'  => $this->runFinalize($state),
                'done'      => null,
                'error'     => null,
                default     => throw new RuntimeException("Unknown phase: $phase"),
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
                $reader->prepareEncrypted($this->cipher, $this->privateKey);
            }
            $meta = $reader->meta();
            $state->set('archive_meta', $meta);

            // Stack guard — FIRST check, before the engine guard and
            // before a single byte is written. A backup taken on a
            // newer PHP cannot be replayed onto an older one: the
            // restored vendor/ tree and composer.lock were resolved
            // for the source's version. Unconditional (not gated on
            // the file selection) because a DB-only restore also
            // rewrites `extensions_enabled`, re-enabling extensions
            // this PHP cannot parse.
            $blocking = StackSnapshot::blockingReason($meta);
            if ($blocking !== null) {
                throw new RuntimeException($blocking);
            }

            $advisories = StackSnapshot::advisories($meta);
            if ($advisories !== []) {
                $state->set('warnings', array_values(array_unique(array_merge(
                    (array) $state->get('warnings', []),
                    $advisories
                ))));
            }

            // Cross-engine guard: archives from format_version >= 2
            // record which engine the SQL was generated for. If that
            // disagrees with the live destination engine, abort early
            // with a clear error rather than letting the restore fail
            // mid-stream with a cryptic SQL syntax message.
            if (! empty($opts['db'] ?? true)) {
                $target = (string) ($meta['target_dialect'] ?? '');
                if ($target !== '') {
                    try {
                        $here = Dialect::detect($this->db)->value;
                        $compatible = $target === $here
                            || ($target === Dialect::MYSQL->value && $here === Dialect::MARIADB->value)
                            || ($target === Dialect::MARIADB->value && $here === Dialect::MYSQL->value);
                        if (! $compatible) {
                            throw new RuntimeException(
                                "This backup targets `$target`, but the destination is `$here`. "
                                . "Re-export selecting `$here` as the target engine, or restore onto a `$target` install."
                            );
                        }
                    } catch (RuntimeException $e) {
                        throw $e;
                    } catch (Throwable) { /* dialect detection failed — let the restore try */ }
                }
            }
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
     * Resume strategy: each tick stops on an entry boundary and persists
     * the reader's exact position — file offset, the libsodium
     * secretstream pull state, and any leftover decrypted bytes (see
     * ArchiveReader::serializeState). The next tick reopens the archive
     * and `resumeState()`s straight to that point, so earlier entries are
     * read (and, for encrypted archives, DECRYPTED) exactly once across
     * the whole import — not re-decrypted from byte 0 on every tick. That
     * keeps a large encrypted restore linear instead of O(n²).
     */
    private function runExtract(JobState $state): void
    {
        $paths    = $state->get('paths');
        $opts     = $state->get('options');
        $progress = $state->get('progress');
        $cursor   = $state->get('cursor');

        if (($cursor['reader'] ?? null) === null) {
            $this->preflightWritability($state);
        }

        $reader = ArchiveReader::openHeader($paths['archive']);
        try {
            // A persisted reader snapshot means this is a resume tick:
            // jump straight to where we stopped. Its absence means this
            // is the first extract tick, so prime decryption from the
            // archive header instead.
            $snap = $cursor['reader'] ?? null;
            if ($snap !== null) {
                $reader->resumeState([
                    'fpos'             => $snap['fpos'] ?? null,
                    'entry_stream_pos' => $snap['entry_stream_pos'] ?? 0,
                    'stream_exhausted' => $snap['stream_exhausted'] ?? false,
                    'stream_state'     => $state->getBinary('reader_stream_state'),
                    'plain_buffer'     => $state->getBinary('reader_plain_buffer'),
                ]);
            } elseif ($reader->isEncrypted()) {
                $reader->prepareEncrypted($this->cipher, $this->privateKey);
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

                    // Aceita na seleção mas sem destino resolvível (id
                    // de extensão fora do mapa do manifesto, raiz não
                    // permitida). Os bytes são drenados igual, mas isso
                    // é perda de dado — nunca pode passar por "extraído".
                    $unresolved = $accept
                        && $entry['type'] === Format::TYPE_FILE
                        && $dest === null;

                    // Open a sink: SQL dump file, target file on disk,
                    // or null (drain into the void). We always consume
                    // the bytes — skipping just means not writing them.
                    $sinkFh = null;
                    $writeError = null;
                    if ($accept && $entry['type'] === Format::TYPE_DB_DUMP) {
                        $sinkFh = $dumpFh;
                    } elseif ($accept && $dest !== null) {
                        $sinkFh = $this->openDestination($dest, $writeError);
                    }

                    try {
                        $remaining = $entry['length'];
                        while ($remaining > 0) {
                            $want = (int) min(Format::CHUNK_SIZE, $remaining);
                            $chunk = $reader->readEntryBytes($want);
                            if ($sinkFh !== null && $sinkFh !== $dumpFh) {
                                $written = @fwrite($sinkFh, $chunk);
                                if ($written === false || $written < strlen($chunk)) {
                                    /*
                                     * Escrita curta em arquivo local é
                                     * praticamente sempre ENOSPC. Fecha o
                                     * destino e segue drenando: o resto do
                                     * arquivo ainda precisa ser lido para o
                                     * leitor parar numa fronteira de entrada.
                                     */
                                    $writeError = $this->lastErrorMessage() ?? 'short write (disco cheio?)';
                                    fclose($sinkFh);
                                    $sinkFh = null;
                                }
                            } elseif ($sinkFh === $dumpFh) {
                                $written = @fwrite($dumpFh, $chunk);
                                if ($written === false || $written < strlen($chunk)) {
                                    throw new RuntimeException(
                                        'Falha ao gravar dump.sql em '.$paths['dump']
                                        .' — '.($this->lastErrorMessage() ?? 'escrita curta (disco cheio?)')
                                    );
                                }
                            }
                            $remaining -= strlen($chunk);
                            $progress['processed_bytes'] += strlen($chunk);
                        }
                    } finally {
                        if ($sinkFh !== null && $sinkFh !== $dumpFh) {
                            fclose($sinkFh);
                        }
                    }

                    if ($unresolved) {
                        $progress['unresolved_entries']++;
                        $this->noteUnresolved($state, (string) $entry['name']);
                    } elseif ($writeError !== null) {
                        $progress['unwritable_entries']++;
                        $this->noteUnwritable($state, (string) $dest, $writeError);
                    } elseif ($accept) {
                        $progress['extracted_entries']++;
                    } else {
                        $progress['skipped_entries']++;
                    }
                    $budget -= $entry['length'];
                }
            } finally {
                fclose($dumpFh);
            }

            // Persist the reader's exact stop position (file offset +
            // secretstream pull state + any leftover decrypted bytes) so
            // the next tick resumes here. This is what keeps the whole
            // extract O(n) instead of re-decrypting earlier entries on
            // every tick.
            $snap = $reader->serializeState();
            $cursor['reader'] = [
                'fpos'             => $snap['fpos'],
                'entry_stream_pos' => $snap['entry_stream_pos'],
                'stream_exhausted' => $snap['stream_exhausted'],
            ];
            $state->setBinary('reader_stream_state', (string) ($snap['stream_state'] ?? ''));
            $state->setBinary('reader_plain_buffer', (string) $snap['plain_buffer']);
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
                ? 'Reconciling project files…'
                : 'Extracting… '.$progress['extracted_entries'].' entries'
        );

        if ($cursor['extract_done']) {
            /*
             * A reconciliação roda SEMPRE, inclusive num restore só de
             * banco: é ela que compara os manifestos e avisa o que falta.
             */
            $state->set('phase', 'reconcile');
        }

        $state->save();
    }

    /**
     * Phase 3 — reconcilia o que descreve o servidor, antes de o banco
     * ser tocado.
     *
     * Duas responsabilidades, ambas com a mesma justificativa: o backup
     * descreve o fórum de ORIGEM, e há estado que pertence ao destino.
     * Aqui o composer.json é unido (nunca sobrescrito), o extend.php da
     * raiz só é aplicado sob opt-in, e as settings de infraestrutura do
     * destino são fotografadas enquanto a tabela ainda é a dele.
     */
    private function runReconcile(JobState $state): void
    {
        $paths     = $state->get('paths');
        $opts      = (array) $state->get('options');
        $selection = $opts['selection'] ?? null;

        $staging = $this->projectStagingDir($state);

        $applyComposer   = $selection === null || $this->hasAnyExtensionSelected($selection);
        $applyRootExtend = $selection !== null && ! empty($selection['root_extend']);

        try {
            $result = $this->project->reconcile($staging, $applyComposer, $applyRootExtend);
            $this->addWarnings($state, $result['warnings']);
            $state->set('project_applied', array_values($result['applied']));
        } catch (Throwable $e) {
            $this->addWarnings($state, [
                'Falha ao reconciliar os arquivos da raiz do projeto: '.$e->getMessage()
                .'. O composer.json e o extend.php deste servidor ficaram como estavam.',
            ]);
        }

        $this->snapshotPreservedSettings($state, $selection);

        $hasDump = is_file($paths['dump']) && filesize($paths['dump']) > 0;
        $state->set('phase', $hasDump ? 'restore' : 'rewrite');
        $state->set('message', $hasDump ? 'Restoring database…' : 'Rewriting URLs…');
        $state->save();
    }

    /**
     * Fotografa as settings preservadas num arquivo próprio do job. Roda
     * antes do DROP/CREATE; sem dump de banco não há nada a preservar,
     * porque a tabela não vai ser tocada.
     *
     * @param array<string, mixed>|null $selection
     */
    private function snapshotPreservedSettings(JobState $state, ?array $selection): void
    {
        $wantsDb = $selection === null || ! empty($selection['db']);
        $wantsPreserve = $selection === null || ! empty($selection['preserve_settings']);

        if (! $wantsDb || ! $wantsPreserve) {
            return;
        }

        try {
            $snapshot = $this->preserver->snapshot();
        } catch (Throwable) {
            return;
        }

        if ($snapshot === []) {
            return;
        }

        $encoded = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }

        $file = $this->preservedSettingsFile($state);
        if (@file_put_contents($file, $encoded, LOCK_EX) === false) {
            return;
        }
        @chmod($file, 0600);

        $state->set('preserved_settings_count', count($snapshot));
    }

    /**
     * Regrava o snapshot depois do restore E depois do rewrite de URL,
     * para ter a última palavra sobre qualquer chave que os dois toquem.
     */
    private function reapplyPreservedSettings(JobState $state): void
    {
        $file = $this->preservedSettingsFile($state);
        if (! is_file($file)) {
            return;
        }

        $raw = @file_get_contents($file); /* arquivo do próprio job, 0600; nosemgrep: flarum-v2-server-side-fetch */
        @unlink($file);

        if ($raw === false) {
            return;
        }
        $snapshot = json_decode($raw, true);
        if (! is_array($snapshot) || $snapshot === []) {
            return;
        }

        try {
            $restored = $this->preserver->reapply($snapshot);
        } catch (Throwable $e) {
            $this->addWarnings($state, [
                'Não foi possível regravar a configuração deste servidor após o restore: '.$e->getMessage()
                .'. Reveja SMTP, fila e integrações no painel.',
            ]);
            return;
        }

        if ($restored === []) {
            return;
        }

        $state->set('preserved_settings_restored', count($restored));
        $this->addWarnings($state, [sprintf(
            '%d configuração(ões) deste servidor foram preservadas do backup (SMTP, fila, integrações). '
            .'Ajuste a lista em `%s` se algo específico do seu ambiente ainda estiver sendo sobrescrito.',
            count($restored),
            SettingsPreserver::EXTRA_KEY
        )]);
    }

    /** Caminho do staging dos `project/*`, sem criar nada. */
    private function projectStagingPath(JobState $state): string
    {
        $paths = (array) $state->get('paths');
        return rtrim((string) ($paths['dir'] ?? ''), '/\\')
            .DIRECTORY_SEPARATOR.self::PROJECT_STAGING_DIR;
    }

    /**
     * Mesmo caminho, garantindo o diretório. 0700 pelo mesmo motivo do
     * resto do job: o staging guarda o composer.json e o extend.php da
     * origem enquanto esperam a reconciliação.
     */
    private function projectStagingDir(JobState $state): string
    {
        $dir = $this->projectStagingPath($state);
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    private function preservedSettingsFile(JobState $state): string
    {
        $paths = (array) $state->get('paths');
        return rtrim((string) ($paths['dir'] ?? ''), '/\\')
            .DIRECTORY_SEPARATOR.self::PRESERVED_SETTINGS_FILE;
    }

    /**
     * Acrescenta avisos sem duplicar. O finalize também mexe nessa
     * lista, então a deduplicação tem que valer para os dois.
     *
     * @param list<string> $new
     */
    private function addWarnings(JobState $state, array $new): void
    {
        if ($new === []) {
            return;
        }
        $state->set('warnings', array_values(array_unique(array_merge(
            array_map(fn ($w) => (string) $w, (array) $state->get('warnings', [])),
            array_map(fn ($w) => (string) $w, $new)
        ))));
    }

    /**
     * Phase 4 — replay dump.sql through DatabaseRestorer in chunks. We
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

        // Each tick opens a fresh PDO connection, so any session-level
        // FK toggle from the previous tick is gone. We also can't rely
        // on the dump's own preamble — even though it's emitted as a
        // separate statement, an FK violation mid-batch would still
        // abort. Disable explicitly at the start of every tick that
        // runs SQL; re-enable when we cross EOF below. The helper
        // picks the right syntax for the destination engine.
        DatabaseRestorer::disableForeignKeys($this->db);

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
            DatabaseRestorer::enableForeignKeys($this->db);

            // Belt-and-braces sequence resync on Postgres. Our dump
            // already emits `setval(...)` in its epilogue, but: (a)
            // archives generated before that code shipped don't carry
            // those statements; (b) a managed-host quirk could
            // silently swallow the call; (c) an admin who manually
            // ran `psql -f dump.sql` wouldn't have triggered our
            // emitter at all. Running it again on the live database
            // is harmless — `setval(seq, MAX(id)+1, false)` always
            // converges to the right next value — so this protects
            // every path that ends with "PG database newly populated".
            $this->resyncPostgresSequences($state);

            $state->set('phase', 'rewrite');
        }

        $state->save();
    }

    /**
     * After a bulk restore, bump every Postgres sequence past its
     * column's `MAX(id)`. Without this the next `INSERT … RETURNING
     * id` reuses a value already present and the user sees a
     * `discussions_pkey` (or similar) unique-constraint violation as
     * soon as they post their first new discussion.
     *
     * Sweeps every column in the current schema that has a backing
     * sequence (covers both `SERIAL` and PG 10+ `IDENTITY`). Each
     * `setval` is its own statement so one bad table doesn't abort
     * the rest.
     */
    private function resyncPostgresSequences(JobState $state): void
    {
        try {
            if (Dialect::detect($this->db) !== Dialect::POSTGRES) return;
        } catch (Throwable) {
            return;
        }

        // pg_get_serial_sequence returns the sequence name for both
        // legacy SERIAL columns and PG 10+ IDENTITY columns. Filtering
        // by `sequence IS NOT NULL` cheaply picks out exactly the
        // columns we need to fix.
        try {
            $rows = $this->db->select(
                "SELECT n.nspname AS schema_name, c.relname AS table_name,
                        a.attname AS column_name,
                        pg_get_serial_sequence(
                            quote_ident(n.nspname)||'.'||quote_ident(c.relname),
                            a.attname
                        ) AS seq
                 FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 JOIN pg_attribute a ON a.attrelid = c.oid
                 WHERE c.relkind = 'r'
                   AND n.nspname = current_schema()
                   AND a.attnum > 0
                   AND NOT a.attisdropped"
            );
        } catch (Throwable) {
            return;
        }

        $bumped = 0;
        foreach ($rows as $r) {
            $arr = (array) $r;
            $seq = $arr['seq'] ?? null;
            if (! is_string($seq) || $seq === '') continue;

            $tbl = (string) ($arr['table_name'] ?? '');
            $col = (string) ($arr['column_name'] ?? '');
            if ($tbl === '' || $col === '') continue;

            $tblQ = '"' . str_replace('"', '""', $tbl) . '"';
            $colQ = '"' . str_replace('"', '""', $col) . '"';

            try {
                $this->db->select(
                    "SELECT setval(?, COALESCE((SELECT MAX($colQ) FROM $tblQ), 0) + 1, false)",
                    [$seq]
                );
                $bumped++;
            } catch (Throwable) {
                // Skip individual failures — keep going so one weird
                // table (e.g. permission missing on a system view we
                // shouldn't have picked up) doesn't break the rest.
            }
        }

        if ($bumped > 0) {
            $progress = (array) $state->get('progress');
            $progress['sequences_resynced'] = $bumped;
            $state->set('progress', $progress);
        }
    }

    /**
     * Phase 5 — URL rewrite. Backups taken from one Flarum and
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

            // Identifier quoting depends on the destination engine —
            // backticks for MySQL/MariaDB, double quotes for PG/SQLite.
            // String escaping uses standard SQL doubled-single-quote on
            // every supported engine, so the parametrised path works
            // unchanged.
            $q = function (string $ident): string {
                return Dialect::detect($this->db)->usesBackticks()
                    ? '`' . str_replace('`', '``', $ident) . '`'
                    : '"' . str_replace('"', '""', $ident) . '"';
            };
            $settings = $q('settings');
            $value    = $q('value');
            $posts    = $q('posts');
            $content  = $q('content');
            $parsed   = $q('parsed_content');

            // Settings — `forum.url` and any extension setting that
            // happens to embed the old URL. Run as REPLACE() so a
            // single value can rewrite multiple substrings. REPLACE()
            // is portable across all four engines (MySQL, MariaDB,
            // PostgreSQL, SQLite all expose it identically).
            try {
                $stats['settings'] = $this->db->update(
                    "UPDATE $settings SET $value = REPLACE($value, ?, ?) WHERE $value LIKE ?",
                    [$sourceUrl, $destUrl, '%'.$sourceUrl.'%']
                );
            } catch (Throwable) { /* ignore — table missing or schema differs */ }

            // Post content — Flarum 2 stores XML-tagged formatted text
            // here. Absolute URLs inside that XML are usually only the
            // forum's own canonical URL, so a textual replace is safe
            // for the vast majority of forums.
            try {
                $stats['posts_content'] = $this->db->update(
                    "UPDATE $posts SET $content = REPLACE($content, ?, ?) WHERE $content LIKE ?",
                    [$sourceUrl, $destUrl, '%'.$sourceUrl.'%']
                );
            } catch (Throwable) { /* ignore */ }

            // Post parsed content — exists on Flarum 1.x, may be
            // absent on Flarum 2+. Try both column variants.
            try {
                $stats['posts_parsed'] = $this->db->update(
                    "UPDATE $posts SET $parsed = REPLACE($parsed, ?, ?) WHERE $parsed LIKE ?",
                    [$sourceUrl, $destUrl, '%'.$sourceUrl.'%']
                );
            } catch (Throwable) { /* ignore */ }

            $state->set('rewrite_stats', $stats);
            $state->set('message', 'Rewrote URL: '.$sourceUrl.' → '.$destUrl);
        } else {
            $state->set('message', 'Skipping URL rewrite (URLs match or unknown).');
        }

        /*
         * Depois do REPLACE de URL de propósito: a configuração deste
         * servidor tem que ser a última a escrever nas chaves que as duas
         * etapas encostam.
         */
        $this->reapplyPreservedSettings($state);

        $state->set('phase', 'verify');
        $state->save();
    }

    /**
     * Phase 6 — checagem de sanidade do boot.
     *
     * O core dá `require` no `extend.php` da raiz dentro de
     * `Site::fromPaths()`, antes de existir qualquer handler de erro. Uma
     * classe ausente ali não vira 500 com stack trace: vira exit 255 com
     * saída vazia e log do Flarum vazio. Essa fase troca esse silêncio
     * por um aviso nomeando a classe.
     *
     * Limite conhecido: a checagem usa o autoloader deste processo, então
     * enxerga o vendor/ como está agora. Um `composer install` posterior
     * ainda pode podar pacote — o que o merge do composer.json (ver
     * ProjectReconciler) existe para evitar.
     */
    private function runVerify(JobState $state): void
    {
        try {
            $dangling = $this->project->danglingClassReferences();
        } catch (Throwable) {
            $dangling = [];
        }

        if ($dangling !== []) {
            $state->set('boot_dangling_classes', array_values($dangling));
            $this->addWarnings($state, [sprintf(
                'BOOT EM RISCO: o extend.php da raiz referencia %s que o autoloader não resolve. '
                .'O Flarum carrega esse arquivo antes do handler de erro, então isso derruba o fórum '
                .'com HTTP 500 sem mensagem. Reinstale o pacote que fornece a classe ou comente o '
                .'extender antes de recarregar.',
                count($dangling) === 1
                    ? 'a classe `'.$dangling[0].'`'
                    : 'as classes `'.implode('`, `', array_slice($dangling, 0, 5)).'`'
            )]);
        }

        $state->set('phase', 'finalize');
        $state->save();
    }

    /**
     * Registra, no máximo LIMITE vezes, o nome de uma entrada aceita
     * cujo destino não resolveu. A amostra é limitada de propósito: o
     * contador em `progress.unresolved_entries` é que diz o tamanho do
     * estrago, a lista só serve para identificar o padrão.
     */
    private function noteUnresolved(JobState $state, string $name): void
    {
        $sample = (array) $state->get('unresolved_sample', []);
        if (count($sample) >= self::UNRESOLVED_SAMPLE_LIMIT) {
            return;
        }

        $sample[] = $name;
        $state->set('unresolved_sample', array_values($sample));
    }

    /**
     * Mesma amostragem limitada do `noteUnresolved`, para os destinos que
     * resolveram mas o disco recusou. Guarda o motivo do SO junto do
     * caminho: "Permission denied" e "No space left on device" pedem ações
     * opostas do operador, e sem o motivo os dois chegavam como a mesma
     * mensagem opaca.
     */
    private function noteUnwritable(JobState $state, string $dest, string $reason): void
    {
        $sample = (array) $state->get('unwritable_sample', []);
        if (count($sample) >= self::UNRESOLVED_SAMPLE_LIMIT) {
            return;
        }

        $sample[] = $dest.' — '.$reason;
        $state->set('unwritable_sample', array_values($sample));
    }

    /**
     * Abre o destino para escrita, criando o diretório pai quando falta.
     * Devolve null — com o motivo do SO em `$reason` — em vez de lançar:
     * um `vendor/` sem permissão de escrita não pode abortar a restauração
     * inteira e levar junto o banco, os assets e o storage, que quase sempre
     * são graváveis. A entrada perdida é contada e vira aviso no fim.
     *
     * @param  string|null  $reason  Preenchido com o erro do SO quando falha.
     * @return resource|null
     */
    private function openDestination(string $dest, ?string &$reason)
    {
        $dir = dirname($dest);
        if (! is_dir($dir)) {
            error_clear_last();
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                $reason = $this->lastErrorMessage() ?? 'não foi possível criar '.$dir;
                return null;
            }
        }

        error_clear_last();
        $fh = @fopen($dest, 'wb');
        if ($fh === false) {
            $reason = $this->lastErrorMessage() ?? 'fopen recusou o destino';
            return null;
        }

        return $fh;
    }

    /**
     * Mensagem do último erro do PHP, já sem o prefixo da função que o
     * gerou (`fopen(/x/y): Failed to open stream: ` → `Permission denied`).
     */
    private function lastErrorMessage(): ?string
    {
        $msg = error_get_last()['message'] ?? null;
        if (! is_string($msg) || $msg === '') {
            return null;
        }

        $msg = trim(preg_replace('/^\S+\([^)]*\):\s*/', '', $msg) ?? $msg);
        $msg = trim(preg_replace('/^Failed to open stream:\s*/i', '', $msg) ?? $msg);

        return $msg !== '' ? $msg : null;
    }

    /**
     * Confere, antes do primeiro byte gravado, se as raízes que esta
     * seleção pretende escrever aceitam escrita pelo usuário do PHP. Não
     * aborta: emite aviso nomeando o caminho e o usuário do processo, que
     * é a informação que faltava quando o restore morria no meio com o
     * caminho de um `.gitignore` qualquer.
     */
    private function preflightWritability(JobState $state): void
    {
        $opts      = (array) $state->get('options');
        $selection = $opts['selection'] ?? null;
        $targets   = [];

        if ($selection === null || ! empty($selection['assets'])) {
            $targets[] = rtrim($this->appPaths->public, '/\\').DIRECTORY_SEPARATOR.'assets';
        }
        if ($selection === null || ! empty($selection['storage'])) {
            $targets[] = rtrim($this->appPaths->storage, '/\\');
        }
        if ($selection === null || $this->hasAnyExtensionSelected((array) $selection)) {
            foreach ($this->extensionDestinationMap($state) as $extId => $path) {
                if ($selection !== null
                    && ! $this->isExtensionAllowed('extensions/'.$extId.'/x', $selection['extensions'] ?? null)) {
                    continue;
                }
                $targets[] = $path;
            }
        }

        $blocked = [];
        foreach (array_unique($targets) as $target) {
            $anchor = $this->nearestExistingAncestor($target);
            if ($anchor !== null && ! is_writable($anchor)) {
                $blocked[$anchor] = true;
            }
        }

        if ($blocked === []) {
            return;
        }

        $paths = array_slice(array_keys($blocked), 0, 5);
        $this->addWarning($state, sprintf(
            'Sem permissão de escrita em %s (o PHP roda como `%s`). As entradas '
            .'destinadas a esses caminhos NÃO serão gravadas — o banco, os assets e o '
            .'storage continuam. Ajuste o dono/permissão desses diretórios e restaure '
            .'de novo, ou desmarque Extensões e instale os pacotes pelo composer.',
            implode(', ', $paths),
            $this->processUser()
        ));
    }

    /**
     * Sobe do caminho até o primeiro diretório que já existe. É nele que a
     * permissão importa: o destino final costuma ser justamente o que ainda
     * precisa ser criado.
     */
    private function nearestExistingAncestor(string $path): ?string
    {
        $current = rtrim($path, '/\\');
        for ($i = 0; $i < 64; $i++) {
            if ($current === '') {
                return null;
            }
            if (is_dir($current)) {
                return $current;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }
            $current = $parent;
        }

        return null;
    }

    /**
     * Nome do usuário do processo PHP, para o aviso de permissão apontar o
     * dono que o diretório precisa aceitar.
     */
    private function processUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && $info['name'] !== '') {
                return $info['name'];
            }
        }

        $user = get_current_user();

        return $user !== '' ? $user : 'desconhecido';
    }

    /**
     * Acrescenta um aviso ao state sem duplicar o que já está lá.
     */
    private function addWarning(JobState $state, string $message): void
    {
        $warnings = (array) $state->get('warnings', []);
        $warnings[] = $message;
        $state->set('warnings', array_values(array_unique(array_map(
            fn ($w) => (string) $w,
            $warnings
        ))));
        $state->save();
    }

    /**
     * Fecha o job. Uma restauração que perdeu entradas termina em
     * `done` — os dados já estão no disco, abortar aqui não desfaz
     * nada — mas NUNCA com a mensagem de sucesso liso: o contador de
     * não-resolvidas vira aviso explícito, que é justamente o que
     * faltava quando um import incompleto se apresentava como pronto.
     */
    private function runFinalize(JobState $state): void
    {
        $paths = $state->get('paths');
        @unlink($paths['dump']);
        @unlink($paths['archive']);
        @unlink($this->preservedSettingsFile($state));
        $this->paths->deleteDir($this->projectStagingPath($state));

        $progress   = (array) $state->get('progress');
        $unresolved = (int) ($progress['unresolved_entries'] ?? 0);
        $unwritable = (int) ($progress['unwritable_entries'] ?? 0);
        $dangling   = (array) $state->get('boot_dangling_classes', []);
        $warnings   = (array) $state->get('warnings', []);

        if ($unresolved > 0) {
            $sample = array_map(
                fn ($n) => (string) $n,
                array_slice(array_values((array) $state->get('unresolved_sample', [])), 0, 5)
            );
            $warnings[] = sprintf(
                'Restauração INCOMPLETA: %d entrada(s) selecionada(s) não pôde(ram) ser '
                .'gravada(s) porque o destino não resolveu%s. Confira a integridade do '
                .'arquivo e o manifesto de extensões antes de considerar esta migração '
                .'concluída.',
                $unresolved,
                $sample === [] ? '' : ' — por exemplo: '.implode(', ', $sample)
            );
        }

        if ($unwritable > 0) {
            $sample = array_map(
                fn ($n) => (string) $n,
                array_slice(array_values((array) $state->get('unwritable_sample', [])), 0, 5)
            );
            $warnings[] = sprintf(
                'Restauração INCOMPLETA: %d arquivo(s) não pôde(ram) ser gravado(s) pelo '
                .'usuário `%s` do PHP%s. Corrija dono/permissão (ou espaço em disco) desses '
                .'caminhos e restaure de novo — o banco de dados já foi aplicado, então '
                .'basta repetir o restore com apenas Extensões marcado.',
                $unwritable,
                $this->processUser(),
                $sample === [] ? '' : ' — por exemplo: '.implode('; ', $sample)
            );
        }

        $state->set('warnings', array_values(array_unique(array_map(
            fn ($w) => (string) $w,
            $warnings
        ))));

        /*
         * Referência pendurada no extend.php da raiz conta como restore
         * incompleto tanto quanto uma entrada não gravada: nos dois casos
         * o operador precisa agir antes de recarregar, e nos dois a tela
         * de sucesso limpo seria mentira.
         */
        $state->set('incomplete', $unresolved > 0 || $unwritable > 0 || $dangling !== []);
        $state->set('phase', 'done');
        $state->set('message', match (true) {
            $dangling !== [] => 'Restore finished — the root extend.php references classes that are not installed.',
            $unwritable > 0  => sprintf('Restore finished — %d file(s) could not be written to disk.', $unwritable),
            $unresolved > 0  => sprintf('Restore finished with %d unrestored entr%s.', $unresolved, $unresolved === 1 ? 'y' : 'ies'),
            default          => 'Restore complete.',
        });
        $state->save();
    }

    /**
     * Normalise the user's selection into a stable shape:
     *   ['db' => bool, 'assets' => bool, 'storage' => bool,
     *    'extensions' => true | false | list<string>,
     *    'root_extend' => bool, 'preserve_settings' => bool]
     *
     * `null` short-circuits to restore-everything for backwards
     * compatibility with older import requests.
     *
     * Os dois campos novos têm defaults assimétricos de propósito:
     * `root_extend` é opt-in porque sobrescrever o extend.php da raiz
     * troca configuração viva do destino; `preserve_settings` é opt-out
     * porque perder a configuração de infraestrutura do destino é o
     * defeito que essa camada existe para impedir.
     *
     * @param array<string, mixed>|null $raw
     * @return array{db: bool, assets: bool, storage: bool, extensions: bool|list<string>, root_extend: bool, preserve_settings: bool}|null
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
            'db'                => ! empty($raw['db']),
            'assets'            => ! empty($raw['assets']),
            'storage'           => ! empty($raw['storage']),
            'extensions'        => $extensions,
            'root_extend'       => ! empty($raw['root_extend']),
            'preserve_settings' => ! array_key_exists('preserve_settings', $raw) || ! empty($raw['preserve_settings']),
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
            /*
             * `project/*` é sempre extraído, mesmo com tudo desmarcado:
             * ele vai para um staging, não para o disco vivo, e é o que
             * permite ao reconciliador comparar o manifesto do backup com
             * o deste servidor e avisar quais pacotes faltam. Quem decide
             * o que efetivamente sobrescreve algo é o runReconcile.
             */
            'project' => true,
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
            // `relative` is read from the archive header, which is fully
            // attacker-controlled (the archive "travelled across servers").
            // Accept ONLY the two legitimate layouts — workbench/<dir> or
            // vendor/<vendor>/<package> — with each segment strictly
            // allow-listed and forbidden from starting with a dot (blocks
            // `.`/`..`) or containing a backslash. Anything else is dropped
            // rather than trusted, so a crafted manifest can't point an
            // extension's files at an arbitrary directory (§13.7).
            if (! preg_match(
                '#\A(workbench/[A-Za-z0-9_-][A-Za-z0-9._-]*'
                . '|vendor/[A-Za-z0-9_-][A-Za-z0-9._-]*/[A-Za-z0-9_-][A-Za-z0-9._-]*)\z#',
                $rel
            )) {
                continue;
            }
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
        if (str_contains($name, '..') || str_contains($name, "\0") || str_contains($name, '\\')) { /* rejeita (não remove) e o destino ainda passa pela contenção com realpath abaixo; nosemgrep: flarum-v2-path-traversal-naive-filter */
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
            /*
             * Arquivos da raiz do projeto. A allow-list continua fechada
             * — nada além dos três nomes conhecidos entra, para um
             * arquivo hostil não conseguir largar um `.htaccess` ou um
             * override de public/ na instalação.
             *
             * A diferença para as outras raízes: o destino aqui é um
             * staging DENTRO do diretório do job, nunca a raiz viva. O
             * que chega ao disco real é decidido depois pelo
             * ProjectReconciler, que faz merge em vez de overwrite.
             */
            if (! in_array($rest, ProjectReconciler::ALLOWED, true)) {
                return null;
            }
            $base = $this->projectStagingDir($state);
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

        $candidate = $base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $inner);

        // Final confinement. The string blacklist at the top stops
        // literal `../` traversal, but only canonicalising the resolved
        // parent and re-checking the prefix catches an escape through a
        // SYMLINKED path component (e.g. a symlinked `storage/` subdir on
        // shared hosting). This mirrors the guard StoragePaths uses for
        // downloads (§13.4/§13.5/§13.7); without it a write could follow
        // a link outside the whitelisted root.
        @mkdir(dirname($candidate), 0755, true);
        if (! $this->isWithin($base, dirname($candidate))) {
            return null;
        }

        return $candidate;
    }

    /**
     * True when `$child` resolves to a path inside `$base`. Both sides go
     * through realpath so symlinks are followed before comparison, and
     * the compare is case-insensitive so Windows' drive-letter / casing
     * differences don't produce a false negative (same approach as
     * StoragePaths::backupFilePath).
     */
    private function isWithin(string $base, string $child): bool
    {
        $baseReal  = realpath($base);
        $childReal = realpath($child);
        if ($baseReal === false || $childReal === false) {
            return false;
        }
        $a = strtolower(rtrim($childReal, '/\\')).DIRECTORY_SEPARATOR;
        $b = strtolower(rtrim($baseReal, '/\\')).DIRECTORY_SEPARATOR;
        return str_starts_with($a, $b);
    }
}
