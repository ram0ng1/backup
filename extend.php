<?php

namespace Ramon\Backup;

use Flarum\Extend;
use Ramon\Backup\Api\Controller\CancelExportController;
use Ramon\Backup\Api\Controller\CancelImportController;
use Ramon\Backup\Api\Controller\DeleteBackupController;
use Ramon\Backup\Api\Controller\DownloadBackupController;
use Ramon\Backup\Api\Controller\EncryptionStatusController;
use Ramon\Backup\Api\Controller\GenerateKeypairController;
use Ramon\Backup\Api\Controller\ListBackupsController;
use Ramon\Backup\Api\Controller\ListExtensionsController;
use Ramon\Backup\Api\Controller\StartExportController;
use Ramon\Backup\Api\Controller\StartImportController;
use Ramon\Backup\Api\Controller\TickExportController;
use Ramon\Backup\Api\Controller\TickImportController;
use Ramon\Backup\Api\Controller\UploadImportController;

return [
    (new Extend\Frontend('admin'))
        ->css(__DIR__.'/less/admin.less')
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Settings())
        // Public encryption key (base64). Empty string means encryption is off.
        // Same trust model as ramon/verified — the matching PRIVATE key is
        // pasted into config.php under `backup-private-key`, never stored
        // in the database.
        ->default('ramon-backup.encryption_public_key', ''),

    (new Extend\Routes('api'))
        ->get('/backup/backups',                'backup.list',          ListBackupsController::class)
        ->delete('/backup/backups/{id:[0-9]+}', 'backup.delete',        DeleteBackupController::class)
        ->get('/backup/backups/{id:[0-9]+}/download', 'backup.download', DownloadBackupController::class)

        ->post('/backup/exports',                'backup.export.start',  StartExportController::class)
        ->post('/backup/exports/{id:[a-f0-9]+}/tick', 'backup.export.tick', TickExportController::class)
        ->delete('/backup/exports/{id:[a-f0-9]+}',    'backup.export.cancel', CancelExportController::class)

        ->post('/backup/imports',                'backup.import.upload', UploadImportController::class)
        ->post('/backup/imports/{id:[a-f0-9]+}/start', 'backup.import.start', StartImportController::class)
        ->post('/backup/imports/{id:[a-f0-9]+}/tick',  'backup.import.tick',  TickImportController::class)
        ->delete('/backup/imports/{id:[a-f0-9]+}',     'backup.import.cancel', CancelImportController::class)

        ->get('/backup/encryption/status',          'backup.encryption.status',   EncryptionStatusController::class)
        ->post('/backup/encryption/generate-keypair','backup.encryption.generate', GenerateKeypairController::class)

        ->get('/backup/extensions', 'backup.extensions.list', ListExtensionsController::class),
];
