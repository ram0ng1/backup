<?php

namespace Ramon\Backup\Console;

use Flarum\Console\AbstractCommand;
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\Job\JobState;
use Ramon\Backup\StoragePaths;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php flarum backup:import <archive>` — restore a `.flarum` archive
 * into the running install, to completion, in a single process.
 *
 * This is the destructive half of a transfer: every bundled table is
 * dropped and recreated, and bundled files overwrite their
 * destinations. Because of that we refuse to run without an explicit
 * `--yes`, the CLI equivalent of the "I understand this replaces my
 * data" checkbox in the admin UI.
 *
 * Like {@see ExportCommand}, the heavy lifting is delegated to the same
 * tick-based {@see ImportJob} the web flow uses; the CLI simply loops
 * `runTick()` with no HTTP timeout and no browser tab to keep alive,
 * which is why large or slow restores are "better handled" here.
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

        return 0;
    }

    /**
     * Build the import selection. When the user passes no selection
     * flags at all we return null, which ImportJob reads as "restore
     * everything in the archive". Passing any flag switches to an
     * explicit selection where unspecified sections default to false.
     */
    private function resolveSelection(): ?array
    {
        $touched = $this->input->hasParameterOption('--db')
            || $this->input->hasParameterOption('--no-db')
            || $this->input->hasParameterOption('--assets')
            || $this->input->hasParameterOption('--no-assets')
            || $this->input->hasParameterOption('--storage')
            || $this->input->hasParameterOption('--no-storage')
            || $this->input->hasParameterOption('--extensions');

        if (! $touched) {
            return null;
        }

        return [
            'db'         => (bool) $this->input->getOption('db'),
            'assets'     => (bool) $this->input->getOption('assets'),
            'storage'    => (bool) $this->input->getOption('storage'),
            'extensions' => $this->resolveExtensionSelection(),
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

    private function driveToCompletion(JobState $state): JobState
    {
        $lastMessage = null;
        while (! in_array($state->get('phase'), ['done', 'error'], true)) {
            $state = $this->job->runTick($state);
            $message = (string) $state->get('message');
            if ($message !== $lastMessage) {
                $this->output->writeln($message);
                $lastMessage = $message;
            }
        }
        return $state;
    }
}
