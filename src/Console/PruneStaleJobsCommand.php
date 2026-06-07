<?php

namespace Ramon\Backup\Console;

use Flarum\Console\AbstractCommand;
use Ramon\Backup\StoragePaths;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php flarum backup:prune-stale` — delete abandoned export/import
 * staging directories under `storage/backup-tmp`.
 *
 * Why this exists: a finished export deletes its `dump.sql` + manifest
 * but keeps the job dir for one extra tick so the browser can read the
 * terminal status; cleanup of the dir itself relies on the JS firing a
 * DELETE on dismiss. A job that errors out, or a tab closed mid-export,
 * never fires that DELETE — so the dir lingers, and with it a plaintext
 * `dump.sql` (the whole database, password hashes and emails included).
 * Registered on a daily schedule in `extend.php`; also runnable by hand.
 */
class PruneStaleJobsCommand extends AbstractCommand
{
    /** Default age threshold, in hours, before a staging dir is pruned. */
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
            // deleteDir refuses to touch anything outside backup-tmp/.
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
