# 📦 Backup &amp; Migration — Portable Backups for Flarum

![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square) [![Latest Stable Version](https://img.shields.io/packagist/v/ramon/backup.svg?style=flat-square)](https://packagist.org/packages/ramon/backup) [![Total Downloads](https://img.shields.io/packagist/dt/ramon/backup.svg?style=flat-square)](https://packagist.org/packages/ramon/backup) [![GitHub Release](https://img.shields.io/github/v/release/ram0ng1/backup?style=flat-square&label=release&color=success)](https://github.com/ram0ng1/backup/releases/latest) [![Donate](https://img.shields.io/badge/donate-stripe-%236772E5?style=flat-square)](https://donate.stripe.com/fZe5o66nebkf39S28a)

**A complete backup, export and import system for Flarum 2.x**

### About the Project

**Backup &amp; Migration** is a full-featured backup and migration extension I've been
building for Flarum, inspired by *All-in-One WP Migration* but written from
scratch with a Flarum-native format. It bundles your forum into a single
portable `.flarum` file — database, uploads, storage, and any installed
extensions (workbench *or* vendor) — and restores it on the same install or a
different one with one click.

It started from my own need to migrate forums between hosts without the manual
mysqldump-and-zip dance, and grew into a complete suite covering encryption,
cross-server transfer, per-extension picking, and automatic URL rewriting.

---

### ✨ Highlights

- **Single portable `.flarum` file** — custom streaming format (not `.wpress`,
  not zip), forward-only so multi-GB backups never need to fit in memory
- **Pick what to bundle** — database, `public/assets`, `storage`, and individual
  extensions, with a tag on each row showing whether it lives in `workbench/`
  or in `vendor/` (composer-managed)
- **`composer.json` + `composer.lock` travel along** — vendor extensions stay
  reproducible on the destination
- **Resumable, chunked progress** on both export and import (~4 MB per HTTP
  request), with live progress bars and an upload `%` indicator
- **Command-line export & import** — run a full backup or restore from
  `php flarum backup:export` / `backup:import`, with no `max_execution_time`
  or `memory_limit` worries and no browser tab to keep open; ideal for large
  forums, cron jobs and scripted server-to-server transfer
- **Optional asymmetric encryption** — libsodium hybrid scheme: sealed-box
  wraps a per-archive XChaCha20-Poly1305 stream key. Public key in the database,
  private key only in `config.php`
- **Cross-server transfer** — encrypt to a foreign public key, paste the
  matching private key at import time
- **Automatic URL rewriting** — the source URL is recorded in the archive
  header and rewritten across `settings`, `posts.content` and
  `posts.parsed_content` when restoring on a different host
- **Selectable restore** — per-section and per-extension checkboxes populated
  from the archive's manifest
- **Foreign-key-safe restore** — disables FK checks per tick so DDL referencing
  not-yet-created tables succeeds without ordering dance
- **Smart pruning** while scanning (`node_modules`, `.git`, `.idea`, nested
  `vendor/`…) so workbench scans stay seconds-fast
- **Dedicated "you've been logged out" screen** when a DB restore replaces the
  admin's session

---

### 🛠️ Technologies

- **PHP 8.1+** — resumable export / import jobs, libsodium crypto, MySQL dumper
- **TypeScript + Mithril** — admin panel UI
- **LESS** — styling (theme-aware via Flarum's CSS variables)
- **libsodium** — sealed-box + secretstream chunked encryption

---

### Installation

```sh
composer require ramon/backup
php flarum migrate
php flarum cache:clear
```

Then enable **Backup &amp; Migration** under the *Extensions* page in the admin
panel.

---

### 🖥️ Command-line interface (CLI)

Export and import are also available as console commands. A CLI run has no HTTP
request timeout, no `memory_limit` pressure from a web worker, and doesn't
depend on keeping a browser tab open — so the CLI is the most reliable way to
back up or migrate **large** forums, and the natural fit for cron jobs and
scripted server-to-server transfer. Under the hood it drives the exact same
engine as the admin panel, simply looped to completion in a single process.

#### Export — `backup:export`

```sh
# Database only, same engine as the source
php flarum backup:export --db

# Full backup: database + assets + storage + every extension
php flarum backup:export --all

# Database, retargeted to a different engine (cross-engine migration)
php flarum backup:export --db --target=postgres

# Pick specific extensions and also copy the finished archive elsewhere
php flarum backup:export --db --extensions=ramon/verified,fof/byobu -o /backups/forum.flarum

# Encrypt to a public key (e.g. preparing a transfer to another server)
php flarum backup:export --all --encrypt --public-key="BASE64_PUBLIC_KEY"
```

Options: `--db/--no-db` (default on), `--assets`, `--storage`,
`--extensions[=LIST]` (omit the value for **all** installed extensions),
`--all`, `--target=mysql|mariadb|postgres|sqlite` (defaults to the source
engine), `--encrypt`, `--public-key=…`, `-o, --output=PATH`.

#### Import — `backup:import`

```sh
# Restore everything in an archive (replaces current data)
php flarum backup:import /backups/forum.flarum --yes

# Restore only the database
php flarum backup:import /backups/forum.flarum --yes --db --no-assets --no-storage

# Decrypt an encrypted archive with the matching private key
php flarum backup:import /backups/forum.flarum --yes --private-key="BASE64_PRIVATE_KEY"
```

> ⚠️ A restore **replaces** the destination database and files, so
> `backup:import` refuses to run without the explicit `--yes` flag.

Options: `-y, --yes` (**required**), `--private-key=…`, `--db/--no-db`,
`--assets/--no-assets`, `--storage/--no-storage`, `--extensions[=LIST]`. With no
selection flags, the entire archive is restored.

A typical server-to-server migration:

```sh
# On the OLD server
php flarum backup:export --all --target=postgres -o /tmp/forum.flarum

# copy /tmp/forum.flarum to the NEW server, then there:
php flarum backup:import /tmp/forum.flarum --yes
```

---

### Links

- **GitHub:** [github.com/ram0ng1/backup](https://github.com/ram0ng1/backup)
- **Packagist:** [packagist.org/packages/ramon/backup](https://packagist.org/packages/ramon/backup)
- **Issues:** [github.com/ram0ng1/backup/issues](https://github.com/ram0ng1/backup/issues)
- **Donate:** [Stripe](https://donate.stripe.com/fZe5o66nebkf39S28a)

---

### License

[MIT](LICENSE)

---

**Built with ❤️ by [Ramon Guilherme](https://ramonguilherme.com.br)**

*A personal project focused on making it easier to back up, move and restore
Flarum communities — without leaving the admin panel.*
