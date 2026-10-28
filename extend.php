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
use Ramon\Backup\Console\PruneStaleJobsSchedule;
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
        // Limpa diariamente staging abandonado ou com erro, para não sobrar
        // dump.sql em texto puro em storage/backup-tmp. Depende do cron rodar
        // `php flarum schedule:run`; o comando também roda à mão.
        ->schedule(PruneStaleJobsCommand::class, PruneStaleJobsSchedule::class),

    (new Extend\Settings())
        // Chave pública (base64); vazia desliga a criptografia. A chave
        // privada fica só no config.php (`backup-private-key`), nunca no banco.
        ->default('ramon-backup.encryption_public_key', '')
        /*
         * Chaves extras de `settings` que o restore não sobrescreve, uma por
         * linha (`*` no fim casa por prefixo). Somam-se a
         * SettingsPreserver::BASELINE.
         */
        ->default(SettingsPreserver::EXTRA_KEY, ''),

    (new Extend\Routes('api'))
        ->get('/backup/backups',                'backup.list',          ListBackupsController::class)
        ->delete('/backup/backups/{id:[0-9]+}', 'backup.delete',        DeleteBackupController::class)
        ->get('/backup/backups/{id:[0-9]+}/download', 'backup.download', DownloadBackupController::class)

        ->post('/backup/exports',                'backup.export.start',  StartExportController::class)
        ->post('/backup/exports/{id:[a-f0-9]+}/tick', 'backup.export.tick', TickExportController::class)
        ->delete('/backup/exports/{id:[a-f0-9]+}',    'backup.export.cancel', CancelExportController::class)

        // Upload em partes; o protocolo está no docblock de UploadImportController.
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
