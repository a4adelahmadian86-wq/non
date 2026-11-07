<?php

namespace Tests\Feature;

use App\Models\EmailOtpChallenge;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\EmailService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\TestCase;

class AuthSecondaryFlowsExperienceTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '09123456789';
    private const EMAIL = 'account@example.test';

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Account QA',
            'mobile' => self::PHONE,
            'email' => self::EMAIL,
            'password' => 'OldStrongPass123',
            'is_verified' => true,
            'is_blocked' => false,
        ]);
    }

    private function verifiedResetSession(): array
    {
        return [
            'password_reset.mobile' => self::PHONE,
            'password_reset.verified' => true,
            'password_reset.verified_mobile' => self::PHONE,
            'password_reset.verified_at' => now()->timestamp,
        ];
    }

    public function test_password_entry_page_has_an_accessible_current_password_field(): void
    {
        $this->withSession(['login.phone' => self::PHONE])
            ->get(route('login.password'))
            ->assertOk()
            ->assertSee('id="loginPassword"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('for="loginPassword"', false)
            ->assertSee(route('password.request'), false);
    }

    public function test_email_login_and_otp_pages_expose_accessible_fields_and_feedback(): void
    {
        $this->get(route('login.email'))
            ->assertOk()
            ->assertSee('id="emailLoginInput"', false)
            ->assertSee('for="emailLoginInput"', false)
            ->assertSee('id="emailMessage"', false)
            ->assertSee('aria-live="polite"', false);

        $this->withSession(['email_login.email' => self::EMAIL])
            ->get(route('login.email'))
            ->assertOk()
            ->assertSee('id="emailOtpCode"', false)
            ->assertSee('autocomplete="one-time-code"', false)
            ->assertSee('id="emailOtpMessage"', false);
    }

    public function test_email_mobile_step_displays_the_bound_account_number_and_otp_feedback(): void
    {
        $user = $this->makeUser();

        $this->withSession(['email_login.user_id' => $user->id])
            ->get(route('login.email.mobile'))
            ->assertOk()
            ->assertSee('id="emailAccountMobile"', false)
            ->assertSee('readonly', false)
            ->assertSee(self::PHONE);

        $this->withSession([
            'email_login.user_id' => $user->id,
            'email_login.mobile' => self::PHONE,
        ])->get(route('login.email.mobile'))
            ->assertOk()
            ->assertSee('id="emailMobileOtpCode"', false)
            ->assertSee('id="mobileOtpMessage"', false);
    }

    public function test_forgot_password_page_and_otp_step_are_registered_and_accessible(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('id="resetMobile"', false)
            ->assertSee('id="resetMessage"', false)
            ->assertSee('aria-live="polite"', false);

        $this->get(route('password.request').'?step=otp')
            ->assertOk()
            ->assertSee('id="resetOtpCode"', false)
            ->assertSee('autocomplete="one-time-code"', false);
    }

    public function test_password_reset_request_sends_sms_only_for_an_existing_account(): void
    {
        $this->makeUser();
        $key = 'farast:otp:send:sms:'.hash('sha256', self::PHONE);
        RateLimiter::clear($key);

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('sendOtp')->once()->with(self::PHONE, Mockery::type('string'))->andReturnNull();
        $this->instance(SmsService::class, $sms);

        $this->postJson(route('password.email'), ['mobile' => self::PHONE])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertSessionHas('password_reset.mobile', self::PHONE);

        $this->assertDatabaseHas('otp_challenges', ['mobile' => self::PHONE]);
    }

    public function test_valid_password_reset_otp_unlocks_the_reset_form(): void
    {
        OtpChallenge::create([
            'mobile' => self::PHONE,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(3),
        ]);

        $this->withSession(['password_reset.mobile' => self::PHONE])
            ->post(route('password.verify'), ['code' => '123456'])
            ->assertRedirect(route('password.reset'));

        $this->get(route('password.reset'))
            ->assertOk()
            ->assertSee('id="newPassword"', false)
            ->assertSee('id="newPasswordConfirmation"', false)
            ->assertSee('autocomplete="new-password"', false);
    }

    public function test_password_reset_requires_recent_phone_verification(): void
    {
        $this->withSession([
            'password_reset.mobile' => self::PHONE,
            'password_reset.verified_mobile' => self::PHONE,
            'password_reset.verified_at' => now()->subMinutes(11)->timestamp,
        ])->get(route('password.reset'))->assertForbidden();
    }

    public function test_password_reset_updates_password_and_logs_the_user_in(): void
    {
        $user = $this->makeUser();
        $email = Mockery::mock(EmailService::class);
        $email->shouldReceive('sendPasswordChanged')->once()->with(Mockery::on(fn ($candidate) => $candidate->is($user)))->andReturnNull();
        $this->instance(EmailService::class, $email);

        $this->withSession($this->verifiedResetSession())
            ->post(route('password.update'), [
                'password' => 'NewStrongPass456',
                'password_confirmation' => 'NewStrongPass456',
            ])
            ->assertRedirect(route('editor'));

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertTrue(Hash::check('NewStrongPass456', $user->fresh()->password));
    }
}
