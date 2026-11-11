<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageVolumeReservation extends Model
{
    protected $fillable = [
        'storage_volume_id',
        'reservation_key',
        'reserved_bytes',
        'reserved_objects',
        'purpose',
        'metadata',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'reserved_bytes' => 'integer',
            'reserved_objects' => 'integer',
            'metadata' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function volume(): BelongsTo
    {
        return $this->belongsTo(StorageVolume::class, 'storage_volume_id');
    }
}
