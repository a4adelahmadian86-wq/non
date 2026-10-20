<?php

namespace App\Services;

use App\Http\Middleware\ResolveAdminScope;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * AdminConsoleService — لایهٔ مرکزی «کنسول واحد چنددامنه‌ای».
 *
 * به‌جای داشبوردهای جدا برای هر نقش، همه از همین کنسول استفاده می‌کنند و
 * این سرویس مشخص می‌کند که کاربر جاری:
 *   ۱) چه دامنهٔ داده‌ای دارد (global / organization / team / own)
 *   ۲) کدام بخش‌های کنسول برایش باز است (RBAC + جدول config('farast_admin.sections'))
 *   ۳) کوئری‌ها باید با چه فیلتر دامنه‌ای اجرا شوند.
 */
class AdminConsoleService
{
    /**
     * ثابت‌های دامنه — هم‌ارز با ResolveAdminScope (برای استفادهٔ راحت در کنترلرها/ویوها).
     */
    public const SCOPE_GLOBAL = ResolveAdminScope::SCOPE_GLOBAL;
    public const SCOPE_ORGANIZATION = ResolveAdminScope::SCOPE_ORGANIZATION;
    public const SCOPE_TEAM = ResolveAdminScope::SCOPE_TEAM;
    public const SCOPE_OWN = ResolveAdminScope::SCOPE_OWN;

    public function __construct(private readonly AuthorizationService $authorization)
    {
    }

    public static function scopeOf(?User $user): string
    {
        return ResolveAdminScope::scopeFor($user);
    }

    /**
     * آیا بخش X برای کاربر مجاز است؟ (مجوز RBAC + حداقل دامنه لازم)
     */
    public function sectionAllowed(?User $user, string $section): bool
    {
        if (! $user) {
            return false;
        }

        $def = config("farast_admin.sections.$section");
        if (! is_array($def)) {
            return $user->isAdmin();
        }

        if ($user->isAdmin()) {
            return true;
        }

        if (! $this->authorization->allows($user, $def['permission'])) {
            return false;
        }

        $rank = config('farast_admin.scope_rank', ['global' => 0, 'organization' => 1, 'team' => 2, 'own' => 3]);
        $userRank = $rank[self::scopeOf($user)] ?? 3;
        $requiredRank = $rank[$def['min_scope']] ?? 3;

        // دامنهٔ کاربر باید دست‌کم به اندازهٔ لازم باز باشد (عدد رتبه کوچک‌تر = بازتر).
        return $userRank <= $requiredRank;
    }

    /**
     * فهرست بخش‌های مجاز — برای رندر ناوبری و کنترل‌های رابط.
     *
     * @return array<string,string> section => permission
     */
    public function allowedSections(?User $user): array
    {
        $out = [];
        foreach ((array) config('farast_admin.sections', []) as $section => $def) {
            if ($this->sectionAllowed($user, (string) $section)) {
                $out[(string) $section] = $def['permission'] ?? '';
            }
        }

        return $out;
    }

    /**
     * شناسه کاربران قابل‌مشاهده در دامنهٔ کاربر جاری.
     * null یعنی بدون محدودیت.
     */
    public function visibleUserIds(?User $user): ?array
    {
        return ResolveAdminScope::visibleUserIds($user);
    }

    /**
     * اعمال خودکار دامنه روی هر کوئری دارای user_id.
     */
    public function scopedQuery($query, ?User $user, string $column = 'user_id')
    {
        $ids = $this->visibleUserIds($user);
        if ($ids !== null) {
            $query->whereIn($column, $ids);
        }

        return $query;
    }

    /**
     * گارد عملیاتی برای متدهای نوشتاری کنسول:
     * هم بخش و هم مالکیت رکورد هدف بررسی می‌شود.
     */
    public function authorizeAction(?User $user, string $section, ?int $targetUserId = null): void
    {
        abort_unless($this->sectionAllowed($user, $section), 403, 'اجرای این عملیات در کنسول برای حساب شما مجاز نیست.');

        if ($targetUserId !== null && $user && ! $user->isAdmin()) {
            $ids = $this->visibleUserIds($user);
            abort_if($ids !== null && ! in_array($targetUserId, $ids, true), 403, 'این کاربر خارج از دامنهٔ دسترسی شماست.');
        }
    }

    /**
     * خلاصهٔ دامنه برای نمایش/دیباگ (بدون نشت داده حساس).
     */
    public function scopeSummary(?User $user): array
    {
        $scope = self::scopeOf($user);

        return [
            'scope' => $scope,
            'label' => ResolveAdminScope::scopeLabel($scope),
            'role' => $user?->role,
            'visible_users' => $this->visibleUserIds($user) === null
                ? (Schema::hasTable('users') ? User::count() : null)
                : count($this->visibleUserIds($user) ?? []),
        ];
    }
}
