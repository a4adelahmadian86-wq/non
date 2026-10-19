<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $fillable = [
        'user_id',
        'assigned_to',
        'ticket_code',
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
        'first_response_at',
        'closed_at',
        'customer_last_seen_at',
        'sla_first_due_at',
        'sla_resolve_due_at',
        'resolved_at',
        'csat_score',
        'csat_comment',
        'csat_at',
    ];

    protected function casts(): array
    {
        return [
            'last_reply_at' => 'datetime',
            'first_response_at' => 'datetime',
            'closed_at' => 'datetime',
            'customer_last_seen_at' => 'datetime',
            'sla_first_due_at' => 'datetime',
            'sla_resolve_due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'csat_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'related_product_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at');
    }

    public function publicMessages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)
            ->where(function ($q) {
                $q->where('is_internal', false)->orWhereNull('is_internal');
            })
            ->orderBy('created_at');
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

    public function displayCode(): string
    {
        return $this->ticket_code ?: ('#'.$this->id);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'open' => 'باز',
            'answered' => 'پاسخ داده شده',
            'closed' => 'بسته شده',
            'pending' => 'در حال بررسی',
            'user_replied' => 'پاسخ کاربر',
            default => (string) $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'open' => 'open',
            'answered' => 'answered',
            'closed' => 'closed',
            'pending' => 'pending',
            'user_replied' => 'user',
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

    public function priorityLabel(): string
    {
        return match ($this->priority ?? 'normal') {
            'low' => 'کم',
            'high' => 'زیاد',
            default => 'متوسط',
        };
    }

    public function topicLabel(): ?string
    {
        return match ($this->topic_area) {
            'services' => 'خدمات',
            'store' => 'فروشگاه',
            default => null,
        };
    }

    public function serviceLabel(): ?string
    {
        return match ($this->related_service) {
            'typing' => 'تایپ آنلاین',
            'convert' => 'تبدیل فایل',
            'edit' => 'ویرایش هوشمند',
            'ocr' => 'OCR / استخراج متن',
            'voice' => 'ورود صوتی',
            'other' => 'سایر خدمات',
            default => $this->related_service,
        };
    }

    public function canRateCsat(): bool
    {
        return $this->status === 'closed' && empty($this->csat_at);
    }

    public function scopeNeedsReply(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'user_replied']);
    }

    public function scopeOpenQueue(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['closed']);
    }

    public function scopeSlaBreached(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['closed'])
            ->where(function ($q) {
                $q->where(function ($w) {
                    $w->whereNull('first_response_at')
                        ->whereNotNull('sla_first_due_at')
                        ->where('sla_first_due_at', '<', now());
                })->orWhere(function ($w) {
                    $w->whereNotNull('sla_resolve_due_at')
                        ->where('sla_resolve_due_at', '<', now());
                });
            });
    }

    public function hasUnreadForCustomer(): bool
    {
        if ($this->status !== 'answered') {
            return false;
        }
        if (! $this->customer_last_seen_at) {
            return true;
        }

        return $this->last_reply_at && $this->last_reply_at->gt($this->customer_last_seen_at);
    }
}
