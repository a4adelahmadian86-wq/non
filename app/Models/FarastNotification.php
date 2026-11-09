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
        'archived_at',
        'dedupe_key',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'archived_at' => 'datetime',
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

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function url(): ?string
    {
        $data = $this->data ?? [];
        $url = is_array($data) ? ($data['url'] ?? null) : null;

        return is_string($url) && trim($url) !== '' ? $url : null;
    }

    public function category(): string
    {
        $type = (string) $this->type;

        if (str_starts_with($type, 'discussion_')) return 'community';
        if (str_starts_with($type, 'ticket_')) return 'support';
        if (str_starts_with($type, 'order_') || str_starts_with($type, 'wallet_') || str_starts_with($type, 'billing_')) return 'finance';
        if (str_starts_with($type, 'security_')) return 'security';
        if ($type === 'announcement_campaign' || str_starts_with($type, 'marketing_')) return 'marketing';
        if (str_starts_with($type, 'announcement_')) return 'announcement';

        return 'system';
    }

    public function icon(): string
    {
        return match ($this->category()) {
            'support' => 'fa-headset',
            'finance' => 'fa-wallet',
            'security' => 'fa-shield-halved',
            'announcement' => 'fa-bullhorn',
            'marketing' => 'fa-bullhorn',
            'community' => 'fa-comments',
            default => 'fa-bell',
        };
    }

    public function categoryLabel(): string
    {
        return match ($this->category()) {
            'support' => 'پشتیبانی',
            'finance' => 'مالی',
            'security' => 'امنیت',
            'announcement' => 'اطلاعیه رسمی',
            'marketing' => 'پیام بازاریابی',
            'community' => 'گفت‌وگوها',
            default => 'سیستم',
        };
    }
}
