<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Archive;

/**
 * Layout em disco de um backup `.flarum`.
 *
 * Cabeçalho (sempre em claro):
 *   [8 bytes] MAGIC               "FLARUM01"
 *   [1 byte ] FLAGS               bit 0 = cifrado
 *   [4 bytes] META_LENGTH (big-endian, unsigned int)
 *   [N bytes] META_JSON           metadados UTF-8, nunca sigilosos
 *
 * Com FLAG_ENCRYPTED, o corpo é:
 *   [80 bytes] WRAPPED_KEY        sealed_box(public_key, K), tamanho fixo
 *   [24 bytes] STREAM_HEADER      header do secretstream (libsodium)
 *   [chunks…] cada chunk =
 *       [4 bytes BE] CIPHERTEXT_LENGTH
 *       [N bytes  ] CIPHERTEXT
 *
 * Sem cifra, o corpo é o próprio stream de entradas.
 *
 * Stream de entradas (igual com ou sem cifra), para cada entrada:
 *     [4 bytes BE] NAME_LENGTH    0 marca o fim
 *     [N bytes  ] NAME            caminho lógico UTF-8, ex. "db.sql" ou
 *                                 "assets/avatars/123.png"
 *     [1 byte   ] TYPE            ver constantes TYPE_*
 *     [8 bytes BE] DATA_LENGTH
 *     [N bytes  ] DATA
 *
 * Formato próprio em vez de zip/tar porque, sem diretório central, dá para
 * escrever em sequência ao longo de vários ticks sem voltar no arquivo, e
 * os chunks da cifra não precisam coincidir com as entradas.
 */
final class Format
{
    public const MAGIC = "FLARUM01";

    public const FLAG_ENCRYPTED = 0x01;

    public const TYPE_FILE        = 0;
    public const TYPE_DB_DUMP     = 1;

    /** Bytes por push no stream cifrado (256 KB). */
    public const CHUNK_SIZE = 262144;

    /** Teto do JSON de metadados, para o parser não ler qualquer coisa. */
    public const MAX_META_BYTES = 65536;

    /** Nome lógico do dump SQL no stream de entradas. */
    public const DB_ENTRY_NAME = 'database.sql';

    public static function fileExtension(): string
    {
        return '.flarum';
    }
}
