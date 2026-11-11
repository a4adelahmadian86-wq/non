<?php

namespace App\Services;

use App\Http\Middleware\ResolveAdminScope;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

        $scope = request()->attributes->get('farast.scope') ?? self::scopeOf($user);
        $def = config("farast_admin.sections.$section");
        if (! is_array($def)) {
            return $user->isAdmin() && $scope === self::SCOPE_GLOBAL;
        }

        $rank = config('farast_admin.scope_rank', ['global' => 0, 'organization' => 1, 'team' => 2, 'own' => 3]);
        $userRank = $rank[$scope] ?? 3;
        $requiredRank = $rank[$def['min_scope']] ?? 3;
        $allowed = $userRank <= $requiredRank;

        // A restricted platform administrator keeps administrative capabilities
        // only inside the explicitly assigned scope. Context-free permission
        // checks intentionally fail for such accounts; record guards below
        // enforce the concrete organization/team/owner boundary.
        if ($user->isAdmin() && $scope !== self::SCOPE_GLOBAL) {
            if ($allowed) {
                request()->attributes->set('farast.section_permission', (string) $def['permission']);
            }

            return $allowed;
        }

        if (! $this->authorization->allows($user, $def['permission'])) {
            return false;
        }

        if ($allowed) {
            request()->attributes->set('farast.section_permission', (string) $def['permission']);
        }

        return $allowed;
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
    public function visibleUserIds(?User $user, ?string $permission = null): ?array
    {
        if (! $user) {
            return [];
        }

        $scope = request()->attributes->get('farast.scope');
        $ids = ResolveAdminScope::visibleUserIds($user, is_string($scope) ? $scope : null);
        if ($ids === null) {
            return null;
        }

        $permission ??= request()->attributes->get('farast.section_permission');
        if (! is_string($permission) || $permission === '' || $ids === []) {
            return $ids;
        }

        // Base role scope is only the outer boundary. Custom role assignments
        // and direct deny overrides must also be enforced on every listed user.
        $candidates = User::query()
            ->with('teams:id')
            ->whereIn('id', $ids)
            ->get(['id', 'organization_id']);
        $supervisedTeams = Team::query()
            ->whereIn('supervisor_id', $candidates->pluck('id'))
            ->get(['id', 'supervisor_id'])
            ->groupBy('supervisor_id');

        $visible = [];
        foreach ($candidates as $candidate) {
            $memberTeamIds = $candidate->teams->pluck('id')->map(fn ($id) => (int) $id)->all();
            $supervisedTeamIds = ($supervisedTeams->get($candidate->id) ?? collect())->pluck('id')->map(fn ($id) => (int) $id)->all();
            $context = [
                'owner_id' => (int) $candidate->id,
                'organization_id' => $candidate->organization_id,
                'team_ids' => array_values(array_unique(array_merge($memberTeamIds, $supervisedTeamIds))),
            ];

            if ($this->authorization->allowsFor($user, $permission, $context)) {
                $visible[] = (int) $candidate->id;
            }
        }

        return $visible;
    }
    /**
     * شناسه تیم‌های قابل مشاهده در دامنه جاری؛ null فقط برای دامنه سراسری است.
     */
    public function visibleTeamIds(?User $user, ?string $permission = null): ?array
    {
        if (! $user) {
            return [];
        }

        $scope = request()->attributes->get('farast.scope') ?? self::scopeOf($user);
        if ($scope === self::SCOPE_GLOBAL) {
            return null;
        }
        if ($scope === self::SCOPE_ORGANIZATION) {
            $organizationId = $user->isAdmin() ? ResolveAdminScope::scopeIdFor($user) : $user->organization_id;
            $ids = $organizationId
                ? Team::query()->where('organization_id', $organizationId)->pluck('id')->map(fn ($id) => (int) $id)->all()
                : [];
        } elseif ($scope === self::SCOPE_TEAM && $user->isAdmin()) {
            $teamId = ResolveAdminScope::scopeIdFor($user);
            $ids = $teamId && Team::query()->whereKey($teamId)->exists() ? [$teamId] : [];
        } elseif ($scope === self::SCOPE_TEAM) {
            $ids = $user->organization_id
                ? Team::query()
                    ->where('organization_id', $user->organization_id)
                    ->where(function ($query) use ($user) {
                        $query->where('supervisor_id', $user->id)
                            ->orWhereIn('id', function ($subquery) use ($user) {
                                $subquery->select('team_id')
                                    ->from('team_user')
                                    ->where('user_id', $user->id)
                                    ->whereIn('team_role', ['manager', 'owner', 'supervisor']);
                            });
                    })
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all()
                : [];
        } else {
            return [];
        }

        $permission ??= request()->attributes->get('farast.section_permission');
        if (! is_string($permission) || $permission === '' || $ids === []) {
            return $ids;
        }

        $teams = Team::query()->whereIn('id', $ids)->get(['id', 'organization_id', 'supervisor_id']);
        $visible = [];
        foreach ($teams as $team) {
            $context = [
                'owner_id' => $team->supervisor_id,
                'organization_id' => $team->organization_id,
                'team_id' => (int) $team->id,
            ];
            if ($this->authorization->allowsFor($user, $permission, $context)) {
                $visible[] = (int) $team->id;
            }
        }

        return $visible;
    }
    /**
     * شناسه سازمان‌های قابل مشاهده در دامنه جاری؛ null فقط برای دامنه سراسری است.
     */
    public function visibleOrganizationIds(?User $user, ?string $permission = null): ?array
    {
        if (! $user) {
            return [];
        }

        $scope = request()->attributes->get('farast.scope') ?? self::scopeOf($user);
        if ($scope === self::SCOPE_GLOBAL) {
            return null;
        }
        if ($scope === self::SCOPE_ORGANIZATION) {
            $organizationId = $user->isAdmin() ? ResolveAdminScope::scopeIdFor($user) : $user->organization_id;
            $ids = $organizationId ? [(int) $organizationId] : [];
        } elseif ($scope === self::SCOPE_TEAM) {
            $teamIds = $this->visibleTeamIds($user, $permission) ?? [];
            $ids = Team::query()->whereIn('id', $teamIds)->distinct()->pluck('organization_id')->map(fn ($id) => (int) $id)->all();
        } else {
            return [];
        }

        $permission ??= request()->attributes->get('farast.section_permission');
        if (! is_string($permission) || $permission === '' || $ids === []) {
            return $ids;
        }

        // Preserve the permitted team subset when checking an organization
        // container; a team-scoped grant must not be promoted to whole-org access.
        $permittedTeamIds = $this->visibleTeamIds($user, $permission) ?? [];
        $teamsByOrganization = Team::query()
            ->whereIn('id', $permittedTeamIds)
            ->get(['id', 'organization_id'])
            ->groupBy('organization_id');

        $organizations = \App\Models\Organization::query()->whereIn('id', $ids)->get(['id']);
        $visible = [];
        foreach ($organizations as $organization) {
            $teamIds = ($teamsByOrganization->get($organization->id) ?? collect())
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($this->authorization->allowsFor($user, $permission, [
                'organization_id' => (int) $organization->id,
                'team_ids' => $teamIds,
            ])) {
                $visible[] = (int) $organization->id;
            }
        }

        return $visible;
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
    public function authorizeAction(
        ?User $user,
        string $section,
        ?int $targetUserId = null,
        ?string $requiredPermission = null
    ): void {
        abort_unless($this->sectionAllowed($user, $section), 403, 'اجرای این عملیات در کنسول برای حساب شما مجاز نیست.');

        if ($requiredPermission !== null
            && ! ($user?->isAdmin() && self::scopeOf($user) !== self::SCOPE_GLOBAL)) {
            abort_unless(
                $this->authorization->allows($user, $requiredPermission),
                403,
                'مجوز عملیاتی لازم برای این تغییر در اختیار شما نیست.'
            );
        }

        if ($targetUserId !== null && $user) {
            $sectionPermission = config("farast_admin.sections.$section.permission");
            $sectionIds = $this->visibleUserIds($user, is_string($sectionPermission) ? $sectionPermission : null);
            abort_if(
                $sectionIds !== null && ! in_array($targetUserId, $sectionIds, true),
                403,
                'این کاربر خارج از دامنهٔ مشاهدهٔ شماست.'
            );

            if ($requiredPermission !== null) {
                $actionIds = $this->visibleUserIds($user, $requiredPermission);
                abort_if(
                    $actionIds !== null && ! in_array($targetUserId, $actionIds, true),
                    403,
                    'این کاربر خارج از دامنهٔ عملیاتی شماست.'
                );
            }
        }
    }

    /**
     * خلاصهٔ دامنه برای نمایش/دیباگ (بدون نشت داده حساس).
     */
    public function scopeSummary(?User $user): array
    {
        $scope = request()->attributes->get('farast.scope') ?? self::scopeOf($user);

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
