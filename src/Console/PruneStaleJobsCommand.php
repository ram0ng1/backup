<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Console;

use Flarum\Console\AbstractCommand;
use Ramon\Backup\StoragePaths;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php flarum backup:prune-stale` — apaga staging abandonado em
 * `storage/backup-tmp`.
 *
 * Job com erro ou aba fechada no meio nunca dispara o DELETE do painel, e
 * o diretório fica com um `dump.sql` em texto puro. Agendado diariamente
 * no `extend.php`.
 */
class PruneStaleJobsCommand extends AbstractCommand
{
    /** Idade padrão, em horas, para considerar o staging abandonado. */
    private const DEFAULT_HOURS = 24;

    public function __construct(
        protected StoragePaths $paths
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('backup:prune-stale')
            ->setDescription('Delete abandoned export/import staging directories under storage/backup-tmp.')
            ->addOption(
                'hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Age threshold in hours; dirs untouched for longer are deleted.',
                (string) self::DEFAULT_HOURS
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be deleted without deleting it.');
    }

    protected function fire(): int
    {
        $hours  = max(1, (int) $this->input->getOption('hours'));
        $dryRun = (bool) $this->input->getOption('dry-run');

        $stale = $this->paths->staleJobDirs($hours * 3600);

        if ($stale === []) {
            $this->info('No stale staging directories older than '.$hours.'h.');
            return 0;
        }

        $deleted = 0;
        foreach ($stale as $dir) {
            if ($dryRun) {
                $this->output->writeln('<comment>would delete:</comment> '.$dir);
                continue;
            }
            $this->paths->deleteDir($dir);
            $this->output->writeln('<info>deleted:</info> '.$dir);
            $deleted++;
        }

        $this->info($dryRun
            ? count($stale).' staging director(ies) would be pruned (older than '.$hours.'h).'
            : $deleted.' staging director(ies) pruned (older than '.$hours.'h).'
        );

        return 0;
    }
}
