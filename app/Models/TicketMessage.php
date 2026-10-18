<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMessage extends Model
{
    protected $fillable = [
        'ticket_id',
        'user_id',
        'body',
        'attachments',
        'is_internal',
        'is_staff',
    ];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'is_staff' => 'boolean',
            'attachments' => 'array',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFromStaff(): bool
    {
        if ($this->is_staff) {
            return true;
        }

        return $this->user && method_exists($this->user, 'isAdmin') && $this->user->isAdmin();
    }
}
