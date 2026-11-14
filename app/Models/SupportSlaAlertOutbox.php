<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;

class SupportSlaAlertOutbox extends Model
{
    protected $table = 'support_sla_alert_outbox';

    protected $fillable = [
        'ticket_id',
        'kind',
        'event',
        'minutes_left',
        'event_identity',
        'idempotency_key',
        'status',
        'attempts',
        'available_at',
        'locked_at',
        'delivered_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'minutes_left' => 'integer',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
