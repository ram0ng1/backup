<?php

namespace Ramon\Backup\Api\Controller;

use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Job\ExportJob;
use Ramon\Backup\Job\JobState;
use Ramon\Backup\StoragePaths;

/**
 * POST /api/backup/exports/{id}/tick
 *
 * Drives an in-progress export forward by one tick (~4 MB of work).
 * Returns the current phase, message, and progress so the frontend can
 * draw its progress bar and decide whether to keep polling.
 */
class TickExportController implements RequestHandlerInterface
{
    use AdminOnlyController;

    public function __construct(
        protected ExportJob $job,
        protected StoragePaths $paths
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertCanManage($request);

        $id = (string) ($request->getQueryParams()['id'] ?? '');
        if (! preg_match('/^[a-f0-9]{16}$/', $id)) {
            throw new ValidationException(['id' => 'Invalid job id.']);
        }

        $stateFile = $this->paths->exportJobDir($id).DIRECTORY_SEPARATOR.'job.json';
        if (! is_file($stateFile)) {
            throw new ValidationException(['id' => 'Export job not found.']);
        }

        $state = JobState::load($stateFile);
        $this->job->runTick($state);

        return new JsonResponse([
            'phase'    => $state->get('phase'),
            'message'  => $state->get('message'),
            'progress' => $state->get('progress'),
            'result'   => $state->get('result'),
            // Lossy-translation notes raised by the introspector while
            // dumping the schema (unsupported types, generated columns,
            // etc.). Surfaced verbatim on the completion screen so the
            // admin sees what didn't survive cross-engine translation.
            'warnings' => $state->get('db_warnings', []),
        ]);
    }
}
