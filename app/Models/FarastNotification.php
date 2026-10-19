<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarastNotification extends Model
{
    protected $table = 'farast_notifications';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function url(): ?string
    {
        $data = $this->data ?? [];

        return is_array($data) ? ($data['url'] ?? null) : null;
    }

    public function category(): string
    {
        $type = (string) $this->type;

        if (str_starts_with($type, 'ticket_')) {
            return 'support';
        }
        if (str_starts_with($type, 'order_') || str_starts_with($type, 'wallet_') || str_starts_with($type, 'billing_')) {
            return 'finance';
        }
        if (str_starts_with($type, 'security_')) {
            return 'security';
        }
        if (str_starts_with($type, 'announcement_')) {
            return 'announcement';
        }

        return 'system';
    }

    public function icon(): string
    {
        return match ($this->category()) {
            'support' => 'fa-headset',
            'finance' => 'fa-wallet',
            'security' => 'fa-shield-halved',
            'announcement' => 'fa-bullhorn',
            default => 'fa-bell',
        };
    }

    public function categoryLabel(): string
    {
        return match ($this->category()) {
            'support' => 'پشتیبانی',
            'finance' => 'مالی',
            'security' => 'امنیت',
            'announcement' => 'اعلان',
            default => 'سیستم',
        };
    }
}
