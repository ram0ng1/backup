<?php

namespace Ramon\Backup\Console;

use Flarum\Console\AbstractCommand;
use Ramon\Backup\Job\ExportJob;
use Ramon\Backup\Job\JobState;
use Ramon\Backup\StoragePaths;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php flarum backup:export` — run a full export to completion in a
 * single process.
 *
 * Why a CLI path exists at all when the admin UI already does exports:
 * the web flow is deliberately chopped into ~4 MB HTTP "ticks" so a
 * shared-host PHP worker never blows its `max_execution_time` or
 * `memory_limit` mid-backup, and it depends on the browser tab staying
 * open to keep firing ticks. A console invocation has neither
 * constraint — there is no request timeout and no tab to lose — so we
 * just drive `ExportJob::runTick()` in a loop until the job reports
 * `done`. The per-tick byte budget still bounds peak memory, which is
 * why we reuse the exact same job engine rather than writing a second,
 * unbounded dumper.
 */
class ExportCommand extends AbstractCommand
{
    public function __construct(
        protected ExportJob $job,
        protected StoragePaths $paths
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('backup:export')
            ->setDescription('Create a backup/export archive (database, files, extensions) from the command line.')
            ->addOption('db', null, InputOption::VALUE_NEGATABLE, 'Include the database dump.', true)
            ->addOption('assets', null, InputOption::VALUE_NONE, 'Include public/assets.')
            ->addOption('storage', null, InputOption::VALUE_NONE, 'Include storage/ (uploads, avatars).')
            ->addOption(
                'extensions',
                null,
                InputOption::VALUE_OPTIONAL,
                'Bundle extensions. Omit the value for ALL installed extensions, or pass a comma-separated list of ids.',
                false
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Shortcut for --db --assets --storage --extensions.')
            ->addOption(
                'target',
                null,
                InputOption::VALUE_REQUIRED,
                'Target engine the dump should be generated for (mysql, mariadb, postgres, sqlite). Defaults to the source engine.'
            )
            ->addOption('encrypt', null, InputOption::VALUE_NONE, 'Encrypt the archive (requires a configured or supplied public key).')
            ->addOption('public-key', null, InputOption::VALUE_REQUIRED, 'Base64 public key to encrypt to (overrides the configured one).')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Copy the finished archive to this path as well.');
    }

    protected function fire(): int
    {
        $all = (bool) $this->input->getOption('all');

        $extensions = $this->resolveExtensionSelection($all);

        $contents = [
            'db'         => $all ? true : (bool) $this->input->getOption('db'),
            'assets'     => $all || (bool) $this->input->getOption('assets'),
            'storage'    => $all || (bool) $this->input->getOption('storage'),
            'extensions' => $extensions,
        ];

        $encrypt = (bool) $this->input->getOption('encrypt');
        $publicKey = $this->input->getOption('public-key');
        $encryption = [
            'enabled'    => $encrypt,
            'public_key' => is_string($publicKey) ? $publicKey : null,
        ];

        $target = $this->input->getOption('target');
        $target = is_string($target) && $target !== '' ? $target : null;

        $jobId = bin2hex(random_bytes(8));

        try {
            $state = $this->job->start($jobId, $contents, $encryption, $target, null);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $state = $this->driveToCompletion($state);

        if ($state->get('phase') === 'error') {
            $this->error((string) $state->get('message'));
            return 1;
        }

        foreach ((array) $state->get('db_warnings', []) as $warning) {
            $this->output->writeln('<comment>warning:</comment> '.$warning);
        }

        $result = (array) $state->get('result', []);
        $filename = (string) ($result['filename'] ?? '');
        $finalPath = $filename !== ''
            ? $this->paths->backupsDir().DIRECTORY_SEPARATOR.$filename
            : '';

        if ($finalPath !== '') {
            $this->info('Backup created: '.$finalPath.' ('.$this->humanBytes((int) ($result['size'] ?? 0)).')');

            $output = $this->input->getOption('output');
            if (is_string($output) && $output !== '') {
                if (@copy($finalPath, $output)) {
                    $this->info('Copied to: '.$output);
                } else {
                    $this->error('Could not copy archive to: '.$output);
                    return 1;
                }
            }
        }

        return 0;
    }

    /**
     * Translate the --extensions / --all flags into the permissive
     * shape ExportJob expects: false (none), true (all), or a list of ids.
     */
    private function resolveExtensionSelection(bool $all): bool|array
    {
        if ($all) {
            return true;
        }

        if (! $this->input->hasParameterOption('--extensions')) {
            return false;
        }

        $value = $this->input->getOption('extensions');
        // `--extensions` with no value → all; `--extensions=a,b` → list.
        if ($value === null || $value === '') {
            return true;
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    /**
     * Pump runTick() to completion while rendering a single, in-place
     * status line: a spinner (proves liveness during the long DB-dump
     * phase, where the byte-percent stays pinned), the percent, and the
     * job message — which now carries the per-table row counter, so a
     * huge table visibly advances "1,200,000/3,400,000 rows" instead of
     * looking frozen. Symfony's ProgressBar throttles itself on
     * non-decorated output (piped to a file), so this doesn't spam logs.
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

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return round($n, 2).' '.$units[$i];
    }
}
