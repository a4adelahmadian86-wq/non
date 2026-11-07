<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;

class DashboardNavigationService
{
    public function __construct(private readonly CapabilityService $capabilities, private readonly AuthorizationService $authorization)
    {
    }

    public function forUser(?User $user): array
    {
        return collect(config('dashboard.navigation', []))
            ->map(fn (array $group) => $this->filterGroup($group, $user))
            ->filter()
            ->values()
            ->all();
    }

    private function filterGroup(array $group, ?User $user): ?array
    {
        if (! $this->authorized($group, $user)) {
            return null;
        }

        $items = collect($group['items'] ?? [])
            ->map(fn (array $item) => $this->filterItem($item, $user))
            ->filter()
            ->values()
            ->all();

        return $items === [] ? null : [
            'key' => $group['key'] ?? null,
            'label' => $group['label'],
            'icon' => $group['icon'],
            'items' => $items,
        ];
    }

    private function filterItem(array $item, ?User $user): ?array
    {
        if (! $this->authorized($item, $user)) {
            return null;
        }

        // Incomplete placeholders are intentionally omitted from the live menu.
        $routeName = $item['route'] ?? null;
        if (($item['planned'] ?? false) || ! is_string($routeName) || ! Route::has($routeName)) {
            return null;
        }

        $query = is_array($item['query'] ?? null) ? $item['query'] : [];
        $href = route($routeName);
        if ($query !== []) {
            $href .= '?'.http_build_query($query);
        }
        $fragment = (string) ($item['fragment'] ?? '');
        $href .= $fragment;

        $active = request()->routeIs($routeName) && $fragment === '';
        if ($active && $query !== []) {
            foreach ($query as $key => $value) {
                if ((string) request()->query($key, '') !== (string) $value) {
                    $active = false;
                    break;
                }
            }
        }
        if ($active) {
            foreach ((array) ($item['active_unless_query'] ?? []) as $key) {
                if (request()->query($key) !== null) {
                    $active = false;
                    break;
                }
            }
        }
        if (! $active && request()->routeIs($routeName)) {
            foreach ((array) ($item['active_when_query'] ?? []) as $key) {
                if (request()->query($key) !== null) {
                    $active = true;
                    break;
                }
            }
        }

        $badge = $this->itemBadge($item['key'] ?? null, $user);

        return [
            'key' => $item['key'] ?? null,
            'label' => $item['label'],
            'icon' => $item['icon'],
            'href' => $href,
            'active' => $active,
            'disabled' => false,
            'planned' => false,
            'permission' => $item['permission'] ?? null,
            'scope' => $item['scope'] ?? null,
            'badge' => $badge,
        ];
    }

    private function authorized(array $definition, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (($definition['admin_only'] ?? false) && ! $user->isAdmin()) {
            return false;
        }
        if (($definition['exclude_admin'] ?? false) && $user->isAdmin()) {
            return false;
        }

        $permission = $definition['permission'] ?? null;
        if ($permission !== null && ! $this->allows($user, $permission, $definition['scope'] ?? 'own')) {
            return false;
        }

        return true;
    }


    /**
     * نشان عددی عملیاتی برای آیتم‌های پشتیبانی.
     */
    private function itemBadge(?string $key, ?User $user): ?int
    {
        if (! $user || ! $key || ! Schema::hasTable('tickets')) {
            return null;
        }

        try {
            return match ($key) {
                'support.my' => $this->customerUnreadCount($user),
                'support.manage' => $user->isAdmin() ? Ticket::needsReply()->count() : null,
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private function customerUnreadCount(User $user): int
    {
        $q = Ticket::query()
            ->where('user_id', $user->id)
            ->where('status', 'answered');

        // اگر ستون seen موجود باشد: فقط پاسخ‌های ندیده
        if (Schema::hasColumn('tickets', 'customer_last_seen_at') && Schema::hasColumn('tickets', 'last_reply_at')) {
            $q->where(function ($w) {
                $w->whereNull('customer_last_seen_at')
                    ->orWhereColumn('last_reply_at', '>', 'customer_last_seen_at');
            });
        }

        return (int) $q->count();
    }

    private function allows(User $user, string $permission, string $scope): bool
    {
        if ($user->isAdmin()) return true;

        if (in_array($permission, AuthorizationService::PERMISSIONS, true)) {
            return $this->authorization->allows($user, $permission);
        }

        $capability = match ($permission) {
            'documents.view' => null,
            'editor.use' => 'can_type',
            'ai.use' => 'can_ai',
            'support.use' => 'can_support',
            default => null,
        };

        if ($capability === null) return $permission === 'documents.view';
        return $this->capabilities->allowed($user, $capability);
    }
}
