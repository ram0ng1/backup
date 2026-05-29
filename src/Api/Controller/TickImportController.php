<?php

namespace Ramon\Backup\Api\Controller;

use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\Job\JobState;
use Ramon\Backup\StoragePaths;

/**
 * POST /api/backup/imports/{id}/tick
 *
 * Drives an in-progress restore one tick forward. Same pattern as the
 * export tick endpoint.
 */
class TickImportController implements RequestHandlerInterface
{
    use AdminOnlyController;

    public function __construct(
        protected ImportJob $job,
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

        $stateFile = $this->paths->importJobDir($id).DIRECTORY_SEPARATOR.'job.json';
        if (! is_file($stateFile)) {
            throw new ValidationException(['id' => 'Import job not found.']);
        }

        // The decryption private key is never persisted to disk; the
        // client re-sends it on each tick (over HTTPS) so the inspect /
        // extract phases can decrypt without a plaintext copy ever
        // touching storage/. Absent (unencrypted archive) → no-op.
        $body = (array) $request->getParsedBody();
        $privateKey = isset($body['private_key']) ? trim((string) $body['private_key']) : '';
        $this->job->withPrivateKey($privateKey !== '' ? $privateKey : null);

        $state = JobState::load($stateFile);
        $this->job->runTick($state);

        return new JsonResponse([
            'phase'    => $state->get('phase'),
            'message'  => $state->get('message'),
            'progress' => $state->get('progress'),
        ]);
    }
}
