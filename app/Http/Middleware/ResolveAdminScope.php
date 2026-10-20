<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Services\AuthorizationService;
use Closure;
use Illuminate\Support\Facades\DB;
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
        abort_unless($user, 403);
        abort_if($user->is_blocked, 403, 'حساب شما مسدود شده است.');

        $scope = match ($user->role) {
            User::ROLE_ADMIN => self::SCOPE_GLOBAL,
            User::ROLE_ORGANIZATION_MANAGER => self::SCOPE_ORGANIZATION,
            User::ROLE_TEAM_SUPERVISOR => self::SCOPE_TEAM,
            default => self::SCOPE_OWN,
        };

        // اگر کاربر مدیر باشد اما سازمان نداشته باشد، دامنه به own تنزل می‌کند تا دادهٔ نشت نکند.
        if ($scope === self::SCOPE_ORGANIZATION && ! $user->organization_id) {
            $scope = self::SCOPE_OWN;
        }
        if ($scope === self::SCOPE_TEAM && ! $this->hasManagedTeams($user)) {
            $scope = self::SCOPE_OWN;
        }

        // دامنهٔ قابل‌ویرایش: ادمین می‌تواند برای هر کاربر، دامنهٔ محدودتری نسبت به نقشش تنظیم کند.
        $override = config('farast_admin.scope_override_key');
        if (is_string($override) && $override !== '') {
            try {
                $configured = trim((string) \App\Models\SiteSetting::read($override, ''));
            } catch (\Throwable) {
                $configured = '';
            }
            $allowedRank = [self::SCOPE_GLOBAL => 0, self::SCOPE_ORGANIZATION => 1, self::SCOPE_TEAM => 2, self::SCOPE_OWN => 3];
            if (isset($allowedRank[$configured]) && $allowedRank[$configured] >= $allowedRank[$scope]) {
                $scope = $configured;
            }
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

        return match ($user->role) {
            User::ROLE_ADMIN => self::SCOPE_GLOBAL,
            User::ROLE_ORGANIZATION_MANAGER => $user->organization_id ? self::SCOPE_ORGANIZATION : self::SCOPE_OWN,
            User::ROLE_TEAM_SUPERVISOR => self::SCOPE_TEAM,
            default => self::SCOPE_OWN,
        };
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
        $supervised = Team::where('supervisor_id', $user->id)->exists();
        $managerial = $user->teams()
            ->wherePivot('team_role', 'manager')
            ->exists();

        return $supervised || $managerial;
    }

    /**
     * شناسه‌های کاربرانی که کاربر فعلی مجاز به دیدن آن‌هاست.
     * null یعنی «بدون محدودیت» (دامنه سراسری).
     */
    public static function visibleUserIds(?User $user): ?array
    {
        if (! $user || $user->isAdmin()) {
            return null;
        }

        return match ($user->role) {
            User::ROLE_ORGANIZATION_MANAGER => self::organizationUserIds($user),
            User::ROLE_TEAM_SUPERVISOR => self::teamUserIds($user),
            default => [$user->id],
        };
    }

    private static function organizationUserIds(User $user): array
    {
        if (! $user->organization_id) {
            return [$user->id];
        }

        return User::query()
            ->where('organization_id', $user->organization_id)
            ->where('role', '!=', User::ROLE_ADMIN)
            ->pluck('id')
            ->all();
    }

    private static function teamUserIds(User $user): array
    {
        $teamIds = Team::query()
            ->where('supervisor_id', $user->id)
            ->orWhereIn('id', function ($q) use ($user) {
                $q->select('team_id')->from('team_user')
                    ->where('user_id', $user->id)
                    ->where('team_role', 'manager');
            })
            ->pluck('id');

        if ($teamIds->isEmpty()) {
            return [$user->id];
        }

        $memberIds = DB::table('team_user')->whereIn('team_id', $teamIds)->pluck('user_id')->all();

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
