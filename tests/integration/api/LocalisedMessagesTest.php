<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Tests\integration\api;

use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramon\Backup\Job\ExportJob;
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\Locale\Messages;
use Ramon\Backup\Project\ProjectReconciler;
use ReflectionProperty;

/**
 * O que os jobs escrevem no job.json chega ao painel como está: tem de sair
 * no idioma do fórum, e não no texto de reserva em inglês.
 *
 * Com `?Messages $messages = null` o container do Laravel passava null (a
 * classe não é registrada) e tudo caía no inglês, até num fórum em pt-BR.
 * O texto em si é coberto em MessagesTest; aqui vale a fiação.
 */
class LocalisedMessagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-backup');
    }

    /** @return array<string, array{class-string}> */
    public static function services(): array
    {
        return [
            'export'     => [ExportJob::class],
            'import'     => [ImportJob::class],
            'reconciler' => [ProjectReconciler::class],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('services')]
    public function test_container_built_services_use_the_flarum_translator(string $class): void
    {
        $container = $this->app()->getContainer();
        $service   = $container->make($class);

        $messages = (new ReflectionProperty($service, 'messages'))->getValue($service);
        $this->assertInstanceOf(Messages::class, $messages);

        $translator = (new ReflectionProperty($messages, 'translator'))->getValue($messages);
        $this->assertSame($container->make('translator'), $translator);
    }
}
