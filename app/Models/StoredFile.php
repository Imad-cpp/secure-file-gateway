<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $scan_completed_at
 * @property Carbon|null $deleted_at
 */
class StoredFile extends Model
{
    use HasUuids;

    protected $fillable = [
        'owner_id',
        'original_name',
        'detected_mime_type',
        'size_bytes',
        'sha256',
        'deleted_sha256',
        'quarantine_object_key',
        'clean_object_key',
        'state',
        'scan_engine',
        'scan_signature',
        'scan_completed_at',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'scan_completed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
