<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageBackupRun extends Model
{
    protected $fillable = [
        'storage_volume_id', 'backup_type', 'source_name', 'status', 'object_key',
        'size_bytes', 'checksum', 'started_at', 'completed_at', 'error_message', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function volume(): BelongsTo
    {
        return $this->belongsTo(StorageVolume::class, 'storage_volume_id');
    }
}