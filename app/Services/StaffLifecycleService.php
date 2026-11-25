<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffLifecycleService
{
    public function setPrimaryResponsibility(User $actor, User $subject, string $roleKey, string $title, string $scope = 'global', ?int $organizationId = null, ?int $teamId = null): object
    {
        return DB::transaction(function () use ($actor, $subject, $roleKey, $title, $scope, $organizationId, $teamId): object {
            User::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $role = DB::table('roles')->where('key', $roleKey)->where('is_active', true)->first();
            if (! $role) {
                throw ValidationException::withMessages(['role_key' => 'عنوان مسئولیت معتبر و فعال نیست.']);
            }
            $this->validateScope($scope, $organizationId, $teamId);

            DB::table('role_assignments')->where('user_id', $subject->id)
                ->where('is_primary', true)->whereNull('ended_at')
                ->update(['is_primary' => false, 'ended_at' => now(), 'updated_at' => now()]);

            $id = DB::table('role_assignments')->insertGetId([
                'user_id' => $subject->id, 'role_id' => $role->id, 'scope' => $scope,
                'organization_id' => $organizationId, 'team_id' => $teamId,
                'assigned_by' => $actor->id, 'is_primary' => true, 'title' => $title,
                'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, $subject, 'responsibility.primary.assigned', ['assignment_id' => $id, 'role_key' => $roleKey, 'title' => $title]);

            return DB::table('role_assignments')->where('id', $id)->first();
        }, 3);
    }

    public function addSecondaryResponsibility(User $actor, User $subject, string $roleKey, string $title, string $scope = 'own', ?int $organizationId = null, ?int $teamId = null): object
    {
        return DB::transaction(function () use ($actor, $subject, $roleKey, $title, $scope, $organizationId, $teamId): object {
            User::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $role = DB::table('roles')->where('key', $roleKey)->where('is_active', true)->first();
            if (! $role) {
                throw ValidationException::withMessages(['role_key' => 'عنوان مسئولیت معتبر و فعال نیست.']);
            }
            $this->validateScope($scope, $organizationId, $teamId);
            $id = DB::table('role_assignments')->insertGetId([
                'user_id' => $subject->id, 'role_id' => $role->id, 'scope' => $scope,
                'organization_id' => $organizationId, 'team_id' => $teamId,
                'assigned_by' => $actor->id, 'is_primary' => false, 'title' => $title,
                'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, $subject, 'responsibility.secondary.assigned', ['assignment_id' => $id, 'role_key' => $roleKey, 'title' => $title]);

            return DB::table('role_assignments')->where('id', $id)->first();
        }, 3);
    }

    public function endResponsibility(User $actor, int $assignmentId): void
    {
        DB::transaction(function () use ($actor, $assignmentId): void {
            $assignment = DB::table('role_assignments')->where('id', $assignmentId)->lockForUpdate()->first();
            if (! $assignment || $assignment->ended_at !== null) {
                throw ValidationException::withMessages(['assignment' => 'مسئولیت فعال پیدا نشد.']);
            }
            DB::table('role_assignments')->where('id', $assignmentId)->update([
                'ended_at' => now(), 'updated_at' => now(), 'is_primary' => false,
            ]);
            $subject = User::query()->findOrFail($assignment->user_id);
            $this->audit($actor, $subject, 'responsibility.ended', ['assignment_id' => $assignmentId]);
        }, 3);
    }

    public function setDepartmentMembership(User $actor, User $subject, int $departmentId, bool $member): void
    {
        DB::transaction(function () use ($actor, $subject, $departmentId, $member): void {
            $department = DB::table('departments')->where('id', $departmentId)->where('is_active', true)->first();
            if (! $department) {
                throw ValidationException::withMessages(['department_id' => 'دپارتمان فعال پیدا نشد.']);
            }
            $membership = DB::table('department_user')->where('department_id', $departmentId)->where('user_id', $subject->id)->first();
            if ($member) {
                if (! $membership) {
                    DB::table('department_user')->insert([
                        'department_id' => $departmentId, 'user_id' => $subject->id,
                        'added_by' => $actor->id, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } elseif ($membership->left_at !== null) {
                    DB::table('department_user')->where('id', $membership->id)->update(['left_at' => null, 'added_by' => $actor->id, 'joined_at' => now(), 'updated_at' => now()]);
                }
            } elseif ($membership && $membership->left_at === null) {
                DB::table('department_user')->where('id', $membership->id)->update(['left_at' => now(), 'updated_at' => now()]);
            }
            $this->audit($actor, $subject, $member ? 'department.joined' : 'department.left', ['department_id' => $departmentId]);
        }, 3);
    }

    public function dismissToCustomer(User $actor, User $subject): void
    {
        DB::transaction(function () use ($actor, $subject): void {
            User::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            if ($subject->isAdmin()) {
                throw ValidationException::withMessages(['user' => 'برکناری مدیر اصلی از این مسیر مجاز نیست.']);
            }
            DB::table('role_assignments')->where('user_id', $subject->id)->whereNull('ended_at')->update([
                'ended_at' => now(), 'is_primary' => false, 'updated_at' => now(),
            ]);
            if (DB::getSchemaBuilder()->hasTable('department_user')) {
                DB::table('department_user')->where('user_id', $subject->id)->whereNull('left_at')->update(['left_at' => now(), 'updated_at' => now()]);
            }
            $subject->forceFill(['role' => 'user', 'access_control_managed' => true])->save();
            $this->audit($actor, $subject, 'staff.dismissed_to_customer', ['legacy_role_before' => $subject->getOriginal('role')]);
        }, 3);
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
