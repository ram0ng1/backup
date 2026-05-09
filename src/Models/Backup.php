<?php

namespace Ramon\Backup\Models;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $filename
 * @property int $size_bytes
 * @property bool $encrypted
 * @property string $contents
 * @property string|null $flarum_version
 * @property string|null $php_version
 * @property int|null $created_by
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class Backup extends AbstractModel
{
    protected $table = 'backups';

    // Flarum's AbstractModel disables Eloquent timestamps by default;
    // re-enable here so created_at / updated_at are auto-managed and
    // the admin list can sort + display "when" without us threading
    // now() through every save site.
    public $timestamps = true;

    protected $casts = [
        'encrypted'  => 'bool',
        'size_bytes' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'filename',
        'size_bytes',
        'encrypted',
        'contents',
        'flarum_version',
        'php_version',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return list<string> */
    public function contentsList(): array
    {
        $raw = trim((string) $this->contents);
        return $raw === '' ? [] : array_values(array_filter(explode(',', $raw)));
    }
}
