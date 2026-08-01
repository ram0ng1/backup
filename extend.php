<?php

namespace Ramon\Backup;

use Flarum\Extend;
use Ramon\Backup\Api\Controller\CancelExportController;
use Ramon\Backup\Api\Controller\CancelImportController;
use Ramon\Backup\Api\Controller\ChunkImportController;
use Ramon\Backup\Api\Controller\DeleteBackupController;
use Ramon\Backup\Api\Controller\DownloadBackupController;
use Ramon\Backup\Api\Controller\EncryptionStatusController;
use Ramon\Backup\Api\Controller\GenerateKeypairController;
use Ramon\Backup\Api\Controller\InspectImportController;
use Ramon\Backup\Api\Controller\ListBackupsController;
use Ramon\Backup\Api\Controller\ListExtensionsController;
use Ramon\Backup\Api\Controller\StartExportController;
use Ramon\Backup\Api\Controller\StartImportController;
use Ramon\Backup\Api\Controller\TickExportController;
use Ramon\Backup\Api\Controller\TickImportController;
use Ramon\Backup\Api\Controller\UploadImportController;
use Ramon\Backup\Console\ExportCommand;
use Ramon\Backup\Console\ImportCommand;
use Ramon\Backup\Console\PruneStaleJobsCommand;
use Ramon\Backup\Settings\SettingsPreserver;

return [
    (new Extend\Frontend('admin'))
        ->css(__DIR__.'/less/admin.less')
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Console())
        ->command(ExportCommand::class)
        ->command(ImportCommand::class)
        ->command(PruneStaleJobsCommand::class)
        // Sweep abandoned/errored staging dirs daily so a closed tab or
        // a failed job doesn't leave a plaintext dump.sql sitting under
        // storage/backup-tmp forever. Requires the operator's cron to run
        // `php flarum schedule:run`; the command is also runnable by hand.
        ->schedule(PruneStaleJobsCommand::class, function (\Illuminate\Console\Scheduling\Event $event) {
            $event->daily();
        }),

    (new Extend\Settings())
        // Public encryption key (base64). Empty string means encryption is off.
        // Same trust model as ramon/verified — the matching PRIVATE key is
        // pasted into config.php under `backup-private-key`, never stored
        // in the database.
        ->default('ramon-backup.encryption_public_key', '')
        /*
         * Padrões extra de chaves de `settings` que um restore não pode
         * sobrescrever, um por linha (sufixo `*` casa por prefixo). O
         * baseline — SMTP, fila, tokens desta instalação — está em
         * SettingsPreserver::BASELINE; esta chave existe para o operador
         * acrescentar o que for específico do ambiente dele.
         */
        ->default(SettingsPreserver::EXTRA_KEY, ''),

    (new Extend\Routes('api'))
        ->get('/backup/backups',                'backup.list',          ListBackupsController::class)
        ->delete('/backup/backups/{id:[0-9]+}', 'backup.delete',        DeleteBackupController::class)
        ->get('/backup/backups/{id:[0-9]+}/download', 'backup.download', DownloadBackupController::class)

        ->post('/backup/exports',                'backup.export.start',  StartExportController::class)
        ->post('/backup/exports/{id:[a-f0-9]+}/tick', 'backup.export.tick', TickExportController::class)
        ->delete('/backup/exports/{id:[a-f0-9]+}',    'backup.export.cancel', CancelExportController::class)

        // Chunked upload protocol — see UploadImportController docblock
        // for why this replaced the old single multipart POST.
        ->post('/backup/imports',                          'backup.import.upload',  UploadImportController::class)
        ->post('/backup/imports/{id:[a-f0-9]+}/chunk',     'backup.import.chunk',   ChunkImportController::class)
        ->post('/backup/imports/{id:[a-f0-9]+}/inspect',   'backup.import.inspect', InspectImportController::class)
        ->post('/backup/imports/{id:[a-f0-9]+}/start',     'backup.import.start',   StartImportController::class)
        ->post('/backup/imports/{id:[a-f0-9]+}/tick',      'backup.import.tick',    TickImportController::class)
        ->delete('/backup/imports/{id:[a-f0-9]+}',         'backup.import.cancel',  CancelImportController::class)

        ->get('/backup/encryption/status',          'backup.encryption.status',   EncryptionStatusController::class)
        ->post('/backup/encryption/generate-keypair','backup.encryption.generate', GenerateKeypairController::class)

        ->get('/backup/extensions', 'backup.extensions.list', ListExtensionsController::class),
];
