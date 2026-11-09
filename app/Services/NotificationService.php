<?php

namespace App\Services;

use App\Models\FarastNotification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * تنها درگاه ثبت و خواندن اعلان‌های درون‌برنامه‌ای FARAST.
 * ماژول‌ها باید برای ثبت اعلان از همین سرویس استفاده کنند.
 */
class NotificationService
{
    public function push(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
        bool $throwOnFailure = false,
        ?string $dedupeKey = null,
    ): ?FarastNotification {
        if (! Schema::hasTable('farast_notifications')) {
            if ($throwOnFailure) {
                throw new RuntimeException('جدول اعلان‌های فراست در دسترس نیست.');
            }

            return null;
        }

        try {
            $attributes = [
                'user_id' => $userId,
                'type' => mb_substr(trim($type), 0, 100),
                'title' => mb_substr(trim($title), 0, 180),
                'body' => $body === null ? null : mb_substr($body, 0, 5000),
                'data' => $data ?: null,
            ];

            if ($dedupeKey !== null && trim($dedupeKey) !== '') {
                $key = mb_substr(trim($dedupeKey), 0, 191);
                $notification = FarastNotification::firstOrCreate(
                    ['dedupe_key' => $key],
                    $attributes + ['dedupe_key' => $key],
                );

                if ((int) $notification->user_id !== $userId) {
                    throw new RuntimeException('کلید یکتای اعلان قبلاً برای کاربر دیگری استفاده شده است.');
                }

                return $notification;
            }

            return FarastNotification::create($attributes);
        } catch (Throwable $e) {
            // A concurrent request may have inserted the same unique key after
            // firstOrCreate performed its initial lookup. Resolve that race as a
            // successful idempotent delivery instead of reporting a false failure.
            if ($dedupeKey !== null && trim($dedupeKey) !== '' && Schema::hasTable('farast_notifications')) {
                $existing = FarastNotification::query()
                    ->where('dedupe_key', mb_substr(trim($dedupeKey), 0, 191))
                    ->first();

                if ($existing) {
                    if ((int) $existing->user_id !== $userId) {
                        Log::warning('farast.notification.dedupe_key_conflict', [
                            'user_id' => $userId,
                            'type' => $type,
                        ]);

                        if ($throwOnFailure) {
                            throw new RuntimeException('کلید یکتای اعلان قبلاً برای کاربر دیگری استفاده شده است.');
                        }

                        return null;
                    }

                    return $existing;
                }
            }

            Log::warning('farast.notification.push_failed', [
                'user_id' => $userId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            if ($throwOnFailure) {
                throw $e;
            }

            return null;
        }
    }

    public function pushToUser(
        User $user,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
        bool $throwOnFailure = false,
        ?string $dedupeKey = null,
    ): ?FarastNotification {
        return $this->push($user->id, $type, $title, $body, $data, $throwOnFailure, $dedupeKey);
    }

    public function unreadCount(int $userId): int
    {
        if (! Schema::hasTable('farast_notifications')) return 0;

        return FarastNotification::query()
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->whereNull('read_at')
            ->count();
    }

    public function latest(int $userId, int $limit = 8)
    {
        if (! Schema::hasTable('farast_notifications')) return collect();

        return FarastNotification::query()
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 30)))
            ->get();
    }

    public function paginateForUser(
        int $userId,
        ?string $filter = null,
        int $perPage = 20,
        ?string $search = null,
    ): LengthAwarePaginator {
        $q = FarastNotification::query()->where('user_id', $userId);

        if ($filter === 'archived') {
            $q->whereNotNull('archived_at');
        } else {
            $q->whereNull('archived_at');
            if ($filter === 'unread') {
                $q->whereNull('read_at');
            } elseif (in_array($filter, ['support', 'finance', 'announcement', 'community', 'security', 'marketing', 'system'], true)) {
                $this->applyCategoryFilter($q, $filter);
            }
        }

        $term = trim((string) $search);
        if ($term !== '') {
            $q->where(function (Builder $w) use ($term) {
                $w->where('title', 'like', '%'.$term.'%')
                    ->orWhere('body', 'like', '%'.$term.'%');
            });
        }

        return $q->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    private function applyCategoryFilter(Builder $query, string $filter): void
    {
        match ($filter) {
            'support' => $query->where('type', 'like', 'ticket_%'),
            'finance' => $query->where(function (Builder $w) {
                $w->where('type', 'like', 'order_%')
                    ->orWhere('type', 'like', 'wallet_%')
                    ->orWhere('type', 'like', 'billing_%');
            }),
            'community' => $query->where('type', 'like', 'discussion_%'),
            'security' => $query->where('type', 'like', 'security_%'),
            'announcement' => $query->where('type', 'like', 'announcement_%')->where('type', '!=', 'announcement_campaign'),
            'marketing' => $query->where(function (Builder $w) {
                $w->where('type', 'announcement_campaign')
                    ->orWhere('type', 'like', 'marketing_%');
            }),
            'system' => $query
                ->where('type', 'not like', 'discussion_%')
                ->where('type', 'not like', 'ticket_%')
                ->where('type', 'not like', 'order_%')
                ->where('type', 'not like', 'wallet_%')
                ->where('type', 'not like', 'billing_%')
                ->where('type', 'not like', 'security_%')
                ->where('type', 'not like', 'announcement_%')
                ->where('type', 'not like', 'marketing_%')
                ->where('type', '!=', 'announcement_campaign'),
            default => null,
        };
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
            ->whereNull('archived_at')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function archive(int $userId, int $id): bool
    {
        return FarastNotification::query()
            ->where('user_id', $userId)
            ->where('id', $id)
            ->whereNull('archived_at')
            ->update(['archived_at' => now(), 'read_at' => now()]) > 0;
    }

    public function restore(int $userId, int $id): bool
    {
        return FarastNotification::query()
            ->where('user_id', $userId)
            ->where('id', $id)
            ->whereNotNull('archived_at')
            ->update(['archived_at' => null]) > 0;
    }

    public function countsByCategory(int $userId): array
    {
        if (! Schema::hasTable('farast_notifications')) {
            return array_fill_keys(['all', 'unread', 'archived', 'support', 'finance', 'announcement', 'community', 'security', 'marketing', 'system'], 0);
        }

        $base = FarastNotification::query()->where('user_id', $userId);
        $active = (clone $base)->whereNull('archived_at');

        $count = function (string $category) use ($active): int {
            $query = clone $active;
            $this->applyCategoryFilter($query, $category);
            return $query->count();
        };

        return [
            'all' => (clone $active)->count(),
            'unread' => (clone $active)->whereNull('read_at')->count(),
            'archived' => (clone $base)->whereNotNull('archived_at')->count(),
            'support' => $count('support'),
            'finance' => $count('finance'),
            'announcement' => $count('announcement'),
            'community' => $count('community'),
            'security' => $count('security'),
            'marketing' => $count('marketing'),
            'system' => $count('system'),
        ];
    }
}
