<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthorizationPermissionCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_decisions_are_queried_once_per_role_and_permission_per_service_instance(): void
    {
        app(AuthorizationService::class)->syncDefaults();
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $authorization = app(AuthorizationService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertTrue($authorization->allows($user, 'documents.view'));
        $firstQueryCount = count(DB::getQueryLog());

        $this->assertTrue($authorization->allows($user, 'documents.view'));
        $this->assertSame($firstQueryCount, count(DB::getQueryLog()));

        DB::disableQueryLog();
    }

    public function test_syncing_default_permissions_invalidates_cached_decisions(): void
    {
        app(AuthorizationService::class)->syncDefaults();
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $authorization = app(AuthorizationService::class);

        $this->assertTrue($authorization->allows($user, 'documents.view'));
        $authorization->syncDefaults();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertTrue($authorization->allows($user, 'documents.view'));
        $this->assertNotEmpty(DB::getQueryLog());
        DB::disableQueryLog();
    }
}
