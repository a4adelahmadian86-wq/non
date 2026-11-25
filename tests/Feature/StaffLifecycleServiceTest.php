<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StaffLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StaffLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_at_most_one_active_primary_responsibility_and_keeps_history(): void
    {
        $actor = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $subject = User::factory()->create(['role' => User::ROLE_USER]);
        $service = app(StaffLifecycleService::class);

        $first = $service->setPrimaryResponsibility($actor, $subject, 'employee', 'کارشناس');
        $second = $service->setPrimaryResponsibility($actor, $subject, 'organization_manager', 'مدیر سازمان');

        $this->assertDatabaseHas('role_assignments', ['id' => $first->id, 'is_primary' => false]);
        $this->assertNotNull(DB::table('role_assignments')->where('id', $first->id)->value('ended_at'));
        $this->assertDatabaseHas('role_assignments', ['id' => $second->id, 'is_primary' => true, 'ended_at' => null]);
        $this->assertSame(1, DB::table('role_assignments')->where('user_id', $subject->id)->where('is_primary', true)->whereNull('ended_at')->count());
    }

    public function test_department_membership_is_independent_and_dismissal_restores_customer_role_without_deleting_history(): void
    {
        $actor = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $subject = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $departmentId = DB::table('departments')->insertGetId([
            'name' => 'عملیات', 'key' => 'operations', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(StaffLifecycleService::class);
        $assignment = $service->setPrimaryResponsibility($actor, $subject, 'employee', 'کارشناس');
        $service->setDepartmentMembership($actor, $subject, $departmentId, true);
        $service->dismissToCustomer($actor, $subject);

        $this->assertSame(User::ROLE_USER, $subject->fresh()->role);
        $this->assertDatabaseHas('role_assignments', ['id' => $assignment->id, 'is_primary' => false]);
        $this->assertNotNull(DB::table('role_assignments')->where('id', $assignment->id)->value('ended_at'));
        $this->assertNotNull(DB::table('department_user')->where('department_id', $departmentId)->where('user_id', $subject->id)->value('left_at'));
    }

    public function test_a_user_cannot_hold_two_active_senior_manager_posts(): void
    {
        $actor = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $subject = User::factory()->create(['role' => User::ROLE_USER]);
        $service = app(StaffLifecycleService::class);
        $service->addSecondaryResponsibility($actor, $subject, 'organization_manager', 'مدیر ارشد');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->setPrimaryResponsibility($actor, $subject, 'organization_manager', 'مدیر ارشد');
    }
}
