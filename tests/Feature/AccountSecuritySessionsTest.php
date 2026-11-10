<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountSecuritySessionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Security QA',
            'mobile' => '09123456789',
            'email' => 'security@example.test',
            'password' => 'StrongPassword123',
            'role' => 'user',
            'is_verified' => true,
            'is_blocked' => false,
        ]);
    }

    public function test_security_center_lists_the_current_authenticated_device(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get(route('platform.security'))
            ->assertOk()
            ->assertSee('مرکز امنیت حساب')
            ->assertSee('این دستگاه');

        $this->assertDatabaseHas('farast_account_sessions', [
            'user_id' => $user->id,
            'session_key' => hash('sha256', $this->app['session']->getId()),
            'revoked_at' => null,
        ]);
    }

    public function test_revoke_others_requires_current_password_and_revokes_only_other_sessions(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get(route('platform.security'))->assertOk();
        $currentSessionId = $this->app['session']->getId();

        $otherId = DB::table('farast_account_sessions')->insertGetId([
            'user_id' => $user->id,
            'session_key' => hash('sha256', 'other-session-id'),
            'session_id_encrypted' => Crypt::encryptString('other-session-id'),
            'device_label' => 'Linux · Firefox',
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Firefox test agent',
            'last_activity' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post(route('platform.security.sessions.revoke-others'), [
            'current_password' => 'StrongPassword123',
        ])->assertRedirect();

        $this->assertDatabaseHas('farast_account_sessions', [
            'id' => $otherId,
        ]);
        $this->assertNotNull(DB::table('farast_account_sessions')->where('id', $otherId)->value('revoked_at'));
        $this->assertSame(1, DB::table('farast_account_sessions')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->count(), 'The current authenticated browser must remain active.');
    }

    public function test_revoke_others_rejects_an_incorrect_current_password(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post(route('platform.security.sessions.revoke-others'), [
                'current_password' => 'incorrect-password',
            ])
            ->assertSessionHasErrors('current_password');
    }
    public function test_session_expires_after_three_years_without_a_visit(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get(route('platform.security'))->assertOk();
        $sessionKey = hash('sha256', $this->app['session']->getId());

        DB::table('farast_account_sessions')
            ->where('user_id', $user->id)
            ->where('session_key', $sessionKey)
            ->update(['last_activity' => now()->subYears(3)->subMinute()]);

        $this->actingAs($user)->get(route('platform.security'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNotNull(DB::table('farast_account_sessions')
            ->where('user_id', $user->id)
            ->where('session_key', $sessionKey)
            ->value('revoked_at'));
        $this->assertDatabaseHas('farast_security_events', [
            'user_id' => $user->id,
            'event' => 'session_expired_three_years',
        ]);
    }

    public function test_explicit_logout_revokes_the_current_account_session(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get(route('platform.security'))->assertOk();
        $sessionKey = hash('sha256', $this->app['session']->getId());

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertDatabaseHas('farast_security_events', [
            'user_id' => $user->id,
            'event' => 'logout',
        ]);
    }

}
