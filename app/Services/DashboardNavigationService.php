<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Route;

class DashboardNavigationService
{
    public function __construct(private readonly CapabilityService $capabilities)
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

        $routeName = $item['route'] ?? null;
        $planned = (bool) ($item['planned'] ?? false);

        if ($routeName !== null && ! Route::has($routeName)) {
            $planned = true;
            $routeName = null;
        }

        if ($routeName === null && ! $planned) {
            return null;
        }

        $href = null;
        $active = false;
        if ($routeName !== null) {
            $href = route($routeName).($item['fragment'] ?? '');
            $active = request()->routeIs($routeName);
            if ($active && isset($item['fragment'])) {
                $active = request()->getRequestUri() === $href;
            }
        }

        return [
            'key' => $item['key'] ?? null,
            'label' => $item['label'],
            'icon' => $item['icon'],
            'href' => $href,
            'active' => $active,
            'disabled' => $href === null,
            'planned' => $href === null,
            'permission' => $item['permission'] ?? null,
            'scope' => $item['scope'] ?? null,
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

        $permission = $definition['permission'] ?? null;
        if ($permission !== null && ! $this->allows($user, $permission, $definition['scope'] ?? 'own')) {
            return false;
        }

        return true;
    }

    private function allows(User $user, string $permission, string $scope): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (in_array($scope, ['team', 'organization', 'global'], true)) {
            return false;
        }

        $capability = match ($permission) {
            'documents.view' => null,
            'editor.use' => 'can_type',
            'ai.use' => 'can_ai',
            'support.use' => 'can_support',
            default => null,
        };

        if ($capability === null) {
            return $permission === 'documents.view';
        }

        return $this->capabilities->allowed($user, $capability);
    }
}
