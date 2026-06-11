<p align="center">
  <img src="icon.svg" width="80" height="80" alt="Backup &amp; Migration">
</p>

<h1 align="center">Backup &amp; Migration</h1>

<p align="center">
  <a href="https://github.com/ram0ng1/backup/actions/workflows/ci.yml"><img alt="CI" src="https://img.shields.io/github/actions/workflow/status/ram0ng1/backup/ci.yml?branch=main&style=flat-square&label=ci"></a>
  <a href="https://packagist.org/packages/ramon/backup"><img alt="Packagist" src="https://img.shields.io/packagist/v/ramon/backup?style=flat-square&label=packagist"></a>
  <a href="https://packagist.org/packages/ramon/backup"><img alt="Downloads" src="https://img.shields.io/packagist/dt/ramon/backup?style=flat-square"></a>
  <img alt="Flarum" src="https://img.shields.io/badge/flarum-2.x-e7672e?style=flat-square">
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-MIT-blue?style=flat-square"></a>
  <a href="https://donate.stripe.com/fZe5o66nebkf39S28a"><img alt="Donate" src="https://img.shields.io/badge/donate-stripe-6772E5?style=flat-square"></a>
</p>

<p align="center">Full backups and one click migration for Flarum 2.</p>

Backup &amp; Migration packs your whole forum into a single portable `.flarum` file: database, uploads, storage and extensions. Restore it on the same install or on a brand new server and keep going.

I wrote it after one too many rounds of the mysqldump and zip dance while moving forums between hosts. It ended up becoming something close to what All-in-One WP Migration is for WordPress, just built natively for Flarum.

## What it does

- Exports everything into one streaming `.flarum` file, so multi GB forums never need to fit in memory
- Lets you pick what goes in: database, assets, storage and individual extensions
- Restores with per section and per extension checkboxes, resumable in chunks
- Migrates between database engines: export from MySQL, restore on PostgreSQL, MariaDB or SQLite
- Rewrites the forum URL automatically when restoring on a different host
- Encrypts archives with libsodium when you ask it to, including transfers to another server's public key
- Ships `composer.json` and `composer.lock` inside the archive, so vendor extensions stay reproducible

## Installation

```sh
composer require ramon/backup
php flarum migrate
php flarum cache:clear
```

Then enable Backup &amp; Migration on the Extensions page of the admin panel.

## Command line

The same engine runs as console commands, with no HTTP timeout and no browser tab to babysit. Best route for large forums and cron jobs.

```sh
php flarum backup:export --all                      # everything
php flarum backup:export --db --target=postgres     # database only, retargeted to another engine
php flarum backup:import /backups/forum.flarum --yes
```

A restore replaces the destination data, so `backup:import` refuses to run without `--yes`. Use `--help` on either command for the full list of flags.

## About encryption

Encrypted archives use a libsodium sealed box wrapping a per archive stream key. The public key lives in the database, the private key only in `config.php`. Keep that private key safe: without it an encrypted archive cannot be opened.

## License

[MIT](LICENSE). Found a bug or have an idea? [Open an issue](https://github.com/ram0ng1/backup/issues).
