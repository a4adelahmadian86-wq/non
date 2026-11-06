<?php

namespace Tests\Feature;

use App\Models\EmailOtpChallenge;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthOtpAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_otp_is_consumed_once_for_registration(): void
    {
        OtpChallenge::create([
            'mobile' => '09123456789',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(3),
        ]);

        $this->withSession(['register.mobile' => '09123456789'])
            ->post('/login/register/verify', ['code' => '123456'])
            ->assertRedirect(route('register'))
            ->assertSessionHas('register.verified', true);

        $this->assertNotNull(OtpChallenge::query()->firstOrFail()->fresh()->consumed_at);

        $this->withSession(['register.mobile' => '09123456789'])
            ->post('/login/register/verify', ['code' => '123456'])
            ->assertStatus(422);
    }

    public function test_email_otp_is_consumed_once_before_mobile_confirmation(): void
    {
        User::create([
            'name' => 'Email QA',
            'mobile' => '09123456789',
            'email' => 'email-qa@example.test',
            'password' => 'secret',
        ]);

        EmailOtpChallenge::create([
            'email' => 'email-qa@example.test',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(3),
        ]);

        $this->withSession(['email_login.email' => 'email-qa@example.test'])
            ->post('/login/email/verify', ['code' => '123456'])
            ->assertRedirect(route('login.email.mobile'))
            ->assertSessionHas('email_login.user_id');

        $this->assertNotNull(EmailOtpChallenge::query()->firstOrFail()->fresh()->consumed_at);

        $this->withSession(['email_login.email' => 'email-qa@example.test'])
            ->post('/login/email/verify', ['code' => '123456'])
            ->assertStatus(422);
    }

    public function test_failed_sms_attempts_are_persisted_and_limited_to_five(): void
    {
        OtpChallenge::create([
            'mobile' => '09123456789',
            'code_hash' => Hash::make('123456'),
            'attempts' => 4,
            'expires_at' => now()->addMinutes(3),
        ]);

        $this->withSession(['register.mobile' => '09123456789'])
            ->post('/login/register/verify', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertSame(5, (int) OtpChallenge::query()->firstOrFail()->attempts);

        $this->withSession(['register.mobile' => '09123456789'])
            ->post('/login/register/verify', ['code' => '123456'])
            ->assertStatus(429);
    }
}
