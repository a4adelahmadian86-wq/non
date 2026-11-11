<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Services\AuthorizationService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;

/**
 * ResolveAdminScope — قلب معماری «داشبورد اصلی واحد، نقش‌ها مشتق محدود».
 *
 * به‌جای ساخت داشبوردهای جدا برای هر نقش، همین کنسول مرکزی با یک لایهٔ
 * دامنه (Scope) اجرا می‌شود:
 *   - admin                  → global        (همه داده‌ها، همه ابزارها)
 *   - organization_manager   → organization  (فقط سازمان خودش)
 *   - team_supervisor        → team          (فقط تیم‌های خودش)
 *   - employee / observer    → own           (فقط داده‌های شخصی)
 *
 * علاوه بر دامنه، سطح دسترسی هم از RBAC واقعی (جدول permissions و
 * role_permissions) خوانده می‌شود؛ یعنی ادمین می‌تواند برای هر نقش مشخص کند
 * کدام بخش‌های همین داشبورد باز باشد.
 */
class ResolveAdminScope
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_ORGANIZATION = 'organization';
    public const SCOPE_TEAM = 'team';
    public const SCOPE_OWN = 'own';

    public function __construct(private readonly AuthorizationService $authorization)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            // Public pages and signed-token endpoints may legitimately be guests.
            // Protected routes enforce auth/admin middleware themselves.
            $request->attributes->set('farast.scope', self::SCOPE_OWN);
            view()->share('farastScope', self::SCOPE_OWN);
            view()->share('farastScopeUser', null);

            return $next($request);
        }
        abort_if($user->is_blocked, 403, 'حساب شما مسدود شده است.');

        $scope = self::scopeFor($user);

        // Legacy non-admin roles without a real organization/team fail closed.
        if ($scope === self::SCOPE_ORGANIZATION && ! $user->isAdmin() && ! $user->organization_id) {
            $scope = self::SCOPE_OWN;
        }
        if ($scope === self::SCOPE_TEAM && ! $user->isAdmin() && ! $this->hasManagedTeams($user)) {
            $scope = self::SCOPE_OWN;
        }
        if (in_array($scope, [self::SCOPE_ORGANIZATION, self::SCOPE_TEAM], true)
            && $user->isAdmin()
            && ! self::scopeIdFor($user)) {
            $scope = self::SCOPE_OWN;
        }

        $request->attributes->set('farast.scope', $scope);
        view()->share('farastScope', $scope);
        view()->share('farastScopeUser', $user);

        return $next($request);
    }

    /**
     * دامنهٔ کاربر جاری (از middleware یا به‌صورت مستقیم قابل محاسبه).
     */
    public static function scopeFor(?User $user): string
    {
        if (! $user) {
            return self::SCOPE_OWN;
        }

        if ($user->isAdmin()) {
            if (! Schema::hasTable('farast_user_admin_scopes')) {
                return self::applyConfiguredOverride(self::SCOPE_GLOBAL);
            }

            $restriction = DB::table('farast_user_admin_scopes')
                ->where('user_id', $user->id)
                ->first();

            if (! $restriction) {
                return self::applyConfiguredOverride(self::SCOPE_GLOBAL);
            }

            if ($restriction->starts_at && now()->lt($restriction->starts_at)) {
                return self::applyConfiguredOverride(self::SCOPE_GLOBAL);
            }

            if ($restriction->expires_at && now()->gte($restriction->expires_at)) {
                return self::applyConfiguredOverride(self::SCOPE_OWN);
            }

            $scope = in_array($restriction->scope_type, [self::SCOPE_ORGANIZATION, self::SCOPE_TEAM, self::SCOPE_OWN], true)
                ? $restriction->scope_type
                : self::SCOPE_OWN;

            return self::applyConfiguredOverride($scope);
        }

        $scope = match ($user->role) {
            User::ROLE_ORGANIZATION_MANAGER => $user->organization_id ? self::SCOPE_ORGANIZATION : self::SCOPE_OWN,
            User::ROLE_TEAM_SUPERVISOR => self::SCOPE_TEAM,
            default => self::SCOPE_OWN,
        };

        return self::applyConfiguredOverride($scope);
    }

    private static function applyConfiguredOverride(string $scope): string
    {
        $overrideKey = config('farast_admin.scope_override_key');
        if (! is_string($overrideKey) || $overrideKey === '') {
            return $scope;
        }

        try {
            $configured = trim((string) \App\Models\SiteSetting::read($overrideKey, ''));
        } catch (\Throwable) {
            $configured = '';
        }

        $rank = [
            self::SCOPE_GLOBAL => 0,
            self::SCOPE_ORGANIZATION => 1,
            self::SCOPE_TEAM => 2,
            self::SCOPE_OWN => 3,
        ];

        return isset($rank[$configured]) && $rank[$configured] >= ($rank[$scope] ?? 3)
            ? $configured
            : $scope;
    }

    /** Explicit organization/team boundary assigned to a restricted administrator. */
    public static function scopeIdFor(?User $user): ?int
    {
        if (! $user || ! $user->isAdmin() || ! Schema::hasTable('farast_user_admin_scopes')) {
            return null;
        }

        $restriction = DB::table('farast_user_admin_scopes')
            ->where('user_id', $user->id)
            ->first();

        if (! $restriction
            || ($restriction->starts_at && now()->lt($restriction->starts_at))
            || ($restriction->expires_at && now()->gte($restriction->expires_at))) {
            return null;
        }

        $requestUser = request()->user();
        $requestScope = $requestUser && (int) $requestUser->id === (int) $user->id
            ? request()->attributes->get('farast.scope')
            : null;
        $effectiveScope = in_array($requestScope, [self::SCOPE_GLOBAL, self::SCOPE_ORGANIZATION, self::SCOPE_TEAM, self::SCOPE_OWN], true)
            ? $requestScope
            : self::scopeFor($user);

        if ($effectiveScope !== $restriction->scope_type) {
            return null;
        }

        return $restriction->scope_id !== null ? (int) $restriction->scope_id : null;
    }

    public static function organizationIdForScope(?User $user): ?int
    {
        if (! $user) {
            return null;
        }

        if (! $user->isAdmin()) {
            return $user->organization_id !== null ? (int) $user->organization_id : null;
        }

        $scope = self::scopeFor($user);
        $scopeId = self::scopeIdFor($user);

        if ($scope === self::SCOPE_ORGANIZATION) {
            return $scopeId;
        }

        if ($scope === self::SCOPE_TEAM && $scopeId) {
            $organizationId = Team::query()->whereKey($scopeId)->value('organization_id');

            return $organizationId !== null ? (int) $organizationId : null;
        }

        return null;
    }

    /**
     * برچسب فارسی دامنه برای نمایش در رابط.
     */
    public static function scopeLabel(string $scope): string
    {
        return match ($scope) {
            self::SCOPE_GLOBAL => 'سراسری — همه داده‌ها',
            self::SCOPE_ORGANIZATION => 'سازمانی — فقط سازمان خود',
            self::SCOPE_TEAM => 'تیمی — فقط تیم‌های تحت سرپرستی',
            default => 'شخصی — فقط داده‌های خود',
        };
    }

    /**
     * آیا کاربر حداقل یک تیم را سرپرستی می‌کند یا در آن manager است؟
     */
    private function hasManagedTeams(User $user): bool
    {
        if (! $user->organization_id) {
            return false;
        }

        $supervised = Team::query()
            ->where('organization_id', $user->organization_id)
            ->where('supervisor_id', $user->id)
            ->exists();
        $managerial = $user->teams()
            ->where('teams.organization_id', $user->organization_id)
            ->wherePivotIn('team_role', ['manager', 'owner', 'supervisor'])
            ->exists();

        return $supervised || $managerial;
    }

    /**
     * شناسه‌های کاربرانی که کاربر فعلی مجاز به دیدن آن‌هاست.
     * null یعنی «بدون محدودیت» (دامنه سراسری).
     */
    public static function visibleUserIds(?User $user, ?string $scope = null): ?array
    {
        if (! $user) {
            return [];
        }

        $scope ??= self::scopeFor($user);

        return match ($scope) {
            self::SCOPE_GLOBAL => null,
            self::SCOPE_ORGANIZATION => self::organizationUserIds($user),
            self::SCOPE_TEAM => self::teamUserIds($user),
            self::SCOPE_OWN => [$user->id],
            default => [$user->id],
        };
    }

    private static function organizationUserIds(User $user): array
    {
        $organizationId = $user->isAdmin() ? self::scopeIdFor($user) : $user->organization_id;
        if (! $organizationId) {
            return [$user->id];
        }

        return User::query()
            ->where('organization_id', $organizationId)
            ->where('role', '!=', User::ROLE_ADMIN)
            ->pluck('id')
            ->all();
    }

    private static function teamUserIds(User $user): array
    {
        if ($user->isAdmin()) {
            $teamId = self::scopeIdFor($user);
            $team = $teamId ? Team::query()->whereKey($teamId)->first(['id', 'organization_id']) : null;
            if (! $team) {
                return [$user->id];
            }

            $memberIds = DB::table('team_user')
                ->join('users', 'users.id', '=', 'team_user.user_id')
                ->where('team_user.team_id', $team->id)
                ->where('users.organization_id', $team->organization_id)
                ->where('users.role', '!=', User::ROLE_ADMIN)
                ->pluck('team_user.user_id')
                ->all();

            return collect([$user->id])->merge($memberIds)->unique()->values()->all();
        }

        if (! $user->organization_id) {
            return [$user->id];
        }

        $teamIds = Team::query()
            ->where('organization_id', $user->organization_id)
            ->where(function ($query) use ($user) {
                $query->where('supervisor_id', $user->id)
                    ->orWhereIn('id', function ($q) use ($user) {
                        $q->select('team_id')->from('team_user')
                            ->where('user_id', $user->id)
                            ->whereIn('team_role', ['manager', 'owner', 'supervisor']);
                    });
            })
            ->pluck('id');

        if ($teamIds->isEmpty()) {
            return [$user->id];
        }

        $memberIds = DB::table('team_user')
            ->join('users', 'users.id', '=', 'team_user.user_id')
            ->whereIn('team_user.team_id', $teamIds)
            ->where('users.organization_id', $user->organization_id)
            ->where('users.role', '!=', User::ROLE_ADMIN)
            ->pluck('team_user.user_id')
            ->all();

        return collect([$user->id])->merge($memberIds)->unique()->values()->all();
    }

    /**
     * بررسی سریع اینکه کاربر جاری اجازهٔ دیدن بخش X از کنسول را دارد یا نه.
     */
    public static function sectionAllowed(?User $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }

        return app(AuthorizationService::class)->allows($user, $permission);
    }
}
