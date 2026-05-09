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
- **Pick what to bundle** — Database / `public/assets` / `storage` / local `workbench` extensions, ticked individually per export.
- **Resumable progress** — Both export and import run as a chunked-tick loop (~4 MB of work per HTTP request); the admin sees a live progress bar and can cancel mid-flight without leaving partial state behind.
- **Optional encryption** — Hybrid libsodium scheme (sealed-box wraps a per-archive XChaCha20-Poly1305 stream key). Public key lives in settings; the matching private key MUST be pasted into `config.php` — the web process never holds a key it has not been told.
- **Cross-server transfer** — Encrypt to a foreign public key at export time, paste the matching private key at import time; the extension never assumes both ends share `config.php`.
- **Auto URL rewrite** — The source forum URL is recorded in the archive header. On import, every occurrence in `settings`, `posts.content` and `posts.parsed_content` is rewritten to the destination server's URL, so links / redirects / cookies don't break after a cross-host restore.
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
| Extensions | Bundle local extensions under `workbench/` | `false` |
| Encrypt | Encrypt the archive body via libsodium | `false` |
| Foreign public key | Paste a different public key to encrypt for transfer | — |

### Per-import options (admin UI)

| Option | Description |
|---|---|
| Private key | Base64 private key for the archive's keypair (leave blank to use the local `config.php` key) |
| Replace existing data | Required confirmation — the SQL dump replaces every bundled table |

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

## Links

- [GitHub](https://github.com/ram0ng1/backup)
- [Issues](https://github.com/ram0ng1/backup/issues)
- [Donate](https://donate.stripe.com/fZe5o66nebkf39S28a)

## Authors

- [Ramon Guilherme](https://ramonguilherme.com.br)

## License

[MIT](LICENSE)
