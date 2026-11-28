<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffLifecycleService
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    private function assertPersonnelManagementAccess(User $actor): void
    {
        abort_unless($actor->isAdmin() || ($this->authorization->effectiveScope($actor) === 'global' && $this->authorization->allows($actor, 'access.manage')), 403);
    }

    public function setPrimaryResponsibility(User $actor, User $subject, string $roleKey, string $title, string $scope = 'global', ?int $organizationId = null, ?int $teamId = null, ?int $departmentId = null): object
    {
        $this->assertPersonnelManagementAccess($actor);
        return DB::transaction(function () use ($actor, $subject, $roleKey, $title, $scope, $organizationId, $teamId, $departmentId): object {
            User::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $role = DB::table('roles')->where('key', $roleKey)->where('is_active', true)->first();
            if (! $role) {
                throw ValidationException::withMessages(['role_key' => 'عنوان مسئولیت معتبر و فعال نیست.']);
            }
            $this->validateScope($scope, $organizationId, $teamId);
            $this->validateDepartment($departmentId);
            $this->assertNoDuplicateSeniorPost($subject, $roleKey, $title, true);

            DB::table('role_assignments')->where('user_id', $subject->id)
                ->where('is_primary', true)->whereNull('ended_at')
                ->update(['is_primary' => false, 'ended_at' => now(), 'active_primary_user_id' => null, 'updated_at' => now()]);

            $id = DB::table('role_assignments')->insertGetId([
                'user_id' => $subject->id, 'role_id' => $role->id, 'scope' => $scope,
                'organization_id' => $organizationId, 'team_id' => $teamId, 'department_id' => $departmentId,
                'assigned_by' => $actor->id, 'is_primary' => true, 'active_primary_user_id' => $subject->id, 'title' => $title,
                'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            // Keep legacy dashboard routing aligned with the new primary responsibility.
            // The master-admin key is deliberately excluded from this promotion path.
            if (in_array($roleKey, ['user', 'employee', 'organization_manager', 'team_supervisor', 'observer', 'guest'], true)) {
                $subject->forceFill(['role' => $roleKey, 'access_control_managed' => true])->save();
            }
            $this->audit($actor, $subject, 'responsibility.primary.assigned', ['assignment_id' => $id, 'role_key' => $roleKey, 'title' => $title]);

            return DB::table('role_assignments')->where('id', $id)->first();
        }, 3);
    }

    public function addSecondaryResponsibility(User $actor, User $subject, string $roleKey, string $title, string $scope = 'own', ?int $organizationId = null, ?int $teamId = null, ?int $departmentId = null): object
    {
        $this->assertPersonnelManagementAccess($actor);
        return DB::transaction(function () use ($actor, $subject, $roleKey, $title, $scope, $organizationId, $teamId, $departmentId): object {
            User::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $role = DB::table('roles')->where('key', $roleKey)->where('is_active', true)->first();
            if (! $role) {
                throw ValidationException::withMessages(['role_key' => 'عنوان مسئولیت معتبر و فعال نیست.']);
            }
            $this->validateScope($scope, $organizationId, $teamId);
            $this->validateDepartment($departmentId);
            $this->assertNoDuplicateSeniorPost($subject, $roleKey, $title, false);
            $id = DB::table('role_assignments')->insertGetId([
                'user_id' => $subject->id, 'role_id' => $role->id, 'scope' => $scope,
                'organization_id' => $organizationId, 'team_id' => $teamId, 'department_id' => $departmentId,
                'assigned_by' => $actor->id, 'is_primary' => false, 'active_primary_user_id' => null, 'title' => $title,
                'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, $subject, 'responsibility.secondary.assigned', ['assignment_id' => $id, 'role_key' => $roleKey, 'title' => $title]);

            return DB::table('role_assignments')->where('id', $id)->first();
        }, 3);
    }

    public function endResponsibility(User $actor, int $assignmentId): void
    {
        $this->assertPersonnelManagementAccess($actor);
        DB::transaction(function () use ($actor, $assignmentId): void {
            $assignment = DB::table('role_assignments')->where('id', $assignmentId)->lockForUpdate()->first();
            if (! $assignment || $assignment->ended_at !== null) {
                throw ValidationException::withMessages(['assignment' => 'مسئولیت فعال پیدا نشد.']);
            }
            if ((bool) $assignment->is_primary) {
                throw ValidationException::withMessages(['assignment' => 'مسئولیت اصلی باید با مسئولیت اصلی دیگری جایگزین شود.']);
            }
            DB::table('role_assignments')->where('id', $assignmentId)->update([
                'ended_at' => now(), 'updated_at' => now(), 'is_primary' => false, 'active_primary_user_id' => null,
            ]);
            $subject = User::query()->findOrFail($assignment->user_id);
            $this->audit($actor, $subject, 'responsibility.ended', ['assignment_id' => $assignmentId]);
        }, 3);
    }

    public function setDepartmentMembership(User $actor, User $subject, int $departmentId, bool $member): void
    {
        $this->assertPersonnelManagementAccess($actor);
        DB::transaction(function () use ($actor, $subject, $departmentId, $member): void {
            User::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $department = DB::table('departments')->where('id', $departmentId)->where('is_active', true)->first();
            if (! $department) {
                throw ValidationException::withMessages(['department_id' => 'دپارتمان فعال پیدا نشد.']);
            }
            $activeMembership = DB::table('department_user')
                ->where('department_id', $departmentId)->where('user_id', $subject->id)
                ->whereNull('left_at')->latest('joined_at')->first();
            if ($member && ! $activeMembership) {
                // Rejoining creates a new historical membership row; prior periods remain immutable.
                DB::table('department_user')->insert([
                    'department_id' => $departmentId, 'user_id' => $subject->id,
                    'added_by' => $actor->id, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif (! $member && $activeMembership) {
                DB::table('department_user')->where('id', $activeMembership->id)->update(['left_at' => now(), 'updated_at' => now()]);
            }
            $this->audit($actor, $subject, $member ? 'department.joined' : 'department.left', ['department_id' => $departmentId]);
        }, 3);
    }

    public function dismissToCustomer(User $actor, User $subject): void
    {
        $this->assertPersonnelManagementAccess($actor);
        DB::transaction(function () use ($actor, $subject): void {
            $subject = User::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $holdsAdminResponsibility = DB::table('role_assignments')->join('roles', 'roles.id', '=', 'role_assignments.role_id')
                ->where('role_assignments.user_id', $subject->id)->where('roles.key', 'admin')->whereNull('role_assignments.ended_at')->exists();
            if ($subject->isAdmin() || $holdsAdminResponsibility) {
                throw ValidationException::withMessages(['user' => 'برکناری مدیر اصلی از این مسیر مجاز نیست.']);
            }
            $previousRole = $subject->role;
            DB::table('role_assignments')->where('user_id', $subject->id)->whereNull('ended_at')->update([
                'ended_at' => now(), 'is_primary' => false, 'active_primary_user_id' => null, 'updated_at' => now(),
            ]);
            if (DB::getSchemaBuilder()->hasTable('department_user')) {
                DB::table('department_user')->where('user_id', $subject->id)->whereNull('left_at')->update(['left_at' => now(), 'updated_at' => now()]);
            }
            if (DB::getSchemaBuilder()->hasTable('user_permission_grants')) {
                DB::table('user_permission_grants')->where('user_id', $subject->id)->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })->update(['expires_at' => now(), 'updated_at' => now()]);
            }
            $customerRole = DB::table('roles')->where('key', 'user')->where('is_active', true)->first();
            if (! $customerRole) {
                throw ValidationException::withMessages(['role' => 'نقش پایه مشتری در سامانه تعریف نشده است.']);
            }
            DB::table('role_assignments')->insert([
                'user_id' => $subject->id, 'role_id' => $customerRole->id, 'scope' => 'own',
                'assigned_by' => $actor->id, 'is_primary' => true, 'active_primary_user_id' => $subject->id,
                'title' => 'مشتری عادی', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $subject->forceFill(['role' => 'user', 'access_control_managed' => false])->save();
            $this->audit($actor, $subject, 'staff.dismissed_to_customer', ['legacy_role_before' => $previousRole]);
        }, 3);
    }

    private function assertNoDuplicateSeniorPost(User $subject, string $roleKey, string $title, bool $ignoreCurrentPrimary): void
    {
        if ($roleKey !== 'senior_manager' && trim($title) !== 'مدیر ارشد') {
            return;
        }
        $query = DB::table('role_assignments')->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->where('role_assignments.user_id', $subject->id)
            ->whereNull('role_assignments.ended_at')
            ->where(fn ($builder) => $builder->where('roles.key', 'senior_manager')->orWhere('role_assignments.title', 'مدیر ارشد'));
        if ($ignoreCurrentPrimary) {
            $query->where('role_assignments.is_primary', false);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['title' => 'این فرد هم‌اکنون یک مسئولیت مدیر ارشد فعال دارد.']);
        }
    }

    private function validateDepartment(?int $departmentId): void
    {
        if ($departmentId !== null && ! DB::table('departments')->where('id', $departmentId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['department_id' => 'دپارتمان فعال پیدا نشد.']);
        }
    }

    private function validateScope(string $scope, ?int $organizationId, ?int $teamId): void
    {
        if (! in_array($scope, AuthorizationService::SCOPES, true)) {
            throw ValidationException::withMessages(['scope' => 'دامنه دسترسی معتبر نیست.']);
        }
        if ($scope === 'organization' && ! $organizationId) {
            throw ValidationException::withMessages(['organization_id' => 'برای دامنه سازمان، سازمان الزامی است.']);
        }
        if ($scope === 'team' && (! $teamId || ! $organizationId)) {
            throw ValidationException::withMessages(['team_id' => 'برای دامنه تیم، سازمان و تیم الزامی است.']);
        }
        if ($teamId && ! DB::table('teams')->where('id', $teamId)->where('organization_id', $organizationId)->exists()) {
            throw ValidationException::withMessages(['team_id' => 'تیم با سازمان انتخاب‌شده تطبیق ندارد.']);
        }
    }

    private function audit(User $actor, User $subject, string $event, array $context): void
    {
        if (! DB::getSchemaBuilder()->hasTable('access_audit_logs')) {
            return;
        }
        DB::table('access_audit_logs')->insert([
            'actor_id' => $actor->id, 'subject_id' => $subject->id, 'event' => $event,
            'subject_type' => 'user', 'subject_record_id' => $subject->id,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'ip_address' => request()->ip(), 'user_agent' => substr((string) request()->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
