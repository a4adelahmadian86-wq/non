<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageManagedFile extends Model
{
    protected $fillable = [
        'storage_volume_id', 'logical_path', 'drive_key', 'file_key', 'original_name', 'mime',
        'size_bytes', 'checksum', 'classification', 'source_module', 'source_type',
        'source_id', 'product_id', 'visibility', 'status', 'replicated_to_volume_id', 'metadata',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'metadata' => 'array'];
    }

    public function volume(): BelongsTo
    {
        return $this->belongsTo(StorageVolume::class, 'storage_volume_id');
    }

    public function replicaVolume(): BelongsTo
    {
        return $this->belongsTo(StorageVolume::class, 'replicated_to_volume_id');
    }
}