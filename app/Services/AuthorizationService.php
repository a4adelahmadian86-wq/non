<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

/**
 * Central authorization decision point.
 *
 * Legacy role_permissions remain supported during migration. New assignments
 * and direct grants are evaluated first. Permission decisions are memoized only
 * within this service instance and invalidated after audited access changes.
 */
class AuthorizationService
{
    public const SCOPES = ['global', 'organization', 'team', 'own'];

    private array $permissionIds = [];
    private array $decisionCache = [];

    public const PERMISSIONS = [
        'dashboard.view','users.view','users.create','users.edit','users.delete','users.suspend',
        'documents.view','documents.create','documents.edit','documents.delete','documents.export',
        'projects.create','ai.use','ai.manage','ai.view','ocr.use','voice.use','voice.manage',
        'billing.view','billing.manage','finance.view','finance.manage','support.manage',
        'settings.manage','integrations.manage','audit.view','access.manage','organization.manage',
        'team.view','team.manage','workflows.view','workflows.manage','reports.view','reports.users',
        'reports.team','reports.documents','reports.workflows','reports.ai','reports.resources',
        'system.audit','documents.share','store.manage','store.products.publish',
    ];

    /**
     * Conservative effective scope for the existing admin console.
     * Multiple assignments are combined using the narrowest active boundary,
     * so an additional broad role cannot widen an already restricted account.
     */
    public function effectiveScope(?User $user): string
    {
        if (! $user || $user->is_blocked) {
            return 'own';
        }

        $assignments = $this->activeAssignments($user);
        if ($assignments->isEmpty()) {
            return $user->access_control_managed ? 'own' : $this->legacyScope($user);
        }

        $rank = ['global' => 0, 'organization' => 1, 'team' => 2, 'own' => 3];
        return $assignments->pluck('scope')
            ->sortByDesc(fn ($scope) => $rank[$scope] ?? 4)
            ->first() ?: 'own';
    }

    /**
     * Explicit organization assignments constrain administrator queries.
     * null means use the actor's legacy organization context; an empty array
     * means an explicit assignment exists but contains no valid organization.
     */
    public function effectiveOrganizationIds(User $user): ?array
    {
        $assignments = $this->activeAssignments($user)->where('scope', 'organization');

        if ($assignments->isEmpty()) {
            return null;
        }

        return $assignments->pluck('organization_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->filter(fn (int $id): bool => $id > 0 && DB::table('organizations')->where('id', $id)->exists())
            ->values()
            ->all();
    }

    /**
     * Explicit team assignments constrain the existing console's team queries.
     * null means use the legacy supervisor/manager membership resolution.
     */
    public function effectiveTeamIds(User $user): ?array
    {
        $assignments = $this->activeAssignments($user)->where('scope', 'team');

        if ($assignments->isEmpty()) {
            return null;
        }

        $organizationIds = $user->isAdmin() ? $this->effectiveOrganizationIds($user) : null;
        if ($user->isAdmin() && $organizationIds === []) {
            return [];
        }

        return $assignments->map(function (object $assignment) use ($user, $organizationIds): ?int {
            $teamId = (int) ($assignment->team_id ?? 0);
            if ($teamId < 1) {
                return null;
            }

            $teamQuery = \App\Models\Team::query()->whereKey($teamId);
            if ($assignment->organization_id !== null) {
                $teamQuery->where('organization_id', (int) $assignment->organization_id);
            }
            if ($user->isAdmin() && $organizationIds !== null) {
                $teamQuery->whereIn('organization_id', $organizationIds);
            } elseif (! $user->isAdmin() && $user->organization_id !== null) {
                $teamQuery->where('organization_id', (int) $user->organization_id);
            }

            if (! $teamQuery->exists()) {
                return null;
            }

            // An explicit scoped assignment is the authority for an administrator;
            // unlike an employee, the administrator need not be a team member.
            if (! $user->isAdmin() && ! $this->userHasTeamAccess($user, $teamId)) {
                return null;
            }

            return $teamId;
        })->filter()->unique()->values()->all();
    }

    public function allows(?User $user, string $permission): bool
    {
        if (! $this->activeUser($user) || trim($permission) === '') {
            return false;
        }

        $cacheKey = implode(':', [
            (string) $user->id,
            (string) $user->role,
            (string) $user->is_blocked,
            (string) ($user->organization_id ?? ''),
            (string) $user->access_control_managed,
            $permission,
        ]);
        if (array_key_exists($cacheKey, $this->decisionCache)) {
            return $this->decisionCache[$cacheKey];
        }

        $decision = $this->resolvePermissionDecision($user, $permission);
        $this->decisionCache[$cacheKey] = $decision;

        return $decision;
    }

    /**
     * Evaluate a section permission against the complete effective console scope.
     *
     * The generic allows() method answers whether any grant exists. A section
     * query can expose several organizations or teams, so a scoped grant must
     * cover every record set represented by that query.
     */
    public function allowsWithinEffectiveScope(?User $user, string $permission, ?string $effectiveScope = null): bool
    {
        if (! $this->activeUser($user) || trim($permission) === '' || ! $this->allows($user, $permission)) {
            return false;
        }

        $scope = in_array($effectiveScope, self::SCOPES, true)
            ? $effectiveScope
            : $this->effectiveScope($user);

        if ($this->rolePermissionAllowsInEffectiveScope($user, $permission, $scope)) {
            return true;
        }

        $allowGrants = $this->activeDirectGrants($user, $permission)
            ->where('effect', 'allow');
        if ($allowGrants->isEmpty()) {
            return false;
        }

        if ($allowGrants->contains(fn (object $grant): bool => $grant->scope === 'global')) {
            return true;
        }

        $grantedOrganizationIds = $allowGrants
            ->where('scope', 'organization')
            ->pluck('organization_id')
            ->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($scope === 'organization') {
            $visibleOrganizationIds = $this->consoleOrganizationIds($user);

            return $visibleOrganizationIds !== []
                && array_diff($visibleOrganizationIds, $grantedOrganizationIds) === [];
        }

        if ($scope === 'team') {
            $visibleTeamIds = $this->consoleTeamIds($user);
            if ($visibleTeamIds === []) {
                return false;
            }

            $visibleTeams = Team::query()
                ->whereIn('id', $visibleTeamIds)
                ->get(['id', 'organization_id']);
            if ($visibleTeams->count() !== count($visibleTeamIds)) {
                return false;
            }

            $teamGrants = $allowGrants->where('scope', 'team');

            return $visibleTeams->every(
                fn (Team $team): bool => in_array((int) $team->organization_id, $grantedOrganizationIds, true)
                    || $teamGrants->contains(
                        fn (object $grant): bool => $grant->team_id !== null
                            && $grant->organization_id !== null
                            && (int) $grant->team_id === (int) $team->id
                            && (int) $grant->organization_id === (int) $team->organization_id
                    )
            );
        }

        if ($scope === 'own') {
            if ($allowGrants->contains(fn (object $grant): bool => $grant->scope === 'own')) {
                return true;
            }

            return $user->organization_id !== null
                && in_array((int) $user->organization_id, $grantedOrganizationIds, true);
        }

        return false;
    }

    private function rolePermissionAllowsInEffectiveScope(User $user, string $permission, string $scope): bool
    {
        $assignments = $this->activeAssignments($user);
        if ($assignments->isEmpty()) {
            if ($user->access_control_managed) {
                return false;
            }

            if ($user->role === User::ROLE_ADMIN) {
                return true;
            }

            $permissionId = $this->permissionId($permission);

            return $permissionId !== null && RolePermission::query()
                ->where('role', $user->role)
                ->where('permission_id', $permissionId)
                ->exists();
        }

        $permissionId = $this->permissionId($permission);
        if ($permissionId === null) {
            return false;
        }

        $roleKeysWithPermission = RolePermission::query()
            ->where('permission_id', $permissionId)
            ->pluck('role')
            ->flip();
        $permissionAssignments = $assignments->filter(
            fn (object $assignment): bool => $roleKeysWithPermission->has($assignment->role_key)
        );
        if ($permissionAssignments->isEmpty()) {
            return false;
        }

        if ($scope === 'global') {
            return $permissionAssignments->contains(
                fn (object $assignment): bool => $assignment->scope === 'global'
            );
        }

        if ($scope === 'organization') {
            $visibleOrganizationIds = $this->consoleOrganizationIds($user);
            if ($visibleOrganizationIds === []) {
                return false;
            }

            return collect($visibleOrganizationIds)->every(
                fn (int $organizationId): bool => $permissionAssignments->contains(
                    fn (object $assignment): bool => $assignment->scope === 'global'
                        || ($assignment->scope === 'organization'
                            && (int) $assignment->organization_id === $organizationId)
                )
            );
        }

        if ($scope === 'own') {
            return $permissionAssignments->contains(function (object $assignment) use ($user): bool {
                if ($assignment->scope === 'global') {
                    return true;
                }

                if ($assignment->scope === 'organization') {
                    return $user->organization_id !== null
                        && (int) $assignment->organization_id === (int) $user->organization_id;
                }

                return $assignment->scope === 'own';
            });
        }

        if ($scope === 'team') {
            $visibleTeamIds = $this->consoleTeamIds($user);
            if ($visibleTeamIds === []) {
                return false;
            }

            $visibleTeams = Team::query()
                ->whereIn('id', $visibleTeamIds)
                ->get(['id', 'organization_id']);
            if ($visibleTeams->count() !== count($visibleTeamIds)) {
                return false;
            }

            return $visibleTeams->every(function (Team $team) use ($permissionAssignments): bool {
                return $permissionAssignments->contains(function (object $assignment) use ($team): bool {
                    return $assignment->scope === 'global'
                        || ($assignment->scope === 'organization'
                            && (int) $assignment->organization_id === (int) $team->organization_id)
                        || ($assignment->scope === 'team'
                            && (int) $assignment->team_id === (int) $team->id
                            && (int) $assignment->organization_id === (int) $team->organization_id);
                });
            });
        }

        return false;
    }

    private function consoleOrganizationIds(User $user): array
    {
        $assigned = $this->effectiveOrganizationIds($user);
        if ($assigned !== null) {
            return collect($assigned)->map(fn ($id): int => (int) $id)->unique()->values()->all();
        }

        return $user->organization_id !== null ? [(int) $user->organization_id] : [];
    }

    /**
     * Resolve every team represented by the current console scope. Explicit
     * assignments take precedence; legacy supervisors/managers retain their
     * existing team membership behavior during migration.
     */
    private function consoleTeamIds(User $user): array
    {
        $assigned = $this->effectiveTeamIds($user);
        if ($assigned !== null) {
            return collect($assigned)->map(fn ($id): int => (int) $id)->unique()->values()->all();
        }

        return Team::query()
            ->where('supervisor_id', $user->id)
            ->orWhereIn('id', function ($query) use ($user): void {
                $query->select('team_id')
                    ->from('team_user')
                    ->where('user_id', $user->id)
                    ->where('team_role', 'manager');
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function resolvePermissionDecision(User $user, string $permission): bool
    {
        $direct = $this->activeDirectGrants($user, $permission);
        if ($direct->contains(fn (object $grant): bool => $grant->effect === 'deny')) {
            return false;
        }
        if ($direct->contains(fn (object $grant): bool => $grant->effect === 'allow')) {
            return true;
        }

        $assignments = $this->activeAssignments($user);
        if ($assignments->isNotEmpty()) {
            return $this->assignedRoleAllows($assignments, $permission);
        }

        if ($user->access_control_managed) {
            return false;
        }

        // Compatibility for users not yet migrated to scoped access control.
        if ($user->role === User::ROLE_ADMIN) {
            return true;
        }

        $permissionId = $this->permissionId($permission);
        return $permissionId !== null
            && RolePermission::query()->where('role', $user->role)
                ->where('permission_id', $permissionId)->exists();
    }

    /**
     * Authorize an operation against a concrete record. A role-level permission
     * alone is never enough: its scope must also contain the record.
     */
    public function allowsRecord(?User $user, string $permission, Model $record): bool
    {
        if (! $this->activeUser($user) || trim($permission) === '') {
            return false;
        }

        $direct = $this->activeDirectGrants($user, $permission)
            ->filter(fn (object $grant): bool => $this->scopeContains($user, $grant->scope, $grant, $record));

        if ($direct->contains(fn (object $grant): bool => $grant->effect === 'deny')) {
            return false;
        }
        if ($direct->contains(fn (object $grant): bool => $grant->effect === 'allow')) {
            return true;
        }

        $activeAssignments = $this->activeAssignments($user);
        if ($activeAssignments->isNotEmpty()) {
            $inScope = $activeAssignments
                ->filter(fn (object $assignment): bool => $this->scopeContains($user, $assignment->scope, $assignment, $record));

            return $inScope->isNotEmpty() && $this->assignedRoleAllows($inScope, $permission);
        }

        if ($user->access_control_managed) {
            return false;
        }

        if ($user->role === User::ROLE_ADMIN) {
            return true;
        }

        $permissionId = $this->permissionId($permission);
        if ($permissionId === null || ! RolePermission::query()
            ->where('role', $user->role)->where('permission_id', $permissionId)->exists()) {
            return false;
        }

        return $this->scopeContains($user, $this->legacyScope($user), null, $record);
    }

    public function createRole(User $actor, string $key, string $name, array $permissionKeys, ?Request $request = null): object
    {
        abort_unless($this->canManageScope($actor, $actor, 'global', null, null), 403);
        $key = trim($key);
        $name = trim($name);
        abort_unless((bool) preg_match('/^[a-z][a-z0-9_.-]{2,99}$/', $key), 422, 'کلید نقش معتبر نیست.');
        abort_unless($name !== '' && mb_strlen($name) <= 160, 422, 'نام نقش معتبر نیست.');
        abort_if(in_array($key, ['admin', 'user', 'employee', 'organization_manager', 'team_supervisor', 'observer', 'guest'], true), 422, 'این کلید برای نقش سیستمی رزرو شده است.');

        return DB::transaction(function () use ($actor, $key, $name, $permissionKeys, $request): object {
            $roleId = DB::table('roles')->insertGetId([
                'key' => $key,
                'name' => $name,
                'is_system' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (array_values(array_unique($permissionKeys)) as $permissionKey) {
                $permissionId = $this->permissionId((string) $permissionKey);
                abort_unless($permissionId !== null, 422, 'مجوز تعریف‌نشده: '.(string) $permissionKey);
                RolePermission::firstOrCreate(['role' => $key, 'permission_id' => $permissionId]);
            }

            $this->audit('role.created', $actor, null, null, ['role_key' => $key, 'permissions' => array_values(array_unique($permissionKeys))], $request);
            return DB::table('roles')->where('id', $roleId)->first();
        });
    }

    public function updateRole(
        User $actor,
        string $key,
        string $name,
        array $permissionKeys,
        ?bool $isActive = null,
        ?Request $request = null,
    ): object {
        abort_unless($this->canManageScope($actor, $actor, 'global', null, null), 403);
        $role = DB::table('roles')->where('key', $key)->first();
        abort_unless($role, 404);
        $isActive ??= (bool) $role->is_active;
        abort_if((bool) $role->is_system, 403, 'نقش سیستمی قابل ویرایش نیست.');
        $name = trim($name);
        abort_unless($name !== '' && mb_strlen($name) <= 160, 422, 'نام نقش معتبر نیست.');

        $previousPermissionKeys = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role', $key)
            ->pluck('permissions.key')
            ->all();
        $permissionKeys = array_values(array_unique($permissionKeys));
        $permissionIds = [];
        foreach ($permissionKeys as $permissionKey) {
            $permissionId = $this->permissionId((string) $permissionKey);
            abort_unless($permissionId !== null, 422, 'مجوز تعریف‌نشده: '.(string) $permissionKey);
            $permissionIds[] = $permissionId;
        }

        return DB::transaction(function () use ($actor, $role, $key, $name, $permissionIds, $permissionKeys, $previousPermissionKeys, $isActive, $request): object {
            DB::table('roles')->where('id', $role->id)->update([
                'name' => $name,
                'is_active' => $isActive,
                'updated_at' => now(),
            ]);
            RolePermission::query()->where('role', $key)->delete();

            foreach ($permissionIds as $permissionId) {
                RolePermission::firstOrCreate(['role' => $key, 'permission_id' => $permissionId]);
            }

            $this->audit('role.updated', $actor, null, null, [
                'role_key' => $key,
                'is_active' => $isActive,
                'previous_permissions' => $previousPermissionKeys,
                'permissions' => $permissionKeys,
            ], $request);

            return DB::table('roles')->where('id', $role->id)->first();
        });
    }

    public function updateRolePermissions(User $actor, string $role, array $permissionKeys, ?Request $request = null): void
    {
        abort_unless($this->canManageScope($actor, $actor, 'global', null, null), 403);
        abort_unless(in_array($role, User::DASHBOARD_ROLES, true) && $role !== User::ROLE_ADMIN, 422);

        $permissionKeys = array_values(array_unique($permissionKeys));
        $permissionIds = [];
        foreach ($permissionKeys as $permissionKey) {
            $permissionId = $this->permissionId((string) $permissionKey);
            abort_unless($permissionId !== null, 422, 'مجوز تعریف‌نشده: '.(string) $permissionKey);
            $permissionIds[] = $permissionId;
        }

        $previousPermissionKeys = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role', $role)
            ->pluck('permissions.key')
            ->all();

        DB::transaction(function () use ($actor, $role, $permissionKeys, $permissionIds, $previousPermissionKeys, $request): void {
            RolePermission::query()->where('role', $role)->delete();
            foreach ($permissionIds as $permissionId) {
                RolePermission::firstOrCreate(['role' => $role, 'permission_id' => $permissionId]);
            }

            $this->audit('role.permissions.updated', $actor, null, null, [
                'role_key' => $role,
                'previous_permissions' => $previousPermissionKeys,
                'permissions' => $permissionKeys,
            ], $request);
        });
    }

    public function assignRole(
        User $actor,
        User $subject,
        string $roleKey,
        string $scope,
        ?int $organizationId = null,
        ?int $teamId = null,
        ?\DateTimeInterface $startsAt = null,
        ?\DateTimeInterface $expiresAt = null,
        ?Request $request = null,
    ): object {
        abort_unless(in_array($scope, self::SCOPES, true), 422, 'محدوده دسترسی معتبر نیست.');
        abort_unless($this->validScopeContext($scope, $organizationId, $teamId), 422, 'پارامترهای محدوده دسترسی کامل یا سازگار نیستند.');
        abort_unless($this->canManageScope($actor, $subject, $scope, $organizationId, $teamId), 403);
        abort_if($expiresAt && $startsAt && $expiresAt <= $startsAt, 422, 'زمان انقضا باید بعد از زمان شروع باشد.');

        return DB::transaction(function () use ($actor, $subject, $roleKey, $scope, $organizationId, $teamId, $startsAt, $expiresAt, $request): object {
            $role = DB::table('roles')->where('key', $roleKey)->where('is_active', true)->first();
            abort_unless($role, 404, 'نقش فعال پیدا نشد.');

            DB::table('users')->where('id', $subject->id)->update([
                'access_control_managed' => true,
                'updated_at' => now(),
            ]);
            $subject->setAttribute('access_control_managed', true);

            $id = DB::table('role_assignments')->insertGetId([
                'user_id' => $subject->id,
                'role_id' => $role->id,
                'scope' => $scope,
                'organization_id' => $organizationId,
                'team_id' => $teamId,
                'assigned_by' => $actor->id,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit('role.assignment.created', $actor, $subject, null, [
                'role_key' => $roleKey,
                'scope' => $scope,
                'organization_id' => $organizationId,
                'team_id' => $teamId,
                'expires_at' => $expiresAt,
            ], $request);

            return DB::table('role_assignments')->where('id', $id)->first();
        });
    }

    public function grantDirectPermission(
        User $actor,
        User $subject,
        string $permission,
        string $effect,
        string $scope,
        ?int $organizationId = null,
        ?int $teamId = null,
        ?\DateTimeInterface $startsAt = null,
        ?\DateTimeInterface $expiresAt = null,
        ?Request $request = null,
    ): object {
        abort_unless(in_array($effect, ['allow', 'deny'], true), 422, 'نوع مجوز مستقیم معتبر نیست.');
        abort_unless(in_array($scope, self::SCOPES, true), 422, 'محدوده دسترسی معتبر نیست.');
        abort_unless($this->validScopeContext($scope, $organizationId, $teamId), 422, 'پارامترهای محدوده دسترسی کامل یا سازگار نیستند.');
        abort_unless($this->canManageScope($actor, $subject, $scope, $organizationId, $teamId), 403);
        abort_if($expiresAt && $startsAt && $expiresAt <= $startsAt, 422, 'زمان انقضا باید بعد از زمان شروع باشد.');
        $permissionId = $this->permissionId($permission);
        abort_unless($permissionId !== null, 404, 'مجوز پیدا نشد.');

        return DB::transaction(function () use ($actor, $subject, $permission, $permissionId, $effect, $scope, $organizationId, $teamId, $startsAt, $expiresAt, $request): object {
            if ($subject->role === User::ROLE_ADMIN) {
                DB::table('users')->where('id', $subject->id)->update([
                    'access_control_managed' => true,
                    'updated_at' => now(),
                ]);
                $subject->setAttribute('access_control_managed', true);
            }

            $id = DB::table('user_permission_grants')->insertGetId([
                'user_id' => $subject->id,
                'permission_id' => $permissionId,
                'effect' => $effect,
                'scope' => $scope,
                'organization_id' => $organizationId,
                'team_id' => $teamId,
                'granted_by' => $actor->id,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit('permission.direct_'.$effect, $actor, $subject, null, [
                'permission' => $permission,
                'scope' => $scope,
                'organization_id' => $organizationId,
                'team_id' => $teamId,
                'expires_at' => $expiresAt,
            ], $request);

            return DB::table('user_permission_grants')->where('id', $id)->first();
        });
    }

    public function revokeRoleAssignment(User $actor, int $assignmentId, ?Request $request = null): void
    {
        $assignment = DB::table('role_assignments')->where('id', $assignmentId)->first();
        abort_unless($assignment, 404);
        $subject = User::query()->findOrFail($assignment->user_id);
        abort_unless($this->canManageScope($actor, $subject, $assignment->scope, $assignment->organization_id, $assignment->team_id), 403);

        DB::transaction(function () use ($actor, $subject, $assignment, $request): void {
            DB::table('role_assignments')->where('id', $assignment->id)->update(['expires_at' => now(), 'updated_at' => now()]);
            $this->audit('role.assignment.revoked', $actor, $subject, null, [
                'assignment_id' => $assignment->id,
                'role_id' => $assignment->role_id,
            ], $request);
        });
    }

    public function revokeDirectPermission(User $actor, int $grantId, ?Request $request = null): void
    {
        $grant = DB::table('user_permission_grants')->where('id', $grantId)->first();
        abort_unless($grant, 404);
        $subject = User::query()->findOrFail($grant->user_id);
        abort_unless($this->canManageScope($actor, $subject, $grant->scope, $grant->organization_id, $grant->team_id), 403);

        DB::transaction(function () use ($actor, $subject, $grant, $request): void {
            DB::table('user_permission_grants')->where('id', $grant->id)->update([
                'expires_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit('permission.direct_revoked', $actor, $subject, null, [
                'grant_id' => $grant->id,
                'permission_id' => $grant->permission_id,
                'effect' => $grant->effect,
            ], $request);
        });
    }

    private function validScopeContext(string $scope, ?int $organizationId, ?int $teamId): bool
    {
        return match ($scope) {
            'global', 'own' => $organizationId === null && $teamId === null,
            'organization' => $organizationId !== null && $teamId === null,
            'team' => $teamId !== null,
            default => false,
        };
    }

    private function canManageScope(User $actor, User $subject, string $scope, ?int $organizationId, ?int $teamId): bool
    {
        if (! $this->allows($actor, 'access.manage')) {
            return false;
        }

        $rank = ['global' => 0, 'organization' => 1, 'team' => 2, 'own' => 3];
        $accessPermissionId = $this->permissionId('access.manage');
        $actorAssignments = $this->activeAssignments($actor);
        $manageableAssignments = $accessPermissionId === null
            ? collect()
            : $actorAssignments->filter(
                fn (object $assignment): bool => RolePermission::query()
                    ->where('role', $assignment->role_key)
                    ->where('permission_id', $accessPermissionId)
                    ->exists()
            );
        $directManagementGrants = $this->activeDirectGrants($actor, 'access.manage')
            ->where('effect', 'allow');

        $manageableScopes = $manageableAssignments->pluck('scope')
            ->merge($directManagementGrants->pluck('scope'));
        $actorScope = $manageableScopes->isNotEmpty()
            ? $manageableScopes->sortByDesc(fn ($value) => $rank[$value] ?? 4)->first()
            : ($this->activeAssignments($actor)->isEmpty() && ! $actor->access_control_managed ? $this->legacyScope($actor) : 'own');

        // A broad access.manage grant must never override a narrower role boundary.
        // With no role assignments, an explicit direct management grant defines
        // the management scope instead of the implicit own-only managed fallback.
        $effectiveScope = $actorAssignments->isNotEmpty()
            ? $this->effectiveScope($actor)
            : ($directManagementGrants->isNotEmpty() ? $actorScope : $this->effectiveScope($actor));
        if (($rank[$effectiveScope] ?? 4) > ($rank[$actorScope] ?? 4)) {
            $actorScope = $effectiveScope;
        }

        if (! isset($rank[$actorScope], $rank[$scope]) || $rank[$scope] < $rank[$actorScope]) {
            return false;
        }

        $hasExplicitOrganizationManagementScope = $manageableAssignments->contains('scope', 'organization')
            || $directManagementGrants->contains('scope', 'organization');
        $manageableOrganizationIds = $manageableAssignments->where('scope', 'organization')->pluck('organization_id')
            ->merge($directManagementGrants->where('scope', 'organization')->pluck('organization_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $effectiveOrganizationIds = $this->effectiveOrganizationIds($actor);
        if ($manageableOrganizationIds->isNotEmpty() && $effectiveOrganizationIds !== null) {
            $manageableOrganizationIds = $manageableOrganizationIds
                ->filter(fn (int $id): bool => in_array($id, $effectiveOrganizationIds, true))
                ->values();
        }

        $hasExplicitTeamManagementScope = $manageableAssignments->contains('scope', 'team')
            || $directManagementGrants->contains('scope', 'team');
        $manageableTeamIds = $manageableAssignments->where('scope', 'team')->pluck('team_id')
            ->merge($directManagementGrants->where('scope', 'team')->pluck('team_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        // A team-level management grant must also stay inside any organization
        // boundary assigned to the actor (or their legacy organization).
        if ($actor->isAdmin() && $effectiveOrganizationIds !== null) {
            $validTeamIds = \App\Models\Team::query()
                ->whereIn('id', $manageableTeamIds)
                ->whereIn('organization_id', $effectiveOrganizationIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);
            $manageableTeamIds = $manageableTeamIds->intersect($validTeamIds)->values();
        } elseif (! $actor->isAdmin()) {
            $validTeamQuery = \App\Models\Team::query()->whereIn('id', $manageableTeamIds);
            if ($actor->organization_id !== null) {
                $validTeamQuery->where('organization_id', (int) $actor->organization_id);
            }
            $validTeamIds = $validTeamQuery->get(['id'])
                ->filter(fn ($team): bool => $this->userHasTeamAccess($actor, (int) $team->id))
                ->pluck('id')
                ->map(fn ($id) => (int) $id);
            $manageableTeamIds = $manageableTeamIds->intersect($validTeamIds)->values();
        }

        $effectiveTeamIds = $this->effectiveTeamIds($actor);
        if ($manageableTeamIds->isNotEmpty() && $effectiveTeamIds !== null) {
            $manageableTeamIds = $manageableTeamIds
                ->filter(fn (int $id): bool => in_array($id, $effectiveTeamIds, true))
                ->values();
        }

        $allowedOrganizationIds = $hasExplicitOrganizationManagementScope
            ? $manageableOrganizationIds
            : ($effectiveOrganizationIds !== null
                ? collect($effectiveOrganizationIds)
                : ($actor->organization_id ? collect([(int) $actor->organization_id]) : collect()));

        $allowedTeamIds = $hasExplicitTeamManagementScope
            ? $manageableTeamIds
            : ($effectiveTeamIds !== null ? collect($effectiveTeamIds) : collect());

        // Organization-scoped management grants also constrain team operations,
        // even when the actor's narrower effective role is team-scoped.
        if ($hasExplicitOrganizationManagementScope) {
            $validTeamIds = \App\Models\Team::query()
                ->whereIn('id', $allowedTeamIds)
                ->whereIn('organization_id', $manageableOrganizationIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);
            $allowedTeamIds = $allowedTeamIds->intersect($validTeamIds)->values();
        }

        if ($actorScope === 'team' && $allowedTeamIds->isEmpty() && ! $actor->isAdmin() && ! $hasExplicitTeamManagementScope && $effectiveTeamIds === null) {
            $managedTeamsQuery = \App\Models\Team::query();
            if ($actor->organization_id !== null) {
                $managedTeamsQuery->where('organization_id', (int) $actor->organization_id);
            }
            $allowedTeamIds = $managedTeamsQuery
                ->where(function ($query) use ($actor): void {
                    $query->where('supervisor_id', $actor->id)
                        ->orWhereIn('id', function ($subquery) use ($actor): void {
                            $subquery->select('team_id')
                                ->from('team_user')
                                ->where('user_id', $actor->id)
                                ->whereIn('team_role', ['manager', 'owner', 'supervisor']);
                        });
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id);
        }

        if ($scope === 'global') {
            return $actorScope === 'global';
        }

        if ($scope === 'organization') {
            return $organizationId !== null
                && (int) $subject->organization_id === $organizationId
                && ($actorScope === 'global' || $allowedOrganizationIds->contains($organizationId));
        }

        if ($scope === 'team') {
            if ($teamId === null) {
                return false;
            }

            $team = \App\Models\Team::query()->whereKey($teamId)->first(['id', 'organization_id']);
            if (! $team
                || ($organizationId !== null && (int) $team->organization_id !== $organizationId)
                || ! \App\Models\Team::query()->whereKey($teamId)->where(function ($query) use ($subject): void {
                    $query->where('supervisor_id', $subject->id)
                        ->orWhereHas('members', fn ($members) => $members->whereKey($subject->id));
                })->exists()) {
                return false;
            }

            if ($actorScope === 'global') {
                return true;
            }

            if ($actorScope === 'organization') {
                return $allowedOrganizationIds->contains((int) $team->organization_id);
            }

            return $actorScope === 'team' && $allowedTeamIds->contains($teamId);
        }

        if ($actorScope === 'global') {
            return true;
        }

        if ($actorScope === 'organization') {
            return $subject->organization_id !== null
                && $allowedOrganizationIds->contains((int) $subject->organization_id);
        }

        if ($actorScope === 'team') {
            if ($allowedTeamIds->isEmpty()) {
                return false;
            }

            return \App\Models\Team::query()
                ->whereIn('id', $allowedTeamIds)
                ->where(function ($query) use ($subject): void {
                    $query->where('supervisor_id', $subject->id)
                        ->orWhereHas('members', fn ($members) => $members->whereKey($subject->id));
                })
                ->exists();
        }

        return (int) $actor->id === (int) $subject->id;
    }

    public function audit(
        string $event,
        ?User $actor = null,
        ?User $subject = null,
        ?Model $record = null,
        array $context = [],
        ?Request $request = null,
    ): void {
        $this->decisionCache = [];
        DB::table('access_audit_logs')->insert([
            'actor_id' => $actor?->getKey(),
            'subject_id' => $subject?->getKey(),
            'event' => $event,
            'subject_type' => $record ? $record::class : null,
            'subject_record_id' => $record?->getKey(),
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            'created_at' => now(),
        ]);
    }

    public function syncDefaults(): void
    {
        $labels = [
            'dashboard.view'=>'داشبورد','users.view'=>'مشاهده کاربران','users.create'=>'ایجاد کاربر',
            'users.edit'=>'ویرایش کاربر','users.delete'=>'حذف کاربر','users.suspend'=>'مسدودسازی',
            'documents.view'=>'مشاهده اسناد','documents.create'=>'ایجاد سند','documents.edit'=>'ویرایش سند',
            'documents.delete'=>'حذف سند','documents.export'=>'خروجی اسناد','projects.create'=>'ایجاد پروژه',
            'ai.use'=>'استفاده از AI','ai.manage'=>'مدیریت AI','ai.view'=>'گزارش AI','ocr.use'=>'OCR',
            'voice.use'=>'تایپ صوتی','voice.manage'=>'مدیریت صوت','billing.view'=>'مشاهده صورتحساب',
            'billing.manage'=>'مدیریت صورتحساب','finance.view'=>'گزارش مالی','finance.manage'=>'مدیریت مالی',
            'support.manage'=>'مدیریت پشتیبانی','settings.manage'=>'مدیریت تنظیمات',
            'integrations.manage'=>'مدیریت یکپارچه‌سازی','audit.view'=>'مشاهده ممیزی',
            'access.manage'=>'مدیریت دسترسی','organization.manage'=>'مدیریت سازمان',
            'team.view'=>'مشاهده تیم','team.manage'=>'مدیریت تیم','workflows.view'=>'مشاهده گردش‌کار',
            'workflows.manage'=>'مدیریت گردش‌کار','reports.view'=>'گزارش‌ها','reports.users'=>'گزارش کاربران',
            'reports.team'=>'گزارش تیم','reports.documents'=>'گزارش اسناد','reports.workflows'=>'گزارش گردش‌کار',
            'reports.ai'=>'گزارش AI','reports.resources'=>'گزارش منابع','system.audit'=>'نظارت سیستم',
            'documents.share'=>'اشتراک‌گذاری','store.manage'=>'مدیریت محصولات و دوره‌ها',
            'store.products.publish'=>'تأیید و انتشار محصول',
        ];

        foreach (self::PERMISSIONS as $key) {
            Permission::firstOrCreate(['key' => $key], [
                'label' => $labels[$key] ?? $key,
                'group' => str($key)->before('.')->toString(),
            ]);
        }

        $all = Permission::query()->pluck('id', 'key');
        $roles = [
            'user'=>['dashboard.view','documents.view','documents.create','documents.edit','documents.delete','documents.share','documents.export','projects.create','ai.use','ocr.use','voice.use','billing.view','team.view'],
            'organization_manager'=>['dashboard.view','users.view','users.edit','documents.view','documents.create','documents.edit','documents.delete','documents.share','documents.export','projects.create','ai.use','ai.view','ocr.use','voice.use','billing.view','billing.manage','workflows.view','workflows.manage','support.manage','organization.manage','team.view','team.manage','reports.view','reports.users','reports.team','reports.documents','reports.ai','reports.resources'],
            'team_supervisor'=>['dashboard.view','users.view','documents.view','documents.create','documents.edit','documents.delete','documents.export','projects.create','ai.use','ai.view','ocr.use','voice.use','workflows.view','team.view','reports.team','reports.documents','documents.share'],
            'employee'=>['dashboard.view','documents.view','documents.create','documents.edit','documents.delete','documents.share','documents.export','projects.create','ai.use','ocr.use','voice.use','team.view'],
            'observer'=>['dashboard.view','documents.view','ai.use','reports.view'],
            'guest'=>['dashboard.view','documents.view'],
        ];

        foreach ($roles as $role => $permissions) {
            foreach ($permissions as $key) {
                if (isset($all[$key])) {
                    RolePermission::firstOrCreate(['role' => $role, 'permission_id' => $all[$key]]);
                }
            }

            if (DB::getSchemaBuilder()->hasTable('roles')) {
                DB::table('roles')->insertOrIgnore([
                    'key' => $role,
                    'name' => str($role)->replace('_', ' ')->title()->toString(),
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        $this->permissionIds = [];
        $this->decisionCache = [];
    }

    private function activeUser(?User $user): bool
    {
        return $user !== null && ! (bool) $user->is_blocked;
    }

    private function permissionId(string $permission): ?int
    {
        if (! array_key_exists($permission, $this->permissionIds)) {
            $this->permissionIds[$permission] = Permission::query()->where('key', $permission)->value('id');
        }

        return $this->permissionIds[$permission] ? (int) $this->permissionIds[$permission] : null;
    }

    private function activeDirectGrants(User $user, string $permission)
    {
        $id = $this->permissionId($permission);
        if ($id === null) {
            return collect();
        }

        if (! DB::getSchemaBuilder()->hasTable('user_permission_grants')) {
            return collect();
        }

        return DB::table('user_permission_grants')
            ->where('user_id', $user->id)
            ->where('permission_id', $id)
            ->whereIn('effect', ['allow', 'deny'])
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();
    }

    private function activeAssignments(User $user)
    {
        if (! DB::getSchemaBuilder()->hasTable('role_assignments') || ! DB::getSchemaBuilder()->hasTable('roles')) {
            return collect();
        }

        return DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->where('role_assignments.user_id', $user->id)
            ->where('roles.is_active', true)
            ->where(fn ($query) => $query->whereNull('role_assignments.starts_at')->orWhere('role_assignments.starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('role_assignments.expires_at')->orWhere('role_assignments.expires_at', '>', now()))
            ->select('role_assignments.*', 'roles.key as role_key')
            ->get();
    }

    private function assignedRoleAllows($assignments, string $permission): bool
    {
        $id = $this->permissionId($permission);
        if ($id === null) {
            return false;
        }

        foreach ($assignments as $assignment) {
            if (RolePermission::query()->where('role', $assignment->role_key)
                ->where('permission_id', $id)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function legacyScope(User $user): string
    {
        return match ($user->role) {
            User::ROLE_ADMIN => 'global',
            User::ROLE_ORGANIZATION_MANAGER => 'organization',
            User::ROLE_TEAM_SUPERVISOR => 'team',
            default => 'own',
        };
    }

    private function userHasTeamAccess(User $user, int $teamId): bool
    {
        return \App\Models\Team::query()
            ->whereKey($teamId)
            ->where(function ($query) use ($user): void {
                $query->where('supervisor_id', $user->id)
                    ->orWhereHas('members', fn ($members) => $members->where('users.id', $user->id));
            })
            ->exists();
    }

    private function scopeContains(User $user, string $scope, ?object $grant, Model $record): bool
    {
        if (! in_array($scope, self::SCOPES, true)) {
            return false;
        }

        $isDeny = ($grant?->effect ?? null) === 'deny';
        $rank = ['global' => 0, 'organization' => 1, 'team' => 2, 'own' => 3];
        $effectiveScope = $this->effectiveScope($user);
        if (! $isDeny && ($rank[$effectiveScope] ?? 4) > ($rank[$scope] ?? 4)) {
            return false;
        }

        if ($scope === 'global') {
            return true;
        }

        $organizationId = $grant?->organization_id ?: $user->organization_id;
        $teamId = $grant?->team_id;

        if ($scope === 'organization') {
            if ($organizationId === null) {
                return false;
            }

            if (! $isDeny) {
                if ($user->isAdmin()) {
                    $effectiveOrganizationIds = $this->effectiveOrganizationIds($user);
                    if ($effectiveOrganizationIds !== null
                        && ! in_array((int) $organizationId, $effectiveOrganizationIds, true)) {
                        return false;
                    }
                } elseif ($user->organization_id === null
                    || (int) $organizationId !== (int) $user->organization_id) {
                    return false;
                }
            }

            $recordOrganizationId = $record->getAttribute('organization_id');
            if (! $recordOrganizationId && $record->getAttribute('user_id')) {
                $recordOrganizationId = User::query()->whereKey($record->getAttribute('user_id'))->value('organization_id');
            }

            return (int) $recordOrganizationId === (int) $organizationId;
        }

        if ($scope === 'team') {
            if ($teamId === null) {
                return false;
            }

            $teamQuery = \App\Models\Team::query()->whereKey((int) $teamId);
            if ($grant?->organization_id !== null) {
                $teamQuery->where('organization_id', (int) $grant->organization_id);
            }
            $team = $teamQuery->first(['id', 'organization_id']);
            if (! $team) {
                return false;
            }

            if (! $isDeny) {
                if ($user->isAdmin()) {
                    $effectiveOrganizationIds = $this->effectiveOrganizationIds($user);
                    if ($effectiveOrganizationIds !== null
                        && ! in_array((int) $team->organization_id, $effectiveOrganizationIds, true)) {
                        return false;
                    }
                } elseif (($user->organization_id !== null
                    && (int) $team->organization_id !== (int) $user->organization_id)
                    || ! $this->userHasTeamAccess($user, (int) $teamId)) {
                    return false;
                }

                $effectiveTeamIds = $this->effectiveTeamIds($user);
                if ($effectiveTeamIds !== null && ! in_array((int) $teamId, $effectiveTeamIds, true)) {
                    return false;
                }
            }

            $recordTeamId = $record->getAttribute('team_id');
            if ($recordTeamId !== null) {
                return (int) $recordTeamId === (int) $teamId;
            }

            if ($record instanceof User) {
                return \App\Models\Team::query()
                    ->whereKey((int) $teamId)
                    ->where(function ($query) use ($record): void {
                        $query->where('supervisor_id', $record->id)
                            ->orWhereHas('members', fn ($members) => $members->whereKey($record->id));
                    })
                    ->exists();
            }

            $ownerId = $record->getAttribute('user_id')
                ?? $record->getAttribute('owner_id')
                ?? $record->getAttribute('created_by');

            return $ownerId !== null && DB::table('team_user')
                ->where('user_id', $ownerId)
                ->where('team_id', $teamId)
                ->exists();
        }

        $ownerId = $record instanceof User
            ? $record->getKey()
            : ($record->getAttribute('user_id')
                ?? $record->getAttribute('owner_id')
                ?? $record->getAttribute('created_by'));

        return $ownerId !== null && (int) $ownerId === (int) $user->id;
    }
}
