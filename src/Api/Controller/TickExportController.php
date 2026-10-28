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
 * Avança a exportação um tick (~4 MB de trabalho) e devolve fase e
 * progresso para o frontend decidir se continua.
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
            // O que se perdeu na tradução entre bancos (tipos sem
            // equivalente, colunas geradas); mostrado ao admin no fim.
            'warnings' => $state->get('db_warnings', []),
        ]);
    }
}
