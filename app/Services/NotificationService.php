<?php

namespace App\Services;

use App\Models\FarastNotification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * سرویس مرکزی اعلان‌های درون‌برنامه (هماهنگ با پشتیبانی، مالی، اعلان عمومی، سیستم).
 */
class NotificationService
{
    public function push(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
    ): ?FarastNotification {
        if (! Schema::hasTable('farast_notifications')) {
            return null;
        }

        try {
            return FarastNotification::create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('farast.notification.push_failed', [
                'user_id' => $userId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function pushToUser(
        User $user,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
    ): ?FarastNotification {
        return $this->push($user->id, $type, $title, $body, $data);
    }

    public function unreadCount(int $userId): int
    {
        if (! Schema::hasTable('farast_notifications')) {
            return 0;
        }

        return FarastNotification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    public function latest(int $userId, int $limit = 8)
    {
        if (! Schema::hasTable('farast_notifications')) {
            return collect();
        }

        return FarastNotification::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function paginateForUser(int $userId, ?string $filter = null, int $perPage = 20): LengthAwarePaginator
    {
        $q = FarastNotification::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at');

        if ($filter === 'unread') {
            $q->whereNull('read_at');
        } elseif ($filter === 'support') {
            $q->where('type', 'like', 'ticket_%');
        } elseif ($filter === 'finance') {
            $q->where(function ($w) {
                $w->where('type', 'like', 'order_%')
                    ->orWhere('type', 'like', 'wallet_%')
                    ->orWhere('type', 'like', 'billing_%');
            });
        } elseif ($filter === 'announcement') {
            $q->where('type', 'like', 'announcement_%');
        } elseif ($filter === 'system') {
            $q->where('type', 'not like', 'ticket_%')
                ->where('type', 'not like', 'order_%')
                ->where('type', 'not like', 'wallet_%')
                ->where('type', 'not like', 'billing_%')
                ->where('type', 'not like', 'announcement_%');
        }

        return $q->paginate($perPage)->withQueryString();
    }

    public function markRead(int $userId, int $id): bool
    {
        return FarastNotification::query()
            ->where('user_id', $userId)
            ->where('id', $id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]) > 0;
    }

    public function markAllRead(int $userId): int
    {
        return FarastNotification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function countsByCategory(int $userId): array
    {
        if (! Schema::hasTable('farast_notifications')) {
            return ['all' => 0, 'unread' => 0, 'support' => 0, 'finance' => 0, 'announcement' => 0, 'system' => 0];
        }

        $base = FarastNotification::query()->where('user_id', $userId);

        return [
            'all' => (clone $base)->count(),
            'unread' => (clone $base)->whereNull('read_at')->count(),
            'support' => (clone $base)->where('type', 'like', 'ticket_%')->count(),
            'finance' => (clone $base)->where(function ($w) {
                $w->where('type', 'like', 'order_%')
                    ->orWhere('type', 'like', 'wallet_%')
                    ->orWhere('type', 'like', 'billing_%');
            })->count(),
            'announcement' => (clone $base)->where('type', 'like', 'announcement_%')->count(),
            'system' => (clone $base)->where('type', 'not like', 'ticket_%')
                ->where('type', 'not like', 'order_%')
                ->where('type', 'not like', 'wallet_%')
                ->where('type', 'not like', 'billing_%')
                ->where('type', 'not like', 'announcement_%')
                ->count(),
        ];
    }
}
