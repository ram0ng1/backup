<?php

namespace Ramon\Backup\Database\Schema;

/**
 * Engine-neutral column "shape" — what every emitter and introspector
 * agrees on. The native engine types are normalised into one of these
 * before generating target SQL.
 *
 * Names are deliberately MySQL-ish (the largest type vocabulary), with
 * a few extras (UUID, JSON) that map cleanly to other engines.
 */
enum ColumnType: string
{
    case BOOL       = 'bool';

    case TINYINT    = 'tinyint';
    case SMALLINT   = 'smallint';
    case INTEGER    = 'integer';
    case BIGINT     = 'bigint';

    case DECIMAL    = 'decimal';
    case FLOAT      = 'float';
    case DOUBLE     = 'double';

    case CHAR       = 'char';
    case VARCHAR    = 'varchar';
    case TEXT       = 'text';
    case MEDIUMTEXT = 'mediumtext';
    case LONGTEXT   = 'longtext';

    case BINARY     = 'binary';
    case VARBINARY  = 'varbinary';
    case BLOB       = 'blob';
    case MEDIUMBLOB = 'mediumblob';
    case LONGBLOB   = 'longblob';

    case DATE       = 'date';
    case TIME       = 'time';
    case DATETIME   = 'datetime';
    case TIMESTAMP  = 'timestamp';

    case JSON       = 'json';
    case ENUM       = 'enum';
    case UUID       = 'uuid';

    public function isInteger(): bool
    {
        return match ($this) {
            self::TINYINT, self::SMALLINT, self::INTEGER, self::BIGINT => true,
            default => false,
        };
    }

    public function isString(): bool
    {
        return match ($this) {
            self::CHAR, self::VARCHAR, self::TEXT, self::MEDIUMTEXT, self::LONGTEXT, self::ENUM, self::UUID => true,
            default => false,
        };
    }

    public function isBinary(): bool
    {
        return match ($this) {
            self::BINARY, self::VARBINARY, self::BLOB, self::MEDIUMBLOB, self::LONGBLOB => true,
            default => false,
        };
    }

    public function isTemporal(): bool
    {
        return match ($this) {
            self::DATE, self::TIME, self::DATETIME, self::TIMESTAMP => true,
            default => false,
        };
    }
}
