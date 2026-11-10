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

    public function test_security_lists_paginate_independently_without_hiding_older_records(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get(route('platform.security'))->assertOk();

        for ($i = 1; $i <= 17; $i++) {
            DB::table('farast_account_sessions')->insert([
                'user_id' => $user->id,
                'session_key' => hash('sha256', 'pagination-session-'.$i),
                'session_id_encrypted' => Crypt::encryptString('pagination-session-'.$i),
                'device_label' => 'Device '.$i,
                'ip_address' => '203.0.113.'.(($i % 200) + 1),
                'user_agent' => 'Pagination test browser '.$i,
                'last_activity' => now()->subMinutes($i),
                'created_at' => now()->subDays($i),
                'updated_at' => now(),
            ]);
        }

        for ($i = 1; $i <= 12; $i++) {
            DB::table('farast_blocked_devices')->insert([
                'user_id' => $user->id,
                'device_key_hash' => hash('sha256', 'pagination-blocked-device-'.$i),
                'device_label' => 'Blocked device '.$i,
                'ip_address' => '203.0.114.'.(($i % 200) + 1),
                'user_agent' => 'Blocked browser '.$i,
                'blocked_at' => now()->subDays($i),
                'created_at' => now()->subDays($i),
                'updated_at' => now(),
            ]);
        }

        for ($i = 1; $i <= 22; $i++) {
            DB::table('farast_security_events')->insert([
                'user_id' => $user->id,
                'event' => 'pagination_event_'.$i,
                'ip' => '203.0.115.'.(($i % 200) + 1),
                'user_agent' => 'Audit browser '.$i,
                'meta' => json_encode(['sequence' => $i]),
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->get(route('platform.security', [
            'sessions_page' => 2,
            'blocked_page' => 2,
            'events_page' => 2,
        ]));

        $response->assertOk()
            ->assertViewHas('sessions', function ($sessions) {
                return $sessions->total() === 18 && $sessions->count() === 3;
            })
            ->assertViewHas('blockedDevices', function ($devices) {
                return $devices->total() === 12 && $devices->count() === 2;
            })
            ->assertViewHas('events', function ($events) {
                return $events->total() === 22 && $events->count() === 2;
            });
    }

    public function test_blocked_device_sessions_are_revoked_and_owner_can_unblock_device(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->get(route('platform.security'))->assertOk();

        $deviceHash = hash('sha256', 'persistent-device-cookie');
        $sessionId = DB::table('farast_account_sessions')->insertGetId([
            'user_id' => $user->id,
            'session_key' => hash('sha256', 'blocked-device-session'),
            'device_key_hash' => $deviceHash,
            'session_id_encrypted' => Crypt::encryptString('blocked-device-session'),
            'device_label' => 'Windows · Firefox',
            'ip_address' => '203.0.113.55',
            'user_agent' => 'Firefox test device',
            'last_activity' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post(route('platform.security.devices.block', $sessionId), [
            'current_password' => 'StrongPassword123',
        ])->assertRedirect();

        $blockedDeviceId = DB::table('farast_blocked_devices')
            ->where('user_id', $user->id)
            ->where('device_key_hash', $deviceHash)
            ->value('id');

        $this->assertNotNull($blockedDeviceId);
        $this->assertNotNull(DB::table('farast_account_sessions')->where('id', $sessionId)->value('revoked_at'));

        $this->post(route('platform.security.devices.unblock', $blockedDeviceId), [
            'current_password' => 'StrongPassword123',
        ])->assertRedirect();

        $this->assertNotNull(DB::table('farast_blocked_devices')->where('id', $blockedDeviceId)->value('unblocked_at'));
    }

}
