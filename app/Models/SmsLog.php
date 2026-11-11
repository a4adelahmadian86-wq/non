<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    protected $fillable = [
        'idempotency_key',
        'event',
        'mobile_hash',
        'status',
        'attempts',
        'error',
        'last_attempt_at',
        'accepted_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'accepted_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
