# 📦 Backup &amp; Migration — Portable Backups for Flarum

**A complete backup, export and import system for Flarum 2.x**

![Backup admin dashboard](https://cdn.ramonguilherme.com.br/downloads/backup/admin-dashboard.png)

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

> 🚧 **Active development** — first stable release coming soon!

---

### 📸 Screenshots

**Admin Dashboard**
![Admin dashboard](https://cdn.ramonguilherme.com.br/downloads/backup/admin-dashboard.png)

**Creating a Backup**
![Create backup modal](https://cdn.ramonguilherme.com.br/downloads/backup/create-backup-modal.png)

**Restoring a Backup — Upload &amp; Inspect**
![Restore upload step](https://cdn.ramonguilherme.com.br/downloads/backup/restore-backup-modal.png)

**Selecting What to Restore**
![Restore selection](https://cdn.ramonguilherme.com.br/downloads/backup/backup-modal-restore.png)

**Restore Complete — Login Required**
![Logged out completion screen](https://cdn.ramonguilherme.com.br/downloads/backup/backup-modal-done.png)

---

### ✨ Highlights

- **Single portable `.flarum` file**
- **Pick what to bundle** — database, `public/assets`, `storage`, and individual
  extensions, with a tag on each row showing whether it lives in `workbench/`
  or in `vendor/` (composer-managed)
- **`composer.json` + `composer.lock` travel along** — vendor extensions stay
  reproducible on the destination
- **Resumable, chunked progress** on both export and import (~4 MB per HTTP
  request), with live progress bars and an upload `%` indicator
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

### Links

- **GitHub:** [github.com/ram0ng1/backup](https://github.com/ram0ng1/backup)
- **Issues:** [github.com/ram0ng1/backup/issues](https://github.com/ram0ng1/backup/issues)
- **Donate:** [Stripe](https://donate.stripe.com/fZe5o66nebkf39S28a)

---

### License

[MIT](LICENSE)

---

**Built with ❤️ by [Ramon Guilherme](https://ramonguilherme.com.br)**

*A personal project focused on making it easier to back up, move and restore
Flarum communities — without leaving the admin panel.*
