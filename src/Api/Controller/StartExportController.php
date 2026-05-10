<?php

namespace Ramon\Backup\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Job\ExportJob;

/**
 * POST /api/backup/exports
 *
 * Body:
 *   contents:        { db, assets, storage, extensions } (booleans)
 *   encryption:      { enabled, public_key? }
 *   target_dialect:  optional string ("mysql", "mariadb", "postgres",
 *                    "sqlite") — engine the dump should target. Omit
 *                    or leave blank for a same-engine backup.
 *
 * Creates a new job state file and returns the job id. The frontend
 * then polls /tick to make progress.
 */
class StartExportController implements RequestHandlerInterface
{
    use AdminOnlyController;

    public function __construct(
        protected ExportJob $job
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertCanManage($request);
        $actor = RequestUtil::getActor($request);

        $body = (array) $request->getParsedBody();
        $contents = is_array($body['contents'] ?? null) ? $body['contents'] : [];
        $encryption = is_array($body['encryption'] ?? null) ? $body['encryption'] : ['enabled' => false];
        $targetDialect = isset($body['target_dialect']) && is_string($body['target_dialect'])
            ? trim($body['target_dialect'])
            : null;
        if ($targetDialect === '') $targetDialect = null;

        // `contents.extensions` is permissive on purpose:
        //   true     → bundle every installed extension
        //   false    → bundle none
        //   string[] → bundle these specific extension ids
        $extensions = $contents['extensions'] ?? false;
        if (is_array($extensions)) {
            $extensions = array_values(array_filter(array_map(
                fn ($v) => is_string($v) ? $v : null,
                $extensions
            )));
        } else {
            $extensions = (bool) $extensions;
        }

        $jobId = $this->generateJobId();

        $state = $this->job->start(
            $jobId,
            [
                'db'         => ! empty($contents['db']),
                'assets'     => ! empty($contents['assets']),
                'storage'    => ! empty($contents['storage']),
                'extensions' => $extensions,
            ],
            [
                'enabled'    => ! empty($encryption['enabled']),
                'public_key' => isset($encryption['public_key']) ? (string) $encryption['public_key'] : null,
            ],
            $targetDialect,
            (int) $actor->id ?: null
        );

        return new JsonResponse([
            'job_id' => $jobId,
            'phase'  => $state->get('phase'),
            'message'=> $state->get('message'),
        ]);
    }

    private function generateJobId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
