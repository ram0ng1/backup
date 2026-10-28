<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Api\Controller;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\Locale\Messages;

/**
 * POST /api/backup/imports/{id}/start
 *
 * Cria o estado do job depois que o admin confirmou a substituição dos
 * dados (e, se preciso, colou a chave privada); o tick assume dali.
 */
class StartImportController implements RequestHandlerInterface
{
    use AdminOnlyController;

    public function __construct(
        protected ImportJob $job,
        protected Messages $messages
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertAdmin($request);
        $actor = RequestUtil::getActor($request);

        $id = (string) ($request->getQueryParams()['id'] ?? '');
        if (! preg_match('/^[a-f0-9]{16}$/', $id)) {
            throw new ValidationException(['id' => $this->messages->get('errors.invalid_job')]);
        }

        $body = (array) $request->getParsedBody();
        $privateKey = isset($body['private_key']) ? trim((string) $body['private_key']) : '';
        $confirmReplace = ! empty($body['confirm_replace']);

        if (! $confirmReplace) {
            throw new ValidationException([
                'confirm_replace' => $this->messages->get('errors.confirm_replace'),
            ]);
        }

        // Sem seleção, restaura tudo (clientes antigos não a enviam).
        $selection = is_array($body['selection'] ?? null) ? $body['selection'] : null;

        $state = $this->job->start(
            $id,
            $privateKey ?: null,
            $confirmReplace,
            $selection,
            (int) $actor->id ?: null
        );

        return new JsonResponse([
            'job_id'  => $id,
            'phase'   => $state->get('phase'),
            'message' => $state->get('message'),
        ]);
    }
}
