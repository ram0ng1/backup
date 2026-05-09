<p align="center">
  <img src="icon.svg" width="80" alt="Backup">
  <h1 align="center">Backup &amp; Migration</h1>
</p>

<p align="center">
  <img alt="License" src="https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square">
  <a href="https://packagist.org/packages/ramon/backup">
    <img alt="Latest Stable Version" src="https://img.shields.io/packagist/v/ramon/backup.svg?style=flat-square">
  </a>
  <a href="https://packagist.org/packages/ramon/backup">
    <img alt="Total Downloads" src="https://img.shields.io/packagist/dt/ramon/backup.svg?style=flat-square">
  </a>
  <a href="https://github.com/ram0ng1/backup/releases/latest">
    <img alt="GitHub Release" src="https://img.shields.io/github/v/release/ram0ng1/backup?style=flat-square&label=release&color=success">
  </a>
  <a href="https://donate.stripe.com/fZe5o66nebkf39S28a">
    <img alt="Donate" src="https://img.shields.io/badge/donate-stripe-%236772E5?style=flat-square">
  </a>
</p>

<p align="center">
  All-in-one backup, export and import for <a href="https://flarum.org">Flarum</a>. Bundles your database, uploads, storage and local extensions into a single portable <code>.flarum</code> archive — with resumable progress, optional asymmetric encryption, and automatic URL rewriting when restoring on another server.
</p>

---

## Features

- **Single-file archive** — Custom `.flarum` format (not `.wpress`, not zip): magic-tagged header + entry stream, designed for forward-only streaming so multi-GB backups never need to fit in memory.
- **Pick what to bundle, per export** — Database / `public/assets` / `storage` / extensions, ticked individually. Extensions expand into a per-extension picker that detects which were installed via composer (`vendor/`) and which live in `workbench/`, with a tag on each row.
- **composer.json + composer.lock travel with extensions** — Whenever any extension is bundled the project root composer files come along under `project/`, so vendor extensions remain reproducible on the destination (`composer install` after restore brings them back without surprises).
- **Pick what to restore, per import** — On the import side the same selection UI appears, populated from the archive's manifest. The admin can untick a section or specific extensions before confirming; skipped entries are drained from the stream rather than written.
- **Resumable progress** — Both export and import run as a chunked-tick loop (~4 MB of work per HTTP request); the admin sees a live progress bar (with upload `%` during file upload) and can cancel mid-flight without leaving partial state behind.
- **Optional encryption** — Hybrid libsodium scheme (sealed-box wraps a per-archive XChaCha20-Poly1305 stream key). Public key lives in settings; the matching private key MUST be pasted into `config.php` — the web process never holds a key it has not been told.
- **Cross-server transfer** — Encrypt to a foreign public key at export time, paste the matching private key at import time; the extension never assumes both ends share `config.php`.
- **Auto URL rewrite** — The source forum URL is recorded in the archive header. On import, every occurrence in `settings`, `posts.content` and `posts.parsed_content` is rewritten to the destination server's URL, so links / redirects / cookies don't break after a cross-host restore.
- **Foreign-key-safe restore** — Each restore tick explicitly disables `FOREIGN_KEY_CHECKS` against the active connection, so DDL referencing not-yet-created tables (alphabetical-order create) succeeds without ordering dance.
- **Smart pruning while scanning** — `node_modules`, `.git`, `.idea`, `coverage`, nested `vendor/` and friends are skipped before the iterator descends, so a workbench extension scan stays seconds-fast instead of stat-ing tens of thousands of npm files.
- **Logged-out completion screen** — When the restore included the database, the import modal swaps to a dedicated "you've been logged out" screen with a single primary action (reload + sign in) — no chance for the admin to fire stale-session API calls and meet a confusing `401`.
- **Backups library** — Saved archives are listed in the admin panel with size, contents tags, encryption status and one-click download or delete.
- **Replace-only restore** — Imports always confirm with an "I understand this replaces my data" check; the SQL dump is `DROP TABLE` + `CREATE TABLE` per table, so a restore returns the install to a known-clean state.

## Requirements

- Flarum `^2.0.0`
- MySQL (the dumper / restorer is MySQL-specific)
- PHP `libsodium` extension if encryption is enabled (bundled with PHP 8.1+)

## Installation

```sh
composer require ramon/backup
php flarum migrate
php flarum cache:clear
```

Then enable **Backup &amp; Migration** under the *Extensions* page in the admin panel.

## Updating

```sh
composer update ramon/backup --with-dependencies
php flarum migrate
php flarum cache:clear
```

## Configuration

The extension is driven by per-export / per-import options in the admin UI rather than persisted settings. The one stored value is the encryption public key:

| Setting | Description | Default |
|---|---|---|
| Encryption public key | Base64 X25519 public key — empty disables encryption | — |

### `config.php` keys

| Key | Description |
|---|---|
| `backup-private-key` | Base64 X25519 private key paired with the public key in settings. Required to decrypt encrypted backups on this server. |

Example:

```php
'backup-private-key' => 'OBLafzuLtHzxQMLZ58r0PJeL6tKBih9qVNJSo6H5Wrs=',
```

### Per-export options (admin UI)

| Option | Description | Default |
|---|---|---|
| Database | Bundle a SQL dump of every table (DROP / CREATE / INSERT) | `true` |
| Assets | Bundle `public/assets` — avatars, attachments, user uploads | `true` |
| Storage | Bundle `storage/` — cache, sessions, logs (rarely portable) | `false` |
| Extensions | Show the per-extension picker (workbench + vendor) | `false` |
| Extension `<id>` | Per-extension toggle inside the picker | `true` for every installed extension |
| Encrypt | Encrypt the archive body via libsodium | `false` |
| Foreign public key | Paste a different public key to encrypt for transfer | — |

### Per-import options (admin UI)

| Option | Description |
|---|---|
| Restore selection | Per-section + per-extension checkboxes (populated from the archive's manifest) |
| Private key | Base64 private key for the archive's keypair (leave blank to use the local `config.php` key) |
| Replace existing data | Required confirmation — the SQL dump replaces every bundled table |

### Vendor extensions — composer reminder

Bundled vendor extensions land back in `vendor/<vendor>/<package>` and `composer.json` / `composer.lock` are written to the project root. The destination still needs `composer install` (or `composer dump-autoload`) afterwards so the autoloader sees the restored packages and so any sub-dependencies are present; otherwise Flarum will fail to boot a vendor extension whose dependencies were never installed locally.

## Permissions

- **Backup — manage backups**: who can create, list, download, delete backups and run imports (admins by default).

## API Endpoints

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/backup/backups` | List saved backups |
| `DELETE` | `/api/backup/backups/{id}` | Delete a saved backup (file + row) |
| `GET` | `/api/backup/backups/{id}/download` | Download a saved `.flarum` archive |
| `POST` | `/api/backup/exports` | Start a new export job |
| `POST` | `/api/backup/exports/{id}/tick` | Drive the export forward (~4 MB of work) |
| `DELETE` | `/api/backup/exports/{id}` | Cancel an in-progress export and wipe its tmp dir |
| `POST` | `/api/backup/imports` | Upload a `.flarum` archive and inspect its header |
| `POST` | `/api/backup/imports/{id}/start` | Start the restore for an uploaded archive |
| `POST` | `/api/backup/imports/{id}/tick` | Drive the restore forward |
| `DELETE` | `/api/backup/imports/{id}` | Cancel an in-progress import and wipe its tmp dir |
| `GET` | `/api/backup/encryption/status` | Inspect encryption key status |
| `POST` | `/api/backup/encryption/generate-keypair` | Generate a new encryption keypair (private half is shown ONCE) |
| `GET` | `/api/backup/extensions` | List installed extensions (workbench + vendor) for the export-side picker |

## Archive format

`.flarum` files use a small custom container, documented in [src/Archive/Format.php](src/Archive/Format.php):

```
[8 bytes ] MAGIC          "FLARUM01"
[1 byte  ] FLAGS          bit 0 = encrypted
[4 bytes ] META_LENGTH    big-endian unsigned int
[N bytes ] META_JSON      UTF-8 JSON metadata (never secret)

If encrypted:
  [80 bytes] WRAPPED_KEY   sealed_box(public, K)
  [24 bytes] STREAM_HEADER libsodium secretstream init header
  [chunks…] each chunk = [4 bytes BE length][N bytes ciphertext]

Entry stream (encrypted or plain):
  For each entry:
    [4 bytes BE] NAME_LENGTH    0 marks end of stream
    [N bytes  ] NAME            UTF-8 logical path
    [1 byte   ] TYPE            0=file, 1=db_dump
    [8 bytes BE] DATA_LENGTH
    [N bytes  ] DATA
```

The metadata header always travels in plaintext so the importer can decide how to handle the file (and prompt for the right private key) without committing to any decryption.

### Logical name roots

| Prefix | Restored to | Notes |
|---|---|---|
| `database.sql` | (replayed into MySQL) | Single SQL dump entry, type `1` |
| `assets/<…>` | `public/assets/<…>` | Avatars, attachments, uploads |
| `storage/<…>` | `storage/<…>` | Skipped during backup of `backups/` and `backup-tmp/` to avoid recursion |
| `extensions/<id>/<…>` | original location of `<id>` | Mapped via the manifest entry — workbench extensions go to `workbench/<dirname>`, vendor extensions to `vendor/<vendor>/<package>` |
| `project/composer.json` | `composer.json` | Only `composer.json` and `composer.lock` are accepted under `project/` — any other path is rejected |
| `project/composer.lock` | `composer.lock` | |

### Metadata header shape

```jsonc
{
  "format_version": 1,
  "created_at": "2026-05-09T12:34:56+00:00",
  "flarum_version": "v2.0.0",
  "php_version": "8.3.8",
  "contents": ["db", "assets", "extensions"],
  "source_url": "https://forum.example.com",
  "manifest": {
    "asset_count": 142,
    "storage_count": 0,
    "extension_count": 213,
    "has_composer": true,
    "extensions": [
      {
        "id": "ramon-verified",
        "name": "ramon/verified",
        "title": "Verified",
        "version": "1.0.0",
        "location": "vendor",
        "relative": "vendor/ramon/verified",
        "files": 123,
        "bytes": 1048576
      },
      {
        "id": "local-thing",
        "name": "acme/local-thing",
        "title": "Local Thing",
        "version": "0.1.0",
        "location": "workbench",
        "relative": "workbench/local-thing",
        "files": 90,
        "bytes": 524288
      }
    ]
  }
}
```

## Links

- [GitHub](https://github.com/ram0ng1/backup)
- [Issues](https://github.com/ram0ng1/backup/issues)
- [Donate](https://donate.stripe.com/fZe5o66nebkf39S28a)

## Authors

- [Ramon Guilherme](https://ramonguilherme.com.br)

## License

[MIT](LICENSE)
