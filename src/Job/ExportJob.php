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
use Ramon\Backup\Archive\ArchiveWriter;
use Ramon\Backup\Archive\Format;
use Ramon\Backup\Crypto\BackupCipher;
use Ramon\Backup\Database\DatabaseDumper;
use Ramon\Backup\Database\Dialect;
use Ramon\Backup\Environment\StackSnapshot;
use Ramon\Backup\Extensions\Inventory;
use Ramon\Backup\Locale\Messages;
use Ramon\Backup\Models\Backup;
use Ramon\Backup\StoragePaths;
use RuntimeException;
use Throwable;

/**
 * Conduz uma exportação em vários ticks HTTP, nas fases scan → db_dump →
 * bundle → finalize → done. Cada tick grava no máximo BUDGET_BYTES, então
 * pode parar no meio de um arquivo e retomar no tick seguinte.
 */
class ExportJob
{
    /** Bytes gravados por tick antes de pausar. */
    public const BUDGET_BYTES = 4_194_304; // 4 MB

    /** Arquivos maiores que isso ficam de fora. */
    public const FILE_SIZE_LIMIT = 2 * 1024 * 1024 * 1024; // 2 GB

    /**
     * Diretórios ignorados em qualquer nível da árvore (pelo nome). Um
     * `node_modules/` sozinho infla o backup em centenas de MB, e nada disso
     * serve no restore: npm/composer/git reconstroem.
     */
    private const PRUNE_DIR_NAMES = [
        'node_modules' => true,
        // `dist` fica: o Flarum carrega em runtime e o destino precisaria
        // de `npm run build` sem ele.
        'dist-typings' => true,
        '.parcel-cache' => true,
        '.git'         => true,
        '.svn'         => true,
        '.hg'          => true,
        '.idea'        => true,
        '.vscode'      => true,
        '.history'     => true,
        'coverage'     => true,
        '.nyc_output'  => true,
        '__pycache__'  => true,
        // vendor/ dentro de extensão traria milhares de arquivos do composer.
        'vendor'       => true,
    ];

    /**
     * Tipo concreto porque o dump precisa de `getDriverName()`, que a
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
        protected Inventory $inventory,
        protected Messages $messages
    ) {
        if (! $db instanceof Connection) {
            throw new RuntimeException('Unsupported database connection.');
        }
        $this->db = $db;
    }

    /**
     * Cria o job; quem chama roda `runTick()` até phase=done.
     * `contents.extensions`: true (todas), false (nenhuma) ou lista de ids.
     *
     * @param array{db: bool, assets: bool, storage: bool, extensions: bool|list<string>} $contents
     * @param array{enabled: bool, public_key?: string|null} $encryption
     * @param string|null $targetDialect  Banco de destino (mysql, mariadb, postgres,
     *                                    sqlite); null usa o mesmo da origem.
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

        // Valida já aqui para um dialeto inválido não falhar no meio do dump.
        $resolvedTarget = null;
        if ($targetDialect !== null && $targetDialect !== '') {
            $resolvedTarget = Dialect::parse($targetDialect)->value;
        }

        $initial = [
            'job_id'        => $jobId,
            'created_by'    => $userId,
            'started_at'    => time(),
            'phase'         => 'scan',
            'message'       => $this->messages->get('export.preparing'),
            'contents'      => [
                'db'         => ! empty($contents['db']),
                'assets'     => ! empty($contents['assets']),
                'storage'    => ! empty($contents['storage']),
                // Mantém o formato: o scan distingue "todas" (true) de lista.
                'extensions' => $this->normaliseExtensionSelection($contents['extensions'] ?? false),
            ],
            'encryption'    => [
                'enabled'    => $useEncryption,
                'use_external_key' => $useEncryption && $providedKey !== '',
                'external_public_key' => $useEncryption && $providedKey !== '' ? $providedKey : null,
            ],
            // Fica no estado para todo tick usar o mesmo emissor, e vai no
            // cabeçalho para o import recusar dump feito para outro banco.
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
            // Avisos de tradução com perda (tipos sem suporte, colunas
            // geradas), acumulados entre ticks e mostrados no fim.
            'db_warnings'   => [],
            // Plano do scan, montado no primeiro tick; um alvo por tick.
            'scan'          => [],
        ];

        return JobState::create($dir.DIRECTORY_SEPARATOR.'job.json', $initial);
    }

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
            // Job com erro não continua: o dump em claro e o estado de
            // criptografia não servem para mais nada e não ficam no disco.
            @unlink((string) ($state->get('paths')['dump'] ?? ''));
            @unlink((string) ($state->get('paths')['archive'] ?? ''));
            $state->forgetBinary();
            $state->set('phase', 'error');
            $state->set('message', $this->messages->get('error', ['message' => $e->getMessage()]));
            $state->save();
        }

        return $state;
    }

    /**
     * Lista os arquivos um alvo por tick (assets, storage, cada extensão,
     * composer) num manifesto NDJSON, para não estourar max_execution_time.
     * Um storage/ enorme ainda cabe num tick só; nesse caso, use a CLI.
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

            $state->set('message', $this->messages->get('export.scanning', ['current' => $idx + 1, 'total' => count($targets)]));
            $state->save();
            return; // continua em 'scan'
        }

        $this->finalizeScan($state, $scan);
    }

    /**
     * A seleção diz QUAIS extensões entram; o inventário diz ONDE estão
     * (workbench/ ou vendor/). Apaga o manifesto antigo antes.
     *
     * @return array{built: bool, targets: list<array<string, mixed>>, idx: int, files: int, bytes: int, counts: array{asset: int, storage: int, extension: int}, ext_descriptors: list<array<string, mixed>>, has_composer: bool, has_root_extend: bool}
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
                // Não faz backup dos próprios backups.
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
                        // Caminho relativo na origem; o import restaura no
                        // mesmo lugar (vendor/ ou workbench/).
                        'relative' => $ext['relative'],
                    ],
                ];
            }

            // Sem o composer.json/lock, o próximo `composer install` no
            // destino apagaria as extensões de vendor/.
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
            'has_root_extend' => false,
        ];
    }

    /**
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
                /*
                 * O extend.php da raiz vai junto porque consome as
                 * dependências do manifesto; sem ele em sincronia, pode
                 * apontar para classe podada e dar fatal no boot.
                 */
                foreach (['composer.json', 'composer.lock', 'extend.php'] as $rel) {
                    $abs = rtrim($this->appPaths->base, '/\\').DIRECTORY_SEPARATOR.$rel;
                    if (is_file($abs)) {
                        $size = filesize($abs) ?: 0;
                        $files[] = ['name' => 'project/'.$rel, 'absolute' => $abs, 'size' => $size];
                        $bytes += $size;
                        if ($rel === 'extend.php') {
                            $scan['has_root_extend'] = true;
                        } else {
                            $scan['has_composer'] = true;
                        }
                    }
                }
                break;
        }

        $this->appendManifest($manifestPath, $files);
        $scan['files'] += count($files);
        $scan['bytes'] += $bytes;
    }

    /**
     * Falha de encode (nome não UTF-8) ou de escrita lança exceção: um
     * manifesto perdido em silêncio daria um backup "ok" sem os arquivos.
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

    private function finalizeScan(JobState $state, array $scan): void
    {
        $contents    = $state->get('contents');
        $bundledExts = $scan['ext_descriptors'];

        $state->set('bundled_extensions', $bundledExts);
        $state->set('has_composer', (bool) $scan['has_composer']);

        $state->set('cursor', array_merge($state->get('cursor'), [
            'tables' => ! empty($contents['db']) ? $this->listTablesSafely() : [],
        ]));

        // Vai no cabeçalho para a tela de import montar as opções sem ler
        // as entradas.
        $summary = [
            'asset_count'     => (int) $scan['counts']['asset'],
            'storage_count'   => (int) $scan['counts']['storage'],
            'extension_count' => (int) $scan['counts']['extension'],
            'extensions'      => $bundledExts,
            'has_composer'    => (bool) $scan['has_composer'],
            'has_root_extend' => (bool) ($scan['has_root_extend'] ?? false),
        ];
        $state->set('manifest_summary', $summary);

        $progress = $state->get('progress');
        $progress['total_files'] = (int) $scan['files'];
        $progress['total_bytes'] = (int) $scan['bytes'];
        // O tamanho do SQL só entra no total quando o dump terminar.
        $state->set('progress', $progress);

        $state->set('phase', ! empty($contents['db']) ? 'db_dump' : 'bundle');
        $state->set('message', $this->messages->get(! empty($contents['db']) ? 'export.dumping' : 'export.bundling'));
        $state->save();
    }

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

            // Todos os DROP antes de qualquer CREATE, para o restore não
            // depender do schema que já existe no destino.
            if (empty($cursor['wrote_drops'])) {
                $sql = $dumper->dropAllTables();
                fwrite($fh, $sql);
                $written += strlen($sql);
                $cursor['wrote_drops'] = true;
            }

            while ($written < self::BUDGET_BYTES && $cursor['table_idx'] < count($cursor['tables'])) {
                $table = $cursor['tables'][$cursor['table_idx']];

                if ($cursor['table_offset'] === 0) {
                    $cursor['table_total'] = $dumper->countRows($table);
                    $sql = $dumper->dumpSchema($table);
                    fwrite($fh, $sql);
                    $written += strlen($sql);
                }

                $batch = $dumper->dumpDataBatch($table, $cursor['table_offset'], $cursor['table_key'] ?? null);
                if ($batch['consumed'] === 0) {
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

        // Cada dumper só conhece os avisos das tabelas deste tick; o estado
        // guarda a união.
        $existing = (array) $state->get('db_warnings', []);
        $merged = array_values(array_unique(array_merge(
            array_map('strval', $existing),
            $dumper->warnings(),
        )));
        $state->set('db_warnings', $merged);

        if ($allTablesDone) {
            $state->set('message', $this->messages->get('export.dump_complete'));
        } else {
            $tableCount = count($cursor['tables']);
            $idx        = (int) $cursor['table_idx'];
            $name       = $cursor['tables'][$idx] ?? '?';
            $rowsDone   = (int) $cursor['table_offset'];
            $rowsTotal  = (int) ($cursor['table_total'] ?? 0);

            $rows = $rowsTotal > 0
                ? $this->messages->get('export.rows_of', ['done' => $rowsDone, 'total' => $rowsTotal])
                : $this->messages->get('export.rows', ['done' => $rowsDone]);

            $state->set('message', $this->messages->get('export.dumping_table', [
                'current' => $idx + 1,
                'total'   => $tableCount,
                'table'   => $name,
                'rows'    => $rows,
            ]));
        }

        // Progresso estruturado para o painel não depender da mensagem.
        $state->set('db_progress', [
            'table_index' => (int) $cursor['table_idx'],
            'table_count' => count($cursor['tables']),
            'table_name'  => $cursor['tables'][$cursor['table_idx']] ?? null,
            'rows_done'   => (int) $cursor['table_offset'],
            'rows_total'  => (int) ($cursor['table_total'] ?? 0),
        ]);

        if ($allTablesDone) {
            // Soma o dump ao total para a barra cobrir banco e arquivos.
            $progress = $state->get('progress');
            $progress['total_bytes'] = (int) $progress['total_bytes'] + (filesize($paths['dump']) ?: 0);
            $state->set('progress', $progress);

            $state->set('phase', 'bundle');
        }

        $state->save();
    }

    private function runBundle(JobState $state): void
    {
        $cursor   = $state->get('cursor');
        $paths    = $state->get('paths');
        $progress = $state->get('progress');
        $contents = $state->get('contents');
        $enc      = $state->get('encryption');

        $archivePath = $paths['archive'];

        $writer = $this->openWriter($state, $archivePath, $enc);

        try {
            $budgetLeft = self::BUDGET_BYTES;
            // Lido uma vez por tick: dentro do laço o NDJSON inteiro era
            // decodificado de novo a cada arquivo.
            $manifest = null;

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
                    $manifest ??= $this->loadManifest($paths['manifest']);
                    if ($cursor['file_idx'] >= count($manifest)) {
                        $cursor['bundle_step'] = 'trailer';
                        continue;
                    }

                    $entry = $manifest[$cursor['file_idx']];
                    $abs   = $entry['absolute'];
                    $size  = $entry['size'];
                    $headerWritten = $cursor['file_offset'] > 0;

                    if (! $headerWritten && ! is_file($abs)) {
                        // Sumiu antes do cabeçalho: dá para pular sem desalinhar.
                        $cursor['file_idx']++;
                        $cursor['file_offset'] = 0;
                        $progress['processed_files']++;
                        continue;
                    }

                    if (! $headerWritten) {
                        $writer->beginEntry($entry['name'], Format::TYPE_FILE, $size);
                    }

                    $remaining = $size - $cursor['file_offset'];
                    if ($remaining <= 0) {
                        $cursor['file_idx']++;
                        $cursor['file_offset'] = 0;
                        $progress['processed_files']++;
                        continue;
                    }

                    $want = (int) min($remaining, $budgetLeft);
                    $written = is_file($abs) ? $this->streamRange($abs, $cursor['file_offset'], $want, $writer) : 0;
                    if ($written === 0) {
                        /*
                         * O cabeçalho já declarou o tamanho e o leitor confia
                         * nele: se o arquivo encolheu ou sumiu, completa com
                         * zeros para não desalinhar o resto.
                         */
                        $written = $this->padEntry($writer, $want);
                        $this->noteChangedFile($state, $entry['name']);
                    }
                    $cursor['file_offset'] += $written;
                    $progress['processed_bytes'] += $written;
                    $budgetLeft -= $written;
                    continue;
                }

                if ($cursor['bundle_step'] === 'trailer') {
                    $writer->finalize();
                    $cursor['bundle_step'] = 'done';
                    continue;
                }
            }

            // Estado do secretstream para o próximo tick retomar.
            $writer->flushEncryptedBuffer();
            $encState = $writer->serializeEncryptionState();
            if ($encState !== null) {
                $state->setBinary('stream_state', $encState['state']);
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
                ? $this->messages->get('export.finalising')
                : $this->messages->get('export.bundling_files', [
                    'done'  => $progress['processed_files'],
                    'total' => $progress['total_files'],
                ])
        );

        if ($cursor['bundle_step'] === 'done') {
            $state->set('phase', 'finalize');
        }

        $state->save();
    }

    private function runFinalize(JobState $state): void
    {
        $paths    = $state->get('paths');
        $contents = $state->get('contents');
        $enc      = $state->get('encryption');

        // A linha provisória dá o ID usado no nome final. `target_dialect`
        // fica NULL quando o destino é o mesmo banco da origem.
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
            $row->delete();
            throw new RuntimeException('Could not move archive into place.');
        }

        $row->filename = $finalName;
        $row->size_bytes = filesize($finalPath) ?: 0;
        $row->save();

        // O job.json fica para o JS ler o status final; sai no DELETE do
        // endpoint de exportação.
        @unlink($paths['dump']);
        @unlink($paths['manifest']);

        $state->forgetBinary();
        $state->set('phase', 'done');
        $state->set('message', $this->messages->get('export.complete'));
        $state->set('result', [
            'backup_id' => (int) $row->id,
            'filename'  => $finalName,
            'size'      => $row->size_bytes,
        ]);
        $state->save();
    }

    private function openWriter(JobState $state, string $archivePath, array $enc): ArchiveWriter
    {
        $exists = is_file($archivePath);

        if (! $exists) {
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

    /** Retorna quantos bytes foram de fato copiados (≤ $maxBytes). */
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

    /** Grava `$bytes` zeros na entrada corrente, em pedaços de CHUNK_SIZE. */
    private function padEntry(ArchiveWriter $writer, int $bytes): int
    {
        $left = $bytes;
        while ($left > 0) {
            $piece = (int) min(Format::CHUNK_SIZE, $left);
            $writer->appendEntryBytes(str_repeat("\0", $piece));
            $left -= $piece;
        }

        return $bytes;
    }

    private function noteChangedFile(JobState $state, string $name): void
    {
        $warnings = (array) $state->get('db_warnings', []);
        $warnings[] = $this->messages->get('export.file_changed', ['file' => $name]);
        $state->set('db_warnings', array_values(array_unique(array_map('strval', $warnings))));
    }

    /**
     * Não desce em PRUNE_DIR_NAMES nem nos caminhos de $skipDirs.
     *
     * @param list<array{name: string, absolute: string, size: int}> $files
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
                    // O filtro roda antes de descer, então o conteúdo nem é listado.
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
     * Linhas que não decodificam são ignoradas em vez de abortar o bundle.
     *
     * @return list<array{name: string, absolute: string, size: int}>
     */
    private function loadManifest(string $path): array
    {
        $raw = @file_get_contents($path); /* leitura de arquivo local, sem URL de input; nosemgrep: flarum-v2-server-side-fetch */
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
            // A lista vem da origem; o dialeto de destino não interfere.
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
        } catch (Throwable) { /* fica vazio se desconhecido */ }

        return [
            'format_version' => 3, // 2 = dialetos origem/destino; 3 = retrato da stack
            'created_at'     => gmdate('c'),
            'flarum_version' => $this->detectFlarumVersion(),
            'php_version'    => PHP_VERSION,
            // O import recusa restaurar uma stack mais nova sobre uma mais
            // antiga (StackSnapshot::blockingReason()).
            StackSnapshot::META_KEY => StackSnapshot::capture(),
            'contents'       => array_keys(array_filter($contents)),
            'source_dialect' => $sourceDialect,
            // Igual à origem, salvo em migração entre bancos.
            'target_dialect' => $target ?: $sourceDialect,
            // Em claro (não é segredo): o import troca pela URL do destino,
            // senão links absolutos e cookies quebram em outro host.
            'source_url'     => $this->detectSourceUrl(),
            'manifest'       => (array) $state->get('manifest_summary', []),
        ];
    }

    private function detectSourceUrl(): string
    {
        $url = $this->config['url'] ?? '';
        if (! is_string($url)) return '';
        return rtrim($url, '/');
    }

    /** Resultado: true (todas), false (nenhuma) ou lista de ids. */
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
            $data = json_decode((string) file_get_contents($composer), true); /* leitura de arquivo local, sem URL de input; nosemgrep: flarum-v2-server-side-fetch */
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
