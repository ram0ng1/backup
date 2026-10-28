<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
 * Inverso do ExportJob: aplica um `.flarum` na instalação em execução, nas
 * fases inspect → extract → reconcile → restore → rewrite → verify →
 * finalize. Conteúdo do fórum é substituído; arquivos só caem em assets/,
 * storage/ e extensões. O que descreve ESTE servidor é reconciliado:
 * `project/*` passa pelo {@see ProjectReconciler} (merge, não overwrite) e
 * as settings de infraestrutura são preservadas pelo {@see SettingsPreserver}.
 */
class ImportJob
{
    public const BUDGET_BYTES = 4_194_304;

    /** Nomes de entradas não resolvidas guardados para diagnóstico. */
    public const UNRESOLVED_SAMPLE_LIMIT = 20;

    /** Subdiretório do job onde os `project/*` esperam a reconciliação. */
    public const PROJECT_STAGING_DIR = 'project';

    /**
     * Fora do job.json porque carrega segredo do destino (senha de SMTP,
     * token OAuth). Fica no diretório 0700 do job, como o dump.sql.
     */
    public const PRESERVED_SETTINGS_FILE = 'preserved-settings.json';

    /**
     * Chave privada só em memória, nunca no job.json: em disco ela abriria
     * todos os backups cifrados. A CLI reusa a instância entre ticks; na web
     * o TickImportController reenvia a chave a cada tick.
     */
    private ?string $privateKey = null;

    /**
     * Tipo concreto porque o restore precisa de `getDriverName()`, que a
     * interface não expõe. O construtor recebe a interface (é o que o
     * container do Flarum injeta) e estreita aqui.
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
        if (! $db instanceof Connection) {
            throw new RuntimeException('Unsupported database connection.');
        }
        $this->db = $db;
    }

    /** Null ou vazio limpa a chave. */
    public function withPrivateKey(?string $privateKey): self
    {
        $this->privateKey = ($privateKey !== null && $privateKey !== '') ? $privateKey : null;
        return $this;
    }

    /**
     * O arquivo já precisa estar no job como `upload.flarum`.
     *
     * @param array{db?: bool, assets?: bool, storage?: bool, extensions?: bool|list<string>, root_extend?: bool, preserve_settings?: bool}|null $selection
     *        null = restaura tudo; extensions: true, false ou lista;
     *        root_extend: sobrescreve o extend.php da raiz (opt-in);
     *        preserve_settings: mantém as settings deste servidor (opt-out)
     */
    public function start(string $jobId, ?string $privateKey, bool $confirmReplace, ?array $selection, ?int $userId): JobState
    {
        if (! $confirmReplace) {
            throw new RuntimeException('Import requires explicit replace-confirmation.');
        }

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
                // A chave privada não entra aqui de propósito.
                'confirm_replace'  => true,
                'selection'        => $this->normaliseSelection($selection),
            ],
            'archive_meta'  => null,
            'warnings'      => [],
            // Amostras limitadas, para não inflar o estado.
            'unresolved_sample' => [],
            'unwritable_sample' => [],
            'progress' => [
                'total_bytes'        => filesize($upload) ?: 0,
                'processed_bytes'    => 0,
                'extracted_entries'  => 0,
                // Entradas que o arquivo trazia e o operador desmarcou.
                'skipped_entries'    => 0,
                // Selecionadas mas sem destino resolvível: defeito, não escolha.
                'unresolved_entries' => 0,
                // Destino resolvido mas o disco recusou (permissão, disco cheio).
                'unwritable_entries' => 0,
                'restored_statements' => 0,
                'percent'            => 0.0,
            ],
            'cursor' => [
                'bytes_read'        => 0,
                'restore_offset'    => 0,
                // Entrada deixada pela metade e tamanho confirmado do dump.sql.
                'current_entry'     => null,
                'dump_bytes'        => 0,
                'deferred_bytes'    => 0,
                'extract_done'      => false,
                // Posição do leitor (offset + estado do secretstream) para
                // retomar sem decifrar de novo; null até o primeiro tick.
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
            // Restore que parou no meio já trocou a tabela settings pela da
            // origem: a configuração deste servidor volta antes do job morrer.
            if (in_array($phase, ['restore', 'rewrite'], true)) {
                try {
                    $this->reapplyPreservedSettings($state);
                } catch (Throwable) {
                }
            }

            // Job com erro não continua: o dump em claro e o estado de
            // criptografia não servem para mais nada e não ficam no disco.
            @unlink((string) ($state->get('paths')['dump'] ?? ''));
            @unlink($this->deferredDumpPath($state));
            $state->forgetBinary();
            $state->set('phase', 'error');
            $state->set('message', 'Error: '.$e->getMessage());
            $state->save();
        }

        return $state;
    }

    /**
     * Valida o arquivo e a chave antes de tocar em qualquer dado, para o
     * erro aparecer cedo e claro.
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

            // Antes de tudo: backup de PHP mais novo não roda num mais
            // antigo (vendor/ e composer.lock foram resolvidos para ele).
            // Vale até para restore só de banco, que reativa extensões via
            // `extensions_enabled`.
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

            // Dump gerado para outro banco falharia no meio com erro de
            // sintaxe obscuro; MySQL e MariaDB são intercambiáveis.
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
                    } catch (Throwable) { /* detecção falhou: deixa o restore tentar */ }
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
     * SQL vai para o dump.sql do job; arquivos vão direto ao destino. Cada
     * tick salva a posição exata do leitor (offset, estado do secretstream,
     * sobra decifrada), então cada byte é decifrado uma vez só: O(n), não O(n²).
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
            // Com snapshot, retoma; sem, é o primeiro tick e decifra do cabeçalho.
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

            /*
             * Volta o dump.sql ao tamanho do último tick salvo: um tick que
             * morreu por timeout e roda de novo duplicaria os bytes.
             */
            $dumpFh = fopen($paths['dump'], 'cb');
            if ($dumpFh === false) {
                throw new RuntimeException('Could not open dump.sql for write.');
            }
            if (isset($cursor['dump_bytes'])) {
                ftruncate($dumpFh, (int) $cursor['dump_bytes']);
            }
            fseek($dumpFh, 0, SEEK_END);

            try {
                while ($budget > 0) {
                    $current = $cursor['current_entry'] ?? null;
                    $resuming = is_array($current);

                    if (! $resuming) {
                        $entry = $reader->nextEntry();
                        if ($entry === null) {
                            $cursor['extract_done'] = true;
                            break;
                        }

                        $accept = $this->shouldExtract($entry['name'], $entry['type'], $selection);
                        $dest = ($accept && $entry['type'] === Format::TYPE_FILE)
                            ? $this->resolveDestination($entry['name'], $state)
                            : null;

                        $current = [
                            'name'        => $entry['name'],
                            'type'        => $entry['type'],
                            'remaining'   => $entry['length'],
                            'accept'      => $accept,
                            'dest'        => $dest,
                            // Selecionada sem destino: os bytes são drenados, mas
                            // é perda de dado e não pode contar como extraída.
                            'unresolved'  => $accept && $entry['type'] === Format::TYPE_FILE && $dest === null,
                            'write_error' => null,
                        ];
                    }

                    // Os bytes são sempre consumidos; pular só não grava.
                    // Entrada retomada continua em append.
                    $sinkFh = null;
                    $writeError = $current['write_error'];
                    if ($current['accept'] && $current['type'] === Format::TYPE_DB_DUMP) {
                        $sinkFh = $dumpFh;
                    } elseif ($current['accept'] && $current['dest'] !== null && $writeError === null) {
                        $sinkFh = $this->openDestination($current['dest'], $writeError, $resuming ? 'ab' : 'wb');
                    }

                    try {
                        while ($current['remaining'] > 0 && $budget > 0) {
                            $want = (int) min(Format::CHUNK_SIZE, $current['remaining'], $budget);
                            $chunk = $reader->readEntryBytes($want);
                            if ($sinkFh !== null && $sinkFh !== $dumpFh) {
                                $written = @fwrite($sinkFh, $chunk);
                                if ($written === false || $written < strlen($chunk)) {
                                    /*
                                     * Escrita curta aqui é quase sempre
                                     * ENOSPC. Segue drenando para o leitor
                                     * parar numa fronteira de entrada.
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
                            $current['remaining'] -= strlen($chunk);
                            $progress['processed_bytes'] += strlen($chunk);
                            $budget -= strlen($chunk);
                        }
                    } finally {
                        if ($sinkFh !== null && $sinkFh !== $dumpFh) {
                            fclose($sinkFh);
                        }
                    }

                    $current['write_error'] = $writeError;

                    if ($current['remaining'] > 0) {
                        // Orçamento acabou no meio da entrada: o próximo tick continua dela.
                        $cursor['current_entry'] = $current;
                        break;
                    }
                    $cursor['current_entry'] = null;

                    if ($current['unresolved']) {
                        $progress['unresolved_entries']++;
                        $this->noteUnresolved($state, (string) $current['name']);
                    } elseif ($writeError !== null) {
                        $progress['unwritable_entries']++;
                        $this->noteUnwritable($state, (string) $current['dest'], $writeError);
                    } elseif ($current['accept']) {
                        $progress['extracted_entries']++;
                    } else {
                        $progress['skipped_entries']++;
                    }
                }

                fflush($dumpFh);
                $cursor['dump_bytes'] = (int) ftell($dumpFh);
            } finally {
                fclose($dumpFh);
            }

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
             * Roda sempre, até em restore só de banco: é ela que compara os
             * manifestos e avisa o que falta.
             */
            $state->set('phase', 'reconcile');
        }

        $state->save();
    }

    /**
     * Antes de tocar no banco: une o composer.json (nunca sobrescreve),
     * aplica o extend.php da raiz só sob opt-in e fotografa as settings do
     * destino enquanto a tabela ainda é dele.
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
     * Sem dump de banco não há o que preservar: a tabela não será tocada.
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
     * Roda depois do restore e do rewrite de URL, para ter a última palavra.
     */
    private function reapplyPreservedSettings(JobState $state): void
    {
        $file = $this->preservedSettingsFile($state);
        if (! is_file($file)) {
            return;
        }

        $raw = @file_get_contents($file); /* arquivo do próprio job, 0600; nosemgrep: flarum-v2-server-side-fetch */
        if ($raw === false) {
            return;
        }
        $snapshot = json_decode($raw, true);
        if (! is_array($snapshot) || $snapshot === []) {
            @unlink($file);
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

        // Só apaga quando tudo voltou: se a tabela settings sumiu, é a única cópia.
        if (count($restored) === count($snapshot)) {
            @unlink($file);
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

    /** Cria o staging com 0700, como o resto do job. */
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

        // Cada tick tem conexão nova: o estado de sessão do preâmbulo (FK
        // desligada, modo SQL) precisa ser refeito.
        DatabaseRestorer::prepareSession($this->db);

        $size = filesize($paths['dump']) ?: 0;
        $offset = (int) $cursor['restore_offset'];

        $fh = fopen($paths['dump'], 'rb');
        if ($fh === false) {
            throw new RuntimeException('Could not read dump.sql.');
        }

        // Comandos das tabelas de boot, guardados para o fim (ver
        // DatabaseRestorer::BOOT_TABLES). O arquivo volta ao tamanho que o
        // último tick salvo confirmou, como o dump.sql no extract.
        $deferredPath = $this->deferredDumpPath($state);
        $deferredFh = fopen($deferredPath, 'cb');
        if ($deferredFh === false) {
            fclose($fh);
            throw new RuntimeException('Could not open the deferred statements file.');
        }
        ftruncate($deferredFh, (int) ($cursor['deferred_bytes'] ?? 0));
        fseek($deferredFh, 0, SEEK_END);

        try {
            if (fseek($fh, $offset) !== 0) {
                throw new RuntimeException('Could not seek dump.sql.');
            }

            $restorer = new DatabaseRestorer($this->db);
            $restorer->deferBootTables($deferredFh, $this->db->getTablePrefix());
            $consumed = 0;
            while ($offset < $size) {
                $chunk = fread($fh, Format::CHUNK_SIZE);
                if ($chunk === false || $chunk === '') break;
                $restorer->feed($chunk);
                $consumed += strlen($chunk);
                $offset += strlen($chunk);
                $restorer->executeReady();

                if ($consumed < self::BUDGET_BYTES) {
                    continue;
                }

                /*
                 * Só encerra se algum statement rodou; senão o recuo abaixo
                 * anularia o tick e o job leria os mesmos bytes para sempre.
                 * Statement maior que o orçamento segue até fechar.
                 */
                if ($restorer->statementsRun() > 0) {
                    break;
                }
            }

            if ($offset >= $size) {
                $restorer->finish();
            } else {
                /*
                 * O cursor só avança até o que de fato rodou: o statement
                 * incompleto no buffer é relido no próximo tick, senão ele
                 * recomeçaria no meio de um literal.
                 */
                $offset -= $restorer->pendingBytes();
            }

            $progress['restored_statements'] = $restorer->statementsRun() + (int) $progress['restored_statements'];

            fflush($deferredFh);
            $cursor['deferred_bytes'] = (int) ftell($deferredFh);
        } finally {
            fclose($fh);
            fclose($deferredFh);
        }

        $cursor['restore_offset'] = $offset;
        $state->set('cursor', $cursor);
        $state->set('progress', $progress);
        $state->set('message', $offset >= $size
            ? 'Database restored.'
            : 'Restoring database… '.round($offset / max(1, $size) * 100, 1).'%'
        );

        if ($offset >= $size) {
            $progress['restored_statements'] += $this->runDeferred($deferredPath);
            $state->set('progress', $progress);
            @unlink($deferredPath);

            DatabaseRestorer::enableForeignKeys($this->db);

            // O dump já traz `setval(...)`, mas arquivos antigos não. Repetir
            // é inofensivo: `setval(seq, MAX(id)+1, false)` sempre converge.
            $this->resyncPostgresSequences($state);

            $state->set('phase', 'rewrite');
            $state->save();

            /*
             * As tabelas de autenticação acabaram de virar as da origem: a
             * sessão de quem disparou o restore não existe mais, e um próximo
             * tick pela web voltaria 401. As etapas que faltam rodam aqui,
             * ainda nesta requisição.
             */
            $this->runRewrite($state);
            $this->runVerify($state);
            $this->runFinalize($state);

            return;
        }

        $state->save();
    }

    private function deferredDumpPath(JobState $state): string
    {
        $paths = (array) $state->get('paths');

        return rtrim((string) ($paths['dir'] ?? ''), '/\\').DIRECTORY_SEPARATOR.'dump.deferred.sql';
    }

    /**
     * Executa de uma vez os comandos guardados das tabelas de boot. São
     * tabelas pequenas na maioria dos fóruns; o limite de tempo sai para
     * um fórum com muitos usuários não morrer no meio.
     */
    private function runDeferred(string $path): int
    {
        if (! is_file($path) || filesize($path) === 0) {
            return 0;
        }

        @set_time_limit(0);
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Could not read the deferred statements file.');
        }

        try {
            $restorer = new DatabaseRestorer($this->db);
            while (($chunk = fread($fh, Format::CHUNK_SIZE)) !== false && $chunk !== '') {
                $restorer->feed($chunk);
                $restorer->executeReady();
            }
            $restorer->finish();

            return $restorer->statementsRun();
        } finally {
            fclose($fh);
        }
    }

    /**
     * Põe cada sequence do PostgreSQL depois do `MAX(id)`; sem isso o
     * próximo INSERT colide com a chave primária. Um `setval` por coluna,
     * para uma tabela ruim não abortar as outras.
     */
    private function resyncPostgresSequences(JobState $state): void
    {
        try {
            if (Dialect::detect($this->db) !== Dialect::POSTGRES) return;
        } catch (Throwable) {
            return;
        }

        // pg_get_serial_sequence cobre SERIAL e IDENTITY (PG 10+).
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
                // Falha isolada não interrompe as demais.
            }
        }

        if ($bumped > 0) {
            $progress = (array) $state->get('progress');
            $progress['sequences_resynced'] = $bumped;
            $state->set('progress', $progress);
        }
    }

    /**
     * Troca a URL de origem pela do config.php em settings e posts; sem
     * isso, redirects, cookies e links absolutos quebram. Melhor esforço:
     * cada tabela tem seu próprio try.
     */
    private function runRewrite(JobState $state): void
    {
        $meta = (array) $state->get('archive_meta');
        $sourceUrl = isset($meta['source_url']) ? rtrim((string) $meta['source_url'], '/') : '';
        $destUrl   = rtrim((string) ($this->config['url'] ?? ''), '/');

        if ($sourceUrl !== '' && $destUrl !== '' && $sourceUrl !== $destUrl) {
            $stats = ['settings' => 0, 'posts_content' => 0, 'posts_parsed' => 0];

            // Crase no MySQL/MariaDB, aspas duplas no PostgreSQL/SQLite.
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

            // REPLACE() existe igual nos quatro bancos.
            try {
                $stats['settings'] = $this->db->update(
                    "UPDATE $settings SET $value = REPLACE($value, ?, ?) WHERE $value LIKE ?",
                    [$sourceUrl, $destUrl, '%'.$sourceUrl.'%']
                );
            } catch (Throwable) { /* tabela ausente ou schema diferente */ }

            // O XML do conteúdo quase só tem a URL do próprio fórum; a troca
            // textual é segura.
            try {
                $stats['posts_content'] = $this->db->update(
                    "UPDATE $posts SET $content = REPLACE($content, ?, ?) WHERE $content LIKE ?",
                    [$sourceUrl, $destUrl, '%'.$sourceUrl.'%']
                );
            } catch (Throwable) { /* ignore */ }

            // parsed_content pode não existir no Flarum 2.
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

        // Depois do REPLACE de propósito, para ter a última palavra.
        $this->reapplyPreservedSettings($state);

        $state->set('phase', 'verify');
        $state->save();
    }

    /**
     * O core carrega o extend.php da raiz antes de haver handler de erro:
     * classe ausente ali derruba o fórum sem log. Avisa nomeando a classe.
     * Usa o autoloader atual; um `composer install` posterior ainda pode podar.
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
     * Amostra limitada: o tamanho real está em `progress.unresolved_entries`.
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
     * Guarda o motivo do SO: permissão negada e disco cheio pedem ações
     * diferentes do operador.
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
     * Devolve null em vez de lançar: um `vendor/` sem permissão não pode
     * abortar o restore do banco, assets e storage.
     *
     * @param  string|null  $reason  Preenchido com o erro do SO quando falha.
     * @param  string       $mode    `ab` quando a entrada continua de um tick anterior.
     * @return resource|null
     */
    private function openDestination(string $dest, ?string &$reason, string $mode = 'wb')
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
        $fh = @fopen($dest, $mode);
        if ($fh === false) {
            $reason = $this->lastErrorMessage() ?? 'fopen recusou o destino';
            return null;
        }

        return $fh;
    }

    /** Último erro do PHP sem o prefixo da função (`fopen(...): ...`). */
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
     * Avisa (sem abortar) quais raízes da seleção o usuário do PHP não
     * consegue gravar, nomeando caminho e usuário.
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
     * A permissão que importa é a do primeiro ancestral existente; o
     * destino em si costuma ainda não existir.
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
     * Restore com perdas termina em `done` (abortar não desfaz nada), mas
     * nunca com mensagem de sucesso limpo: as perdas viram aviso.
     */
    private function runFinalize(JobState $state): void
    {
        $paths = $state->get('paths');
        @unlink($paths['dump']);
        @unlink($this->deferredDumpPath($state));
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

        // Classe ausente no extend.php da raiz também conta como incompleto.
        $state->forgetBinary();
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
     * `null` restaura tudo. `root_extend` é opt-in (troca configuração viva
     * do destino); `preserve_settings` é opt-out (perder a configuração do
     * destino é justamente o que se quer evitar).
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
     * Raízes desconhecidas são restauradas, para formatos futuros não se
     * perderem em silêncio.
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
             * Sempre extraído: vai para staging, não para o disco vivo, e
             * o runReconcile decide o que aplicar.
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
     * Manifesto v1 trazia só nomes de diretório, sempre em `workbench/<nome>`.
     *
     * @return array<string, string>  id → caminho absoluto
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
                $map[$ext] = $base.DIRECTORY_SEPARATOR.'workbench'.DIRECTORY_SEPARATOR.$ext;
                continue;
            }
            if (! is_array($ext)) continue;
            $id = (string) ($ext['id'] ?? '');
            $rel = (string) ($ext['relative'] ?? '');
            if ($id === '' || $rel === '') continue;
            // `relative` vem do cabeçalho, controlado por quem fez o arquivo.
            // Só aceita workbench/<dir> ou vendor/<vendor>/<pacote>, sem
            // segmento começando com ponto nem barra invertida.
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
     * Null para nome fora das raízes permitidas ou extensão desconhecida.
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
        if ($rest === '') return null;

        if ($root === 'extensions') {
            $cut = strpos($rest, '/');
            if ($cut === false) return null;
            $extId = substr($rest, 0, $cut);
            $inner = substr($rest, $cut + 1);
            if ($inner === '') return null;

            $map = $this->extensionDestinationMap($state);
            $base = $map[$extId] ?? null;
            if ($base === null) return null;
        } elseif ($root === 'project') {
            /*
             * Allow-list fechada (um arquivo hostil não larga `.htaccess`),
             * e o destino é o staging do job, nunca a raiz viva.
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

        // O filtro de `..` não pega fuga por symlink; só o realpath do pai
        // comparado com a base pega.
        @mkdir(dirname($candidate), 0755, true);
        if (! $this->isWithin($base, dirname($candidate))) {
            return null;
        }

        return $candidate;
    }

    /**
     * Compara via realpath (segue symlinks) e sem diferenciar caixa, por
     * causa do Windows.
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
