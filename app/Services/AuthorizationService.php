<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

/**
 * Central authorization decision point.
 *
 * Legacy role_permissions remain supported during migration. New assignments
 * and direct grants are evaluated first. Decisions are deliberately not cached:
 * revocation must take effect immediately across long-lived workers and sessions.
 */
class AuthorizationService
{
    public const SCOPES = ['global', 'organization', 'team', 'own'];

    private array $permissionIds = [];

    public const PERMISSIONS = [
        'dashboard.view','users.view','users.create','users.edit','users.delete','users.suspend',
        'documents.view','documents.create','documents.edit','documents.delete','documents.export',
        'projects.create','ai.use','ai.manage','ai.view','ocr.use','voice.use','voice.manage',
        'billing.view','billing.manage','finance.view','finance.manage','support.manage',
        'settings.manage','integrations.manage','audit.view','access.manage','organization.manage',
        'team.view','team.manage','workflows.view','workflows.manage','reports.view','reports.users',
        'reports.team','reports.documents','reports.workflows','reports.ai','reports.resources',
        'system.audit','documents.share','store.manage',
    ];

    public function allows(?User $user, string $permission): bool
    {
        if (! $this->activeUser($user) || trim($permission) === '') {
            return false;
        }

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

        // Compatibility for the existing single-role installation.
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

    public function createRole(User $actor, string $key, string $name, array $permissionKeys): object
    {
        abort_unless($this->allows($actor, 'access.manage'), 403);
        $key = trim($key);
        $name = trim($name);
        abort_unless((bool) preg_match('/^[a-z][a-z0-9_.-]{2,99}$/', $key), 422, 'کلید نقش معتبر نیست.');
        abort_unless($name !== '' && mb_strlen($name) <= 160, 422, 'نام نقش معتبر نیست.');
        abort_if(in_array($key, ['admin', 'user', 'employee', 'organization_manager', 'team_supervisor', 'observer', 'guest'], true), 422, 'این کلید برای نقش سیستمی رزرو شده است.');

        return DB::transaction(function () use ($actor, $key, $name, $permissionKeys): object {
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

            $this->audit('role.created', $actor, null, null, ['role_key' => $key]);
            return DB::table('roles')->where('id', $roleId)->first();
        });
    }

    public function assignRole(
        User $actor,
        User $subject,
        string $roleKey,
        string $scope,
        ?int $organizationId = null,
        ?int $teamId = null,
        ?\\DateTimeInterface $startsAt = null,
        ?\\DateTimeInterface $expiresAt = null,
        ?Request $request = null,
    ): object {
        abort_unless(in_array($scope, self::SCOPES, true), 422, 'محدوده دسترسی معتبر نیست.');
        abort_unless($this->canManageScope($actor, $subject, $scope, $organizationId, $teamId), 403);
        abort_if($expiresAt && $startsAt && $expiresAt <= $startsAt, 422, 'زمان انقضا باید بعد از زمان شروع باشد.');

        return DB::transaction(function () use ($actor, $subject, $roleKey, $scope, $organizationId, $teamId, $startsAt, $expiresAt, $request): object {
            $role = DB::table('roles')->where('key', $roleKey)->where('is_active', true)->first();
            abort_unless($role, 404, 'نقش فعال پیدا نشد.');

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
        ?\\DateTimeInterface $startsAt = null,
        ?\\DateTimeInterface $expiresAt = null,
        ?Request $request = null,
    ): object {
        abort_unless(in_array($effect, ['allow', 'deny'], true), 422, 'نوع مجوز مستقیم معتبر نیست.');
        abort_unless(in_array($scope, self::SCOPES, true), 422, 'محدوده دسترسی معتبر نیست.');
        abort_unless($this->canManageScope($actor, $subject, $scope, $organizationId, $teamId), 403);
        abort_if($expiresAt && $startsAt && $expiresAt <= $startsAt, 422, 'زمان انقضا باید بعد از زمان شروع باشد.');
        $permissionId = $this->permissionId($permission);
        abort_unless($permissionId !== null, 404, 'مجوز پیدا نشد.');

        return DB::transaction(function () use ($actor, $subject, $permission, $permissionId, $effect, $scope, $organizationId, $teamId, $startsAt, $expiresAt, $request): object {
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

    private function canManageScope(User $actor, User $subject, string $scope, ?int $organizationId, ?int $teamId): bool
    {
        if (! $this->allows($actor, 'access.manage')) {
            return false;
        }

        $rank = ['global' => 0, 'organization' => 1, 'team' => 2, 'own' => 3];
        $assignments = $this->activeAssignments($actor);
        $actorScope = $assignments->isNotEmpty()
            ? $assignments->pluck('scope')->sortBy(fn ($value) => $rank[$value] ?? 4)->first()
            : $this->legacyScope($actor);

        if (! isset($rank[$actorScope]) || $rank[$scope] < $rank[$actorScope]) {
            return false;
        }

        if ($scope === 'global') {
            return $actorScope === 'global' && $actor->isAdmin();
        }

        if ($scope === 'organization') {
            return $organizationId !== null
                && (int) $subject->organization_id === $organizationId
                && ($actorScope === 'global' || (int) $actor->organization_id === $organizationId);
        }

        if ($scope === 'team') {
            if ($teamId === null || ! $subject->teams()->whereKey($teamId)->exists()) {
                return false;
            }
            $teamOrganizationId = \\App\\Models\\Team::query()->whereKey($teamId)->value('organization_id');

            return $teamOrganizationId !== null
                && ($organizationId === null || $organizationId === (int) $teamOrganizationId)
                && ($actorScope === 'global' || (int) $actor->organization_id === (int) $teamOrganizationId);
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
        }
        $this->permissionIds = [];
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

    private function scopeContains(User $user, string $scope, ?object $grant, Model $record): bool
    {
        if (! in_array($scope, self::SCOPES, true)) {
            return false;
        }

        if ($scope === 'global') {
            return true;
        }

        $organizationId = $grant?->organization_id ?: $user->organization_id;
        $teamId = $grant?->team_id;

        if ($scope === 'organization') {
            $recordOrganizationId = $record->getAttribute('organization_id');
            if (! $recordOrganizationId && $record->getAttribute('user_id')) {
                $recordOrganizationId = User::query()->whereKey($record->getAttribute('user_id'))->value('organization_id');
            }

            return $organizationId !== null
                && (int) $recordOrganizationId === (int) $organizationId;
        }

        if ($scope === 'team') {
            $teamIds = $teamId !== null
                ? collect([(int) $teamId])
                : \App\Models\Team::query()
                    ->where('supervisor_id', $user->id)
                    ->orWhereHas('members', fn ($query) => $query->where('users.id', $user->id)->where('team_user.team_role', 'manager'))
                    ->pluck('id');

            if ($teamIds->isEmpty()) {
                return false;
            }

            $recordTeamId = $record->getAttribute('team_id');
            if ($recordTeamId !== null) {
                return $teamIds->contains((int) $recordTeamId);
            }

            $ownerId = $record->getAttribute('user_id')
                ?? $record->getAttribute('owner_id')
                ?? $record->getAttribute('created_by');

            return $ownerId !== null && DB::table('team_user')
                ->where('user_id', $ownerId)
                ->whereIn('team_id', $teamIds)
                ->exists();
        }

        foreach (['user_id', 'owner_id', 'created_by'] as $ownerColumn) {
            if ($record->getAttribute($ownerColumn) !== null) {
                return (int) $record->getAttribute($ownerColumn) === (int) $user->id;
            }
        }

        return false;
    }
}
