<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FarastStorageObject extends Model
{
    protected $table = 'farast_storage_objects';

    protected $fillable = [
        'object_id',
        'user_id',
        'project_id',
        'document_id',
        'disk',
        'path',
        'path_hash',
        'mime',
        'size',
        'checksum',
        'status',
        'classification',
        'version',
        'revision',
        'provenance',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->object_id ??= (string) Str::uuid());

        static::saving(function ($model): void {
            $path = (string) $model->path;
            if ($path === '') {
                throw new InvalidArgumentException('Storage object path cannot be empty.');
            }

            // Hash the exact persisted key; the database indexes this fixed-size
            // digest instead of the potentially 1000-character object path.
            $model->path_hash = hash('sha256', $path);
        });
    }

    protected function casts(): array
    {
        return ['provenance' => 'array'];
    }
}
