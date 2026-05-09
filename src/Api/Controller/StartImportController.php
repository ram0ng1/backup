<?php

namespace Ramon\Backup\Api\Controller;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Job\ImportJob;

/**
 * POST /api/backup/imports/{id}/start
 *
 * The user has reviewed the inspect result, optionally pasted a private
 * key (for encrypted archives whose key isn't in our config.php), and
 * checked the "I understand this will replace my data" box. We create
 * the persisted job state and let the tick endpoint take over.
 */
class StartImportController implements RequestHandlerInterface
{
    use AdminOnlyController;

    public function __construct(
        protected ImportJob $job
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertCanManage($request);
        $actor = RequestUtil::getActor($request);

        $id = (string) ($request->getQueryParams()['id'] ?? '');
        if (! preg_match('/^[a-f0-9]{16}$/', $id)) {
            throw new ValidationException(['id' => 'Invalid job id.']);
        }

        $body = (array) $request->getParsedBody();
        $privateKey = isset($body['private_key']) ? trim((string) $body['private_key']) : '';
        $confirmReplace = ! empty($body['confirm_replace']);

        if (! $confirmReplace) {
            throw new ValidationException([
                'confirm_replace' => 'You must confirm that this will replace existing data.',
            ]);
        }

        $state = $this->job->start($id, $privateKey ?: null, $confirmReplace, (int) $actor->id ?: null);

        return new JsonResponse([
            'job_id'  => $id,
            'phase'   => $state->get('phase'),
            'message' => $state->get('message'),
        ]);
    }
}
