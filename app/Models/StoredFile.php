<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoredFile extends Model
{
    use HasUuids;

    protected $fillable = [
        'owner_id',
        'original_name',
        'detected_mime_type',
        'size_bytes',
        'sha256',
        'quarantine_object_key',
        'clean_object_key',
        'state',
        'scan_engine',
        'scan_signature',
        'scan_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'scan_completed_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
