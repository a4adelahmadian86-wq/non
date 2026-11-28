<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StorageBackupObject extends Model
{
    protected $fillable = [
        'storage_volume_id', 'sha256', 'size_bytes', 'object_key',
        'encrypted_size_bytes', 'status', 'verified_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'encrypted_size_bytes' => 'integer',
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function volume(): BelongsTo
    {
        return $this->belongsTo(StorageVolume::class, 'storage_volume_id');
    }

    public function runs(): BelongsToMany
    {
        return $this->belongsToMany(
            StorageBackupRun::class,
            'storage_backup_run_objects',
            'storage_backup_object_id',
            'storage_backup_run_id'
        )->withTimestamps();
    }
}
