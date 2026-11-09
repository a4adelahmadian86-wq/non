<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    protected $fillable = [
        'type',
        'to_email',
        'user_id',
        'subject',
        'status',
        'attempts',
        'error',
        'meta',
        'sent_at',
        'queued_at',
        'last_attempt_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'sent_at' => 'datetime',
            'queued_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
