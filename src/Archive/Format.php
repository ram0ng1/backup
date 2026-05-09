<?php

namespace Ramon\Backup\Archive;

/**
 * On-disk layout of a `.flarum` backup file.
 *
 * Header (always plaintext):
 *   [8 bytes] MAGIC               "FLARUM01"
 *   [1 byte ] FLAGS               bit 0 = encrypted
 *   [4 bytes] META_LENGTH (big-endian, unsigned int)
 *   [N bytes] META_JSON           UTF-8 JSON metadata, never secret
 *
 * If FLAG_ENCRYPTED is set, the body that follows is:
 *   [80 bytes] WRAPPED_KEY        sealed_box(public_key, K) — fixed size
 *   [24 bytes] STREAM_HEADER      libsodium secretstream header
 *   [chunks…] each chunk =
 *       [4 bytes BE] CIPHERTEXT_LENGTH
 *       [N bytes  ] CIPHERTEXT (each chunk decrypts to plaintext entries)
 *
 * If unencrypted, the body is the entry stream directly.
 *
 * Entry stream format (the "inner archive" — same shape encrypted or not):
 *   For each entry:
 *     [4 bytes BE] NAME_LENGTH    0 marks end of stream
 *     [N bytes  ] NAME            UTF-8 logical path, e.g. "db.sql" or
 *                                 "assets/avatars/123.png"
 *     [1 byte   ] TYPE            see TYPE_* constants
 *     [8 bytes BE] DATA_LENGTH
 *     [N bytes  ] DATA
 *
 * Why a custom format instead of zip/tar:
 *   - Forward streaming (no central directory) means we can write while
 *     resuming across multiple HTTP ticks without seeking back.
 *   - One byte per entry can flag whether the payload is a SQL dump or a
 *     file, so the importer doesn't need filename heuristics.
 *   - Encryption wraps the whole stream as one secretstream — chunk
 *     boundaries don't have to align with entry boundaries.
 */
final class Format
{
    public const MAGIC = "FLARUM01";

    public const FLAG_ENCRYPTED = 0x01;

    public const TYPE_FILE        = 0;
    public const TYPE_DB_DUMP     = 1;

    /** Bytes flushed to the encrypted stream per push. */
    public const CHUNK_SIZE = 262144; // 256 KB

    /** Cap on the JSON metadata length, just to keep parsers honest. */
    public const MAX_META_BYTES = 65536;

    /** Logical filename of the SQL dump inside the entry stream. */
    public const DB_ENTRY_NAME = 'database.sql';

    public static function fileExtension(): string
    {
        return '.flarum';
    }
}
