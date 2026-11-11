<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreAdminSectionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['farast_admin.multi_role_console' => true]);
        app(AuthorizationService::class)->syncDefaults();
    }

    public function test_store_section_requires_global_scope_even_when_role_has_store_permission(): void
    {
        $organization = Organization::create([
            'name' => 'Store Scope Org',
            'slug' => 'store-scope-org',
            'status' => 'active',
        ]);
        $manager = User::create([
            'name' => 'Scoped Store Manager',
            'mobile' => '09120000072',
            'password' => 'secret',
            'role' => User::ROLE_ORGANIZATION_MANAGER,
            'organization_id' => $organization->id,
        ]);

        $permission = Permission::query()->where('key', 'store.manage')->firstOrFail();
        RolePermission::firstOrCreate([
            'role' => $manager->role,
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($manager)
            ->get(route('admin.store.index'))
            ->assertForbidden();
    }

    public function test_global_admin_can_open_store_section(): void
    {
        $admin = User::create([
            'name' => 'Global Store Admin',
            'mobile' => '09120000073',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.store.index'))
            ->assertOk();
    }
}
