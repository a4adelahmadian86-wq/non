<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuthorizationService
{
    private array $permissionIds = [];
    private array $decisionCache = [];
    private array $legacyPermissionIds = [];
    private array $overrideRowsByUser = [];
    private array $dynamicGrantsByUser = [];
    private array $teamIdsByUser = [];

    public const PERMISSIONS = [
        'dashboard.view', 'users.view', 'users.create', 'users.edit', 'users.delete', 'users.suspend',
        'documents.view', 'documents.create', 'documents.edit', 'documents.delete', 'documents.export',
        'projects.create', 'ai.use', 'ai.manage', 'ai.view', 'ocr.use', 'voice.use', 'voice.manage',
        'billing.view', 'billing.manage', 'finance.view', 'finance.manage', 'support.manage',
        'settings.manage', 'integrations.manage', 'audit.view', 'access.manage', 'organization.manage',
        'team.view', 'team.manage', 'workflows.view', 'workflows.manage', 'reports.view', 'reports.users',
        'reports.team', 'reports.documents', 'reports.workflows', 'reports.ai', 'reports.resources',
        'system.audit', 'documents.share', 'store.manage',
        'access.roles.manage', 'access.assign', 'access.override', 'access.audit',
        'workflows.create', 'workflows.edit', 'workflows.delete', 'workflows.run', 'workflows.audit',
        'automation.manage', 'automation.run', 'automation.audit',
    ];

    /**
     * Backward-compatible permission check used by existing controllers and middleware.
     * Context-free checks are suitable for navigation; record access must use allowsFor().
     */
    public function allows(?User $user, string $permission): bool
    {
        return $this->decide($user, $permission, []);
    }

    /**
     * Attribute-aware permission check. Supply trusted server-side resource attributes,
     * for example owner_id, organization_id, team_id and resource_status.
     */
    public function allowsFor(?User $user, string $permission, array $context): bool
    {
        return $this->decide($user, $permission, $context);
    }

    /**
     * Explain a decision without exposing permission data belonging to other users.
     */
    public function explain(?User $user, string $permission, array $context = []): array
    {
        if (! $user || $user->is_blocked) {
            return ['allowed' => false, 'reason' => 'inactive_or_blocked', 'source' => null, 'permission' => $permission];
        }

        if ($user->isAdmin()) {
            return ['allowed' => true, 'reason' => 'system_administrator', 'source' => 'system', 'permission' => $permission];
        }

        $permissionId = $this->permissionId($permission);
        if (! $permissionId) {
            return ['allowed' => false, 'reason' => 'unknown_permission', 'source' => null, 'permission' => $permission];
        }

        if (! $this->scopeMatches($user, $this->baseScope($user), null, $context)) {
            return ['allowed' => false, 'reason' => 'outside_base_scope', 'source' => null, 'permission' => $permission];
        }

        $matching = array_values(array_filter(
            $this->overridesFor($user),
            fn ($override) => (int) $override->permission_id === $permissionId
                && $this->scopeMatches($user, $override->scope_type, $override->scope_id, $context)
                && $this->conditionsMatch($override->conditions, $context)
        ));
        $override = collect($matching)->first(fn ($item) => $item->effect === 'deny')
            ?? collect($matching)->first(fn ($item) => $item->effect === 'allow');

        if ($override) {
            return [
                'allowed' => $override->effect === 'allow',
                'reason' => 'direct_'.$override->effect.'_override',
                'source' => 'user_override',
                'permission' => $permission,
                'override_id' => (int) $override->id,
                'scope' => $override->scope_type,
            ];
        }

        if ($this->hasLegacyGrantForContext($user, $permissionId, $context)) {
            return ['allowed' => true, 'reason' => 'legacy_role_grant', 'source' => 'legacy_role', 'permission' => $permission];
        }

        $grant = $this->dynamicGrant($user, $permissionId, $context);

        return [
            'allowed' => $grant !== null,
            'reason' => $grant ? 'assigned_role_grant' : 'no_matching_grant',
            'source' => $grant ? 'assigned_role' : null,
            'permission' => $permission,
            'role_id' => $grant ? (int) $grant->role_id : null,
            'scope' => $grant?->permission_scope_type,
        ];
    }

    public function forgetUser(?int $userId = null): void
    {
        // This service is request-scoped in normal Laravel execution. Clearing the
        // local cache after writes also protects long-running workers and tests.
        $this->decisionCache = [];
        $this->permissionIds = [];
        $this->legacyPermissionIds = [];
        $this->overrideRowsByUser = [];
        $this->dynamicGrantsByUser = [];
        $this->teamIdsByUser = [];
    }

    private function decide(?User $user, string $permission, array $context): bool
    {
        if (! $user || $user->is_blocked) {
            return false;
        }

        // The platform owner remains the immutable break-glass administrator.
        // Data boundaries still apply through AdminConsoleService / resource queries.
        if ($user->isAdmin()) {
            return true;
        }

        $contextKey = $context ? hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') : 'navigation';
        $cacheKey = $user->id.'|'.$user->role.'|'.($user->organization_id ?? 'none').'|'.(int) $user->is_blocked.'|'.$permission.'|'.$contextKey;
        if (array_key_exists($cacheKey, $this->decisionCache)) {
            return $this->decisionCache[$cacheKey];
        }

        $permissionId = $this->permissionId($permission);
        if (! $permissionId) {
            return $this->decisionCache[$cacheKey] = false;
        }

        if (! $this->scopeMatches($user, $this->baseScope($user), null, $context)) {
            return $this->decisionCache[$cacheKey] = false;
        }

        $matching = array_values(array_filter(
            $this->overridesFor($user),
            fn ($override) => (int) $override->permission_id === $permissionId
                && $this->scopeMatches($user, $override->scope_type, $override->scope_id, $context)
                && $this->conditionsMatch($override->conditions, $context)
        ));

        // Explicit deny always wins over every inherited or direct grant.
        if (collect($matching)->contains(fn ($override) => $override->effect === 'deny')) {
            return $this->decisionCache[$cacheKey] = false;
        }
        if (collect($matching)->contains(fn ($override) => $override->effect === 'allow')) {
            return $this->decisionCache[$cacheKey] = true;
        }

        if ($this->hasLegacyGrantForContext($user, $permissionId, $context)) {
            return $this->decisionCache[$cacheKey] = true;
        }

        return $this->decisionCache[$cacheKey] = $this->dynamicGrant($user, $permissionId, $context) !== null;
    }

    private function permissionId(string $permission): ?int
    {
        if (! array_key_exists($permission, $this->permissionIds)) {
            $this->permissionIds[$permission] = Schema::hasTable('permissions')
                ? Permission::query()->where('key', $permission)->value('id')
                : null;
        }

        return $this->permissionIds[$permission] ? (int) $this->permissionIds[$permission] : null;
    }

    private function hasLegacyGrant(User $user, int $permissionId): bool
    {
        if (! array_key_exists($user->role, $this->legacyPermissionIds)) {
            $ids = Schema::hasTable('role_permissions')
                ? RolePermission::query()->where('role', $user->role)->pluck('permission_id')->map(fn ($id) => (int) $id)->all()
                : [];
            $this->legacyPermissionIds[$user->role] = array_fill_keys($ids, true);
        }

        return isset($this->legacyPermissionIds[$user->role][$permissionId]);
    }

    private function baseScope(User $user): string
    {
        $requestUser = request()->user();
        $scope = $requestUser && (int) $requestUser->id === (int) $user->id
            ? request()->attributes->get('farast.scope')
            : null;
        if (! in_array($scope, ['global', 'organization', 'team', 'own'], true)) {
            $scope = \App\Http\Middleware\ResolveAdminScope::scopeFor($user);
        }

        // A supervisor with no managed team is effectively personal-scope.
        if ($scope === 'team'
            && ! $user->teams()->exists()
            && ! \App\Models\Team::query()->where('supervisor_id', $user->id)->exists()) {
            return 'own';
        }

        return $scope;
    }

    private function hasLegacyGrantForContext(User $user, int $permissionId, array $context): bool
    {
        return $this->hasLegacyGrant($user, $permissionId)
            && $this->scopeMatches($user, $this->baseScope($user), null, $context);
    }

    /** Load active direct overrides once per user/request, not once per permission. */
    private function overridesFor(User $user): array
    {
        if (! array_key_exists($user->id, $this->overrideRowsByUser)) {
            $this->overrideRowsByUser[$user->id] = Schema::hasTable('farast_user_permission_overrides')
                ? DB::table('farast_user_permission_overrides')
                    ->where('user_id', $user->id)
                    ->where(function ($query) {
                        $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                    })
                    ->where(function ($query) {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->orderByDesc('id')
                    ->get()
                    ->all()
                : [];
        }

        return $this->overrideRowsByUser[$user->id];
    }

    private function dynamicGrant(User $user, int $permissionId, array $context): ?object
    {
        if (! array_key_exists($user->id, $this->dynamicGrantsByUser)) {
            $this->dynamicGrantsByUser[$user->id] = Schema::hasTable('farast_user_roles')
                && Schema::hasTable('farast_roles')
                && Schema::hasTable('farast_role_permissions')
                ? DB::table('farast_user_roles as ur')
                    ->join('farast_roles as r', 'r.id', '=', 'ur.role_id')
                    ->join('farast_role_permissions as rp', 'rp.role_id', '=', 'r.id')
                    ->where('ur.user_id', $user->id)
                    ->where('r.is_active', true)
                    ->where(function ($query) {
                        $query->whereNull('ur.starts_at')->orWhere('ur.starts_at', '<=', now());
                    })
                    ->where(function ($query) {
                        $query->whereNull('ur.expires_at')->orWhere('ur.expires_at', '>', now());
                    })
                    ->select([
                        'rp.permission_id',
                        'r.id as role_id',
                        'r.scope_type as role_scope_type',
                        'r.scope_id as role_scope_id',
                        'ur.scope_type as assignment_scope_type',
                        'ur.scope_id as assignment_scope_id',
                        'rp.scope_type as permission_scope_type',
                        'rp.conditions as permission_conditions',
                    ])
                    ->orderBy('ur.id')
                    ->get()
                    ->all()
                : [];
        }

        foreach ($this->dynamicGrantsByUser[$user->id] as $assignment) {
            if ((int) $assignment->permission_id !== $permissionId) {
                continue;
            }

            if (! $this->scopeMatches($user, $assignment->role_scope_type, $assignment->role_scope_id, $context)
                || ! $this->scopeMatches($user, $assignment->assignment_scope_type, $assignment->assignment_scope_id, $context)
                || ! $this->scopeMatches($user, $assignment->permission_scope_type, null, $context)
                || ! $this->conditionsMatch($assignment->permission_conditions, $context)) {
                continue;
            }

            return $assignment;
        }

        return null;
    }

    private function scopeMatches(User $user, ?string $scope, mixed $scopeId, array $context): bool
    {
        $scope = $scope ?: 'own';
        $hasResourceContext = $context !== [];

        if ($scope === 'global') {
            return true;
        }

        if ($scope === 'own') {
            if (! $hasResourceContext) {
                return true; // Navigation-level visibility; record checks must provide an owner.
            }

            // Prefer an explicit owner field. A null owner is not permission to
            // fall through to an unrelated actor/user_id attribute.
            $ownerId = null;
            foreach (['owner_id', 'resource_user_id', 'user_id'] as $ownerField) {
                if (array_key_exists($ownerField, $context)) {
                    $ownerId = $context[$ownerField];
                    break;
                }
            }

            return $ownerId !== null && $ownerId !== '' && (int) $ownerId === (int) $user->id;
        }

        if ($scope === 'organization') {
            // Record-level checks must use the record's actual organization.
            // Never infer ownership from the user's organization or the role scope
            // when the resource attribute is absent or explicitly null.
            if ($hasResourceContext && (! array_key_exists('organization_id', $context) || $context['organization_id'] === null || $context['organization_id'] === '')) {
                return false;
            }
            $organizationId = $hasResourceContext
                ? $context['organization_id']
                : ($scopeId ?? $user->organization_id);

            return $organizationId !== null
                && $user->organization_id !== null
                && (int) $organizationId === (int) $user->organization_id
                && ($scopeId === null || (int) $scopeId === (int) $organizationId);
        }

        if ($scope === 'team') {
            // A record must belong to an explicitly shared team. Cache the
            // actor's memberships once per request to keep list filtering bounded.
            if ($hasResourceContext) {
                if (isset($context['team_ids']) && is_array($context['team_ids'])) {
                    $resourceTeamIds = array_values(array_filter(array_map('intval', $context['team_ids']), fn ($id) => $id > 0));
                } elseif (array_key_exists('team_id', $context) && $context['team_id'] !== null && $context['team_id'] !== '') {
                    $resourceTeamIds = [(int) $context['team_id']];
                } else {
                    return false;
                }
            } elseif ($scopeId !== null) {
                $resourceTeamIds = [(int) $scopeId];
            } else {
                $resourceTeamIds = $this->teamIdsForUser($user);
            }

            $actorTeamIds = $this->teamIdsForUser($user);
            foreach (array_unique($resourceTeamIds) as $teamId) {
                if ($teamId > 0
                    && ($scopeId === null || (int) $scopeId === $teamId)
                    && in_array($teamId, $actorTeamIds, true)) {
                    return true;
                }
            }

            return false;
        }
        return false;
    }

    /** Cache the authenticated user's team memberships and supervised teams per request. */
    private function teamIdsForUser(User $user): array
    {
        if (! array_key_exists($user->id, $this->teamIdsByUser)) {
            $memberIds = $user->teams()->pluck('teams.id')->map(fn ($id) => (int) $id)->all();
            $supervisedIds = \App\Models\Team::query()
                ->where('supervisor_id', $user->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $this->teamIdsByUser[$user->id] = array_values(array_unique(array_merge($memberIds, $supervisedIds)));
        }

        return $this->teamIdsByUser[$user->id];
    }

    /**
     * Conditions are a deliberately small, non-executable DSL. No eval, arbitrary
     * callbacks, SQL fragments, or user-provided code are accepted.
     */
    private function conditionsMatch(mixed $rawConditions, array $context): bool
    {
        if ($rawConditions === null || $rawConditions === '') {
            return true;
        }

        $conditions = is_array($rawConditions) ? $rawConditions : json_decode((string) $rawConditions, true);
        if (! is_array($conditions)) {
            return false;
        }

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                return false;
            }

            $field = (string) ($condition['field'] ?? '');
            $operator = (string) ($condition['operator'] ?? 'equals');
            if ($field === '' || ! preg_match('/^[a-zA-Z0-9_.]+$/', $field)) {
                return false;
            }

            $present = array_key_exists($field, $context)
                || data_get($context, $field, '__farast_missing__') !== '__farast_missing__';
            $actual = $present ? data_get($context, $field) : null;
            $expected = $condition['value'] ?? null;
            $matched = match ($operator) {
                'exists' => $present && $actual !== null && $actual !== '',
                'equals' => $present && $this->sameScalarValue($actual, $expected),
                'not_equals' => $present && ! $this->sameScalarValue($actual, $expected),
                'in' => is_array($expected) && in_array($actual, $expected, true),
                'not_in' => is_array($expected) && ! in_array($actual, $expected, true),
                'contains' => is_array($actual) ? in_array($expected, $actual, true) : (is_string($actual) && is_string($expected) && str_contains($actual, $expected)),
                'greater_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
                'greater_or_equal' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
                'less_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
                'less_or_equal' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
                default => false,
            };

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    private function sameScalarValue(mixed $actual, mixed $expected): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === $expected;
        }
        if (! is_scalar($actual) || ! is_scalar($expected)) {
            return false;
        }
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        return (string) $actual === (string) $expected;
    }

    public function syncDefaults(): void
    {
        $labels = [
            'dashboard.view' => 'داشبورد',
            'users.view' => 'مشاهده کاربران',
            'users.create' => 'ایجاد کاربر',
            'users.edit' => 'ویرایش کاربر',
            'users.delete' => 'حذف کاربر',
            'users.suspend' => 'مسدودسازی',
            'documents.view' => 'مشاهده اسناد',
            'documents.create' => 'ایجاد سند',
            'documents.edit' => 'ویرایش سند',
            'documents.delete' => 'حذف سند',
            'documents.export' => 'خروجی اسناد',
            'projects.create' => 'ایجاد پروژه',
            'ai.use' => 'استفاده از هوش مصنوعی',
            'ai.manage' => 'مدیریت هوش مصنوعی',
            'ai.view' => 'گزارش هوش مصنوعی',
            'ocr.use' => 'استخراج متن (OCR)',
            'voice.use' => 'تایپ صوتی',
            'voice.manage' => 'مدیریت سرویس صوتی',
            'billing.view' => 'مشاهده صورتحساب',
            'billing.manage' => 'مدیریت صورتحساب',
            'finance.view' => 'مشاهده گزارش مالی',
            'finance.manage' => 'مدیریت عملیات مالی',
            'support.manage' => 'مدیریت پشتیبانی',
            'settings.manage' => 'مدیریت تنظیمات',
            'integrations.manage' => 'مدیریت یکپارچه‌سازی',
            'audit.view' => 'مشاهده ممیزی',
            'access.manage' => 'مدیریت دسترسی',
            'organization.manage' => 'مدیریت سازمان',
            'team.view' => 'مشاهده تیم',
            'team.manage' => 'مدیریت تیم',
            'workflows.view' => 'مشاهده گردش‌کار',
            'workflows.manage' => 'مدیریت گردش‌کار',
            'reports.view' => 'گزارش‌ها',
            'reports.users' => 'گزارش کاربران',
            'reports.team' => 'گزارش تیم',
            'reports.documents' => 'گزارش اسناد',
            'reports.workflows' => 'گزارش گردش‌کار',
            'reports.ai' => 'گزارش هوش مصنوعی',
            'reports.resources' => 'گزارش منابع',
            'system.audit' => 'نظارت سیستم',
            'documents.share' => 'اشتراک‌گذاری اسناد',
            'store.manage' => 'مدیریت محصولات و دوره‌ها',
            'access.roles.manage' => 'ایجاد و مدیریت نقش‌ها',
            'access.assign' => 'انتساب نقش به کاربران',
            'access.override' => 'مدیریت دسترسی مستقیم کاربران',
            'access.audit' => 'مشاهده تاریخچه دسترسی',
            'workflows.create' => 'ایجاد گردش‌کار',
            'workflows.edit' => 'ویرایش گردش‌کار',
            'workflows.delete' => 'حذف گردش‌کار',
            'workflows.run' => 'اجرای گردش‌کار',
            'workflows.audit' => 'مشاهده تاریخچه گردش‌کار',
            'automation.manage' => 'مدیریت اتوماسیون',
            'automation.run' => 'اجرای اتوماسیون',
            'automation.audit' => 'ممیزی اتوماسیون',
        ];

        foreach (self::PERMISSIONS as $key) {
            Permission::firstOrCreate(
                ['key' => $key],
                ['label' => $labels[$key] ?? $key, 'group' => str($key)->before('.')->toString()]
            );
        }

        $all = Permission::query()->pluck('id', 'key');
        $roles = [
            'user' => ['dashboard.view', 'documents.view', 'documents.create', 'documents.edit', 'documents.delete', 'documents.share', 'documents.export', 'projects.create', 'ai.use', 'ocr.use', 'voice.use', 'billing.view', 'team.view'],
            'organization_manager' => ['dashboard.view', 'users.view', 'users.edit', 'documents.view', 'documents.create', 'documents.edit', 'documents.delete', 'documents.share', 'documents.export', 'projects.create', 'ai.use', 'ai.view', 'ocr.use', 'voice.use', 'billing.view', 'billing.manage', 'workflows.view', 'workflows.manage', 'support.manage', 'organization.manage', 'team.view', 'team.manage', 'reports.view', 'reports.users', 'reports.team', 'reports.documents', 'reports.ai', 'reports.resources'],
            'team_supervisor' => ['dashboard.view', 'users.view', 'documents.view', 'documents.create', 'documents.edit', 'documents.delete', 'documents.export', 'projects.create', 'ai.use', 'ai.view', 'ocr.use', 'voice.use', 'workflows.view', 'team.view', 'reports.team', 'reports.documents', 'documents.share'],
            'employee' => ['dashboard.view', 'documents.view', 'documents.create', 'documents.edit', 'documents.delete', 'documents.share', 'documents.export', 'projects.create', 'ai.use', 'ocr.use', 'voice.use', 'team.view'],
            'observer' => ['dashboard.view', 'documents.view', 'ai.use', 'reports.view'],
            'guest' => ['dashboard.view', 'documents.view'],
        ];

        foreach ($roles as $role => $permissions) {
            foreach ($permissions as $key) {
                if (isset($all[$key])) {
                    RolePermission::firstOrCreate(['role' => $role, 'permission_id' => $all[$key]]);
                }
            }
        }

        $this->forgetUser();
    }
}
