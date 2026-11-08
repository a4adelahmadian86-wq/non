<?php

namespace Tests\Feature;

use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class RegistrationExperienceTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '09123456789';

    private function verifiedRegistrationSession(?int $verifiedAt = null): array
    {
        return [
            'register.mobile' => self::PHONE,
            'register.verified' => true,
            'register.verified_mobile' => self::PHONE,
            'register.verified_at' => $verifiedAt ?? now()->timestamp,
        ];
    }

    public function test_registration_page_requires_a_phone_session(): void
    {
        $this->get(route('register'))
            ->assertRedirect(route('login'));
    }

    public function test_registration_otp_step_has_accessible_input_and_live_feedback(): void
    {
        $this->withSession(['register.mobile' => self::PHONE])
            ->get(route('register'))
            ->assertOk()
            ->assertSee(self::PHONE)
            ->assertSee('id="registerOtpCode"', false)
            ->assertSee('autocomplete="one-time-code"', false)
            ->assertSee('id="registerMessage"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('بازگشت به ورود');
    }

    public function test_valid_registration_otp_moves_to_account_details(): void
    {
        OtpChallenge::create([
            'mobile' => self::PHONE,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(3),
        ]);

        $this->withSession(['register.mobile' => self::PHONE])
            ->post(route('register.otp.verify'), ['code' => '123456'])
            ->assertRedirect(route('register'));

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('شماره موبایل تأیید شد')
            ->assertSee('id="registerName"', false)
            ->assertSee('id="registerEmail"', false)
            ->assertSee('id="registerPassword"', false)
            ->assertSee('id="registerPasswordConfirmation"', false);
    }

    public function test_invalid_registration_otp_does_not_unlock_account_details(): void
    {
        OtpChallenge::create([
            'mobile' => self::PHONE,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(3),
        ]);

        $this->withSession(['register.mobile' => self::PHONE])
            ->postJson(route('register.otp.verify'), ['code' => '000000'])
            ->assertUnprocessable()
            ->assertSessionMissing('register.verified_mobile');

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('id="registerOtpCode"', false)
            ->assertDontSee('id="registerName"', false);
    }

    public function test_registration_rejects_an_expired_phone_verification(): void
    {
        $this->withSession($this->verifiedRegistrationSession(now()->subMinutes(11)->timestamp))
            ->post(route('register.store'), [
                'name' => 'کاربر آزمایشی',
                'email' => 'expired@example.test',
                'password' => 'StrongPass123',
                'password_confirmation' => 'StrongPass123',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['mobile' => self::PHONE]);
    }

    public function test_verified_registration_creates_account_and_logs_user_in(): void
    {
        $email = Mockery::mock(EmailService::class);
        $email->shouldReceive('sendWelcome')->once()->andReturnNull();
        $this->instance(EmailService::class, $email);

        $this->withSession($this->verifiedRegistrationSession())
            ->post(route('register.store'), [
                'name' => 'کاربر آزمایشی',
                'email' => 'new-user@example.test',
                'password' => 'StrongPass123',
                'password_confirmation' => 'StrongPass123',
            ])
            ->assertRedirect(route('editor'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'name' => 'کاربر آزمایشی',
            'mobile' => self::PHONE,
            'email' => 'new-user@example.test',
            'is_verified' => true,
        ]);
    }
}
