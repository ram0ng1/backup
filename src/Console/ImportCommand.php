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
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\Job\JobState;
use Ramon\Backup\StoragePaths;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php flarum backup:import <arquivo>` — restaura um `.flarum` num só processo.
 *
 * Destrutivo: tabelas do arquivo são recriadas e arquivos sobrescritos, por
 * isso exige `--yes` (o equivalente da confirmação no painel). Usa o mesmo
 * {@see ImportJob} da web em loop de `runTick()`.
 */
class ImportCommand extends AbstractCommand
{
    public function __construct(
        protected ImportJob $job,
        protected StoragePaths $paths
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('backup:import')
            ->setDescription('Restore a .flarum backup archive into this install (replaces existing data).')
            ->addArgument('archive', InputArgument::REQUIRED, 'Path to the .flarum archive to restore.')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Confirm that this will REPLACE existing data (required).')
            ->addOption('private-key', null, InputOption::VALUE_REQUIRED, 'Base64 private key to decrypt an encrypted archive.')
            ->addOption('db', null, InputOption::VALUE_NEGATABLE, 'Restore the database dump.')
            ->addOption('assets', null, InputOption::VALUE_NEGATABLE, 'Restore public/assets.')
            ->addOption('storage', null, InputOption::VALUE_NEGATABLE, 'Restore storage/.')
            ->addOption(
                'extensions',
                null,
                InputOption::VALUE_OPTIONAL,
                'Restore extensions. Omit the value for ALL, or pass a comma-separated list of ids.',
                false
            )
            ->addOption(
                'root-extend',
                null,
                InputOption::VALUE_NONE,
                'Replace this install\'s root extend.php with the backup\'s (off by default — it is loaded before Flarum\'s error handler).'
            )
            ->addOption(
                'preserve-settings',
                null,
                InputOption::VALUE_NEGATABLE,
                'Keep this server\'s mail / queue / integration settings across the restore.',
                true
            );
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('yes')) {
            $this->error('Refusing to import without --yes: a restore REPLACES the current database and files.');
            return 1;
        }

        $archive = (string) $this->input->getArgument('archive');
        if (! is_file($archive)) {
            $this->error('Archive not found: '.$archive);
            return 1;
        }

        $jobId = bin2hex(random_bytes(8));
        $dir = $this->paths->importJobDir($jobId);
        $staged = $dir.DIRECTORY_SEPARATOR.'upload.flarum';

        if (! @copy($archive, $staged)) {
            $this->error('Could not stage archive into the import directory.');
            return 1;
        }

        $privateKey = $this->input->getOption('private-key');
        $selection = $this->resolveSelection();

        try {
            $state = $this->job->start(
                $jobId,
                is_string($privateKey) && $privateKey !== '' ? $privateKey : null,
                true,
                $selection,
                null
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $state = $this->driveToCompletion($state);

        if ($state->get('phase') === 'error') {
            $this->error((string) $state->get('message'));
            return 1;
        }

        $progress = (array) $state->get('progress', []);
        $this->info(sprintf(
            'Restore complete. %d entries extracted, %d SQL statements applied.',
            (int) ($progress['extracted_entries'] ?? 0),
            (int) ($progress['restored_statements'] ?? 0)
        ));

        $rewrite = $state->get('rewrite_stats');
        if (is_array($rewrite)) {
            $this->info('URL rewrite — settings: '.((int) ($rewrite['settings'] ?? 0))
                .', posts content: '.((int) ($rewrite['posts_content'] ?? 0))
                .', posts parsed: '.((int) ($rewrite['posts_parsed'] ?? 0)));
        }

        return $this->reportOutcome($state);
    }

    /**
     * Restore `incomplete` sai com 1: o operador precisa agir antes de
     * recarregar o fórum, e sair 0 deixaria um script de deploy seguir
     * rumo a um HTTP 500.
     */
    private function reportOutcome(JobState $state): int
    {
        foreach ((array) $state->get('warnings', []) as $warning) {
            $this->output->writeln('<comment>! '.(string) $warning.'</comment>');
        }

        if (! $state->get('incomplete', false)) {
            return 0;
        }

        $this->error('Restore finished INCOMPLETE — review the warnings above before reloading the forum.');
        return 1;
    }

    /**
     * Sem nenhuma flag de seleção devolve null (restaura tudo); com qualquer
     * flag, o que não foi pedido fica de fora.
     */
    private function resolveSelection(): ?array
    {
        $touched = $this->input->hasParameterOption('--db')
            || $this->input->hasParameterOption('--no-db')
            || $this->input->hasParameterOption('--assets')
            || $this->input->hasParameterOption('--no-assets')
            || $this->input->hasParameterOption('--storage')
            || $this->input->hasParameterOption('--no-storage')
            || $this->input->hasParameterOption('--extensions')
            || $this->input->hasParameterOption('--root-extend')
            || $this->input->hasParameterOption('--no-preserve-settings');

        if (! $touched) {
            return null;
        }

        return [
            'db'         => (bool) $this->input->getOption('db'),
            'assets'     => (bool) $this->input->getOption('assets'),
            'storage'    => (bool) $this->input->getOption('storage'),
            'extensions' => $this->resolveExtensionSelection(),
            /*
             * Assimétricos de propósito: sobrescrever o extend.php da raiz
             * exige pedir, preservar a config deste servidor exige recusar.
             */
            'root_extend'       => (bool) $this->input->getOption('root-extend'),
            'preserve_settings' => (bool) $this->input->getOption('preserve-settings'),
        ];
    }

    private function resolveExtensionSelection(): bool|array
    {
        if (! $this->input->hasParameterOption('--extensions')) {
            return false;
        }

        $value = $this->input->getOption('extensions');
        if ($value === null || $value === '') {
            return true;
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    /**
     * Roda os ticks até o fim com a mesma linha de status do {@see ExportCommand}.
     */
    private function driveToCompletion(JobState $state): JobState
    {
        $frames = ['-', '\\', '|', '/'];
        $tick = 0;
        ProgressBar::setPlaceholderFormatterDefinition(
            'spinner',
            function () use (&$tick, $frames) {
                return $frames[$tick % count($frames)];
            }
        );

        $bar = new ProgressBar($this->output, 100);
        $bar->setFormat(' %spinner% %percent:3s%% — %message%');
        $bar->setMessage('Preparing…');
        $bar->start();

        try {
            while (! in_array($state->get('phase'), ['done', 'error'], true)) {
                $state = $this->job->runTick($state);
                $tick++;
                $progress = (array) $state->get('progress', []);
                $percent = (int) round((float) ($progress['percent'] ?? 0));
                $bar->setMessage((string) $state->get('message'));
                $bar->setProgress(min(100, max(0, $percent)));
                $bar->display();
            }

            if ($state->get('phase') === 'done') {
                $bar->setProgress(100);
            }
            $bar->finish();
        } finally {
            $this->output->writeln('');
        }

        return $state;
    }
}
