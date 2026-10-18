<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $fillable = [
        'user_id',
        'guest_name',
        'guest_email',
        'subject',
        'status',
        'priority',
        'department',
        'topic_area',
        'related_service',
        'related_product_id',
        'source',
        'last_reply_at',
    ];

    protected function casts(): array
    {
        return [
            'last_reply_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'related_product_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at');
    }

    public function isGuest(): bool
    {
        return $this->user_id === null && filled($this->guest_email);
    }

    public function displayName(): string
    {
        if ($this->user) {
            return $this->user->name ?? $this->user->email ?? 'کاربر';
        }

        return $this->guest_name ?: ($this->guest_email ?: 'مهمان');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'open' => 'باز',
            'answered' => 'پاسخ داده شده',
            'closed' => 'بسته شده',
            'pending' => 'در حال بررسی',
            default => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'open' => 'open',
            'answered' => 'answered',
            'closed' => 'closed',
            'pending' => 'pending',
            default => 'open',
        };
    }

    public function departmentLabel(): string
    {
        return match ($this->department ?? 'support') {
            'sales' => 'بخش فروش',
            'billing' => 'بخش مالی',
            'complaint' => 'بخش شکایات',
            default => 'بخش پشتیبانی',
        };
    }
}
