<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageVolume extends Model
{
    protected $fillable = [
        'drive_key', 'name', 'provider', 'driver', 'region', 'endpoint', 'bucket',
        'credentials', 'capacity_bytes', 'used_bytes', 'reserved_bytes', 'reserved_files',
        'file_limit', 'max_file_bytes', 'priority', 'is_active', 'status',
        'last_checked_at', 'last_success_at', 'capabilities', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'capabilities' => 'array',
            'metadata' => 'array',
            'capacity_bytes' => 'integer',
            'used_bytes' => 'integer',
            'reserved_bytes' => 'integer',
            'reserved_files' => 'integer',
            'file_limit' => 'integer',
            'max_file_bytes' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public function files(): HasMany
    {
        return $this->hasMany(StorageManagedFile::class);
    }

    public function replicas(): HasMany
    {
        return $this->hasMany(StorageManagedFile::class, 'replicated_to_volume_id');
    }

    public function backups(): HasMany
    {
        return $this->hasMany(StorageBackupRun::class, 'storage_volume_id');
    }

    public function getFreeBytesAttribute(): ?int
    {
        return $this->capacity_bytes === null
            ? null
            : max(0, $this->capacity_bytes - $this->used_bytes - $this->reserved_bytes);
    }
}