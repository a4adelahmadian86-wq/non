<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountSecurityPasswordActionsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name' => 'Security Test',
            'mobile' => '09123456780',
            'email' => 'security-actions@example.test',
            'password' => 'StrongPassword123',
            'role' => 'user',
            'is_verified' => true,
            'is_blocked' => false,
        ]);
    }

    public function test_password_change_keeps_current_device_and_revokes_other_sessions(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get(route('platform.security'))->assertOk();
        $currentId = $this->app['session']->getId();

        $otherId = DB::table('farast_account_sessions')->insertGetId([
            'user_id' => $user->id,
            'session_key' => hash('sha256', 'another-device'),
            'session_id_encrypted' => encrypt('another-device'),
            'device_label' => 'Linux · Firefox',
            'ip_address' => '203.0.113.20',
            'user_agent' => 'Firefox',
            'last_activity' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post(route('platform.security.password.update'), [
            'current_password' => 'StrongPassword123',
            'password' => 'NewStrongPassword456',
            'password_confirmation' => 'NewStrongPassword456',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('NewStrongPassword456', $user->fresh()->password));
        $this->assertNotNull(DB::table('farast_account_sessions')->where('id', $otherId)->value('revoked_at'));
        $this->assertDatabaseHas('farast_account_sessions', [
            'user_id' => $user->id,
            'session_key' => hash('sha256', $currentId),
            'revoked_at' => null,
        ]);
    }

    public function test_account_wide_logout_ends_the_current_session(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get(route('platform.security'))->assertOk();

        $this->post(route('platform.security.sessions.revoke-all'), [
            'current_password' => 'StrongPassword123',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('farast_security_events', [
            'user_id' => $user->id,
            'event' => 'all_sessions_revoked',
        ]);
    }
}
