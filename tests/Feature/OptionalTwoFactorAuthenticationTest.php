<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LocalTotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OptionalTwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(?string $secret = null): User
    {
        $user = User::create([
            'name' => 'Two Factor QA',
            'mobile' => '09123456781',
            'email' => 'two-factor@example.test',
            'password' => 'StrongPassword123',
            'role' => 'user',
            'is_verified' => true,
            'is_blocked' => false,
        ]);

        if ($secret !== null) {
            $user->forceFill([
                'two_factor_secret' => Crypt::encryptString($secret),
                'two_factor_enabled_at' => now(),
            ])->save();
        }

        return $user;
    }

    public function test_two_factor_is_not_required_for_users_who_have_not_enabled_it(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get(route('platform.security'))
            ->assertOk()
            ->assertSee('تأیید دومرحله‌ای (اختیاری)');
    }

    public function test_enabled_account_requires_totp_before_workspace_access(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $user = $this->makeUser($secret);
        $totp = app(LocalTotpService::class);

        $this->actingAs($user)
            ->get(route('platform.security'))
            ->assertRedirect(route('two-factor.challenge'));

        $this->get(route('two-factor.challenge'))->assertOk();

        $code = $totp->codeAtCounter($secret, intdiv(time(), 30));
        $this->post(route('two-factor.challenge.verify'), ['code' => $code])
            ->assertRedirect(route('platform.security'));

        $this->get(route('platform.security'))->assertOk();
        $this->assertTrue(session('auth.two_factor_passed'));
        $this->assertNotNull($user->fresh()->two_factor_last_counter);

        $this->withSession(['auth.two_factor_passed' => false])
            ->post(route('two-factor.challenge.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');
    }

    public function test_recovery_code_is_consumed_once_and_cannot_be_reused(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $user = $this->makeUser($secret);
        $totp = app(LocalTotpService::class);
        $recovery = 'ABCD-EF01-2345';

        $id = DB::table('farast_two_factor_recovery_codes')->insertGetId([
            'user_id' => $user->id,
            'code_hash' => hash('sha256', $totp->normalizeRecoveryCode($recovery)),
            'used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('two-factor.challenge.verify'), ['code' => $recovery])
            ->assertRedirect();

        $this->assertNotNull(DB::table('farast_two_factor_recovery_codes')->where('id', $id)->value('used_at'));

        $this->withSession(['auth.two_factor_passed' => false])
            ->post(route('two-factor.challenge.verify'), ['code' => $recovery])
            ->assertSessionHasErrors('code');
    }

    public function test_enrollment_requires_a_valid_totp_and_creates_hashed_recovery_codes(): void
    {
        $user = $this->makeUser();
        $totp = app(LocalTotpService::class);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $code = $totp->codeAtCounter($secret, intdiv(time(), 30));

        $this->actingAs($user)
            ->withSession([
                'farast.two_factor.pending_secret' => $secret,
                'farast.two_factor.pending_at' => now()->timestamp,
            ])
            ->post(route('platform.security.two-factor.enable'), [
                'current_password' => 'StrongPassword123',
                'code' => $code,
            ])
            ->assertRedirect()
            ->assertSessionHas('two_factor_recovery_codes');

        $this->assertNotNull($user->fresh()->two_factor_enabled_at);
        $this->assertSame(10, DB::table('farast_two_factor_recovery_codes')
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->count());
        $this->assertTrue(session('auth.two_factor_passed'));
    }
}
