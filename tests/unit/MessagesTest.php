<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Ramon\Backup\Tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Environment\StackSnapshot;
use Ramon\Backup\Locale\Messages;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

/**
 * O texto que a API devolve (progresso, avisos, erros) segue o idioma do
 * fórum, como o resto do painel.
 *
 * O tradutor aqui é montado como o do Flarum: domínio `messages+intl-icu`,
 * então um plural mal escrito no yml quebra este teste, não o painel.
 */
final class MessagesTest extends TestCase
{
    private const LOCALES = ['en', 'pt-BR'];

    /** Valores de exemplo para todo placeholder usado em `api.*`. */
    private const PARAMS = [
        'message' => 'x', 'items' => 'a, b', 'current' => 2, 'total' => 5, 'table' => 'posts',
        'rows' => '10 rows', 'done' => 1234, 'file' => 'assets/a.png', 'count' => 2,
        'percent' => 12.5, 'from' => 'http://a', 'to' => 'http://b', 'error' => 'boom',
        'key' => 'k', 'classes' => '`A`', 'paths' => '/x', 'user' => 'www-data', 'sample' => '',
        'dir' => '/x', 'packages' => 'a/b', 'source' => '8.4', 'here' => '8.3',
        'extensions' => 'pdo', 'size' => 10, 'max' => 5, 'received' => 1, 'expected' => 2,
        'offset' => 1048576,
    ];

    private static function translator(string $locale): Translator
    {
        $translator = new Translator($locale);
        $translator->addLoader('array', new ArrayLoader());

        foreach (self::LOCALES as $l) {
            $tree = Yaml::parseFile(dirname(__DIR__, 2)."/locale/$l.yml");
            $translator->addResource('array', $tree, $l, 'messages'.MessageCatalogueInterface::INTL_DOMAIN_SUFFIX);
        }

        return $translator;
    }

    /** @return list<string> chaves `api.*` do en.yml, sem o prefixo */
    private static function apiKeys(): array
    {
        $tree = Yaml::parseFile(dirname(__DIR__, 2).'/locale/en.yml');
        $keys = [];
        $walk = function (array $node, string $prefix) use (&$walk, &$keys): void {
            foreach ($node as $k => $v) {
                is_array($v) ? $walk($v, $prefix.$k.'.') : $keys[] = $prefix.$k;
            }
        };
        $walk($tree['ramon-backup']['api'], '');

        return $keys;
    }

    /** @return array<string, array{string}> */
    public static function locales(): array
    {
        return array_combine(self::LOCALES, array_map(fn ($l) => [$l], self::LOCALES));
    }

    #[DataProvider('locales')]
    public function test_every_api_message_formats_in_the_locale(string $locale): void
    {
        $messages = new Messages(self::translator($locale));

        foreach (self::apiKeys() as $key) {
            $text = $messages->get($key, self::PARAMS);
            $this->assertNotSame(Messages::PREFIX.$key, $text, "$locale: $key não traduziu");
            $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $text, "$locale: $key ficou com placeholder cru");
        }
    }

    public function test_english_forum_gets_english_and_portuguese_forum_gets_portuguese(): void
    {
        $en = new Messages(self::translator('en'));
        $pt = new Messages(self::translator('pt-BR'));

        $this->assertSame('Restore finished with 1 unrestored entry.', $en->get('import.finished_unresolved', ['count' => 1]));
        $this->assertSame('Restore finished with 3 unrestored entries.', $en->get('import.finished_unresolved', ['count' => 3]));
        $this->assertSame('Restauração terminada com 3 entradas não restauradas.', $pt->get('import.finished_unresolved', ['count' => 3]));

        $this->assertStringStartsWith('No write permission on /srv', $en->get('import.unwritable_destination', ['paths' => '/srv', 'user' => 'www']));
        $this->assertStringStartsWith('Sem permissão de escrita em /srv', $pt->get('import.unwritable_destination', ['paths' => '/srv', 'user' => 'www']));
    }

    public function test_without_a_translator_falls_back_to_english(): void
    {
        $messages = new Messages();

        $this->assertSame('Backup complete.', $messages->get('export.complete'));
        $this->assertSame('Scanning… (2/5)', $messages->get('export.scanning', ['current' => 2, 'total' => 5]));
        $this->assertSame(Messages::PREFIX.'nope', $messages->get('nope'));
    }

    public function test_stack_reasons_follow_the_locale(): void
    {
        $meta = [StackSnapshot::META_KEY => ['php_version' => PHP_VERSION, 'php_extensions' => ['pdo', 'json']]];

        $en = StackSnapshot::blockingReason($meta, ['json'], new Messages(self::translator('en')));
        $pt = StackSnapshot::blockingReason($meta, ['json'], new Messages(self::translator('pt-BR')));

        $this->assertNotNull($en);
        $this->assertNotNull($pt);
        $this->assertStringStartsWith('Cannot continue: this server is missing a required PHP extension', $en);
        $this->assertStringStartsWith('Não é possível continuar: este servidor não tem uma extensão PHP obrigatória', $pt);
    }
}
