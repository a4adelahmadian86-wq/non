<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    protected $fillable = [
        'title',
        'body',
        'type',
        'audience',
        'is_active',
        'is_pinned',
        'notify_in_app',
        'notify_email',
        'link_url',
        'starts_at',
        'ends_at',
        'notified_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_pinned' => 'boolean',
            'notify_in_app' => 'boolean',
            'notify_email' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeVisible($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            });
    }

    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }

    /**
     * اعلان‌های قابل‌مشاهده برای یک کاربر (با فیلتر مخاطب).
     */
    public function scopeForUser($query, $user)
    {
        $query = $query->visible();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return $query->whereIn('audience', ['all', 'admins', 'users']);
        }

        return $query->where(function ($q) {
            $q->where('audience', 'all')
                ->orWhere('audience', 'users')
                ->orWhereNull('audience');
        });
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'success' => 'موفقیت',
            'warning' => 'هشدار',
            'danger' => 'مهم',
            default => 'اطلاع‌رسانی',
        };
    }

    public function audienceLabel(): string
    {
        return match ($this->audience ?? 'all') {
            'admins' => 'فقط مدیران',
            'users' => 'فقط کاربران',
            default => 'همه',
        };
    }

    public function icon(): string
    {
        return match ($this->type) {
            'danger' => 'fa-triangle-exclamation',
            'warning' => 'fa-bell',
            'success' => 'fa-circle-check',
            default => 'fa-bullhorn',
        };
    }
}
