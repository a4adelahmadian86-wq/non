<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EmailService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\TestCase;

class AuthOtpRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_otp_delivery_is_limited_per_phone_across_different_ips(): void
    {
        $phone = '09120001122';
        $key = 'farast:otp:send:sms:'.hash('sha256', $phone);
        RateLimiter::clear($key);

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('sendOtp')->times(3);
        $this->instance(SmsService::class, $sms);

        foreach (['192.0.2.10', '192.0.2.11', '192.0.2.12'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/login/request-otp', ['mobile' => $phone])
                ->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.13'])
            ->postJson('/login/request-otp', ['mobile' => $phone])
            ->assertUnprocessable();

        $this->assertDatabaseCount('otp_challenges', 3);
    }

    public function test_email_otp_delivery_is_limited_per_email_across_different_ips(): void
    {
        $emailAddress = 'otp-rate-limit@example.test';
        $key = 'farast:otp:send:email:'.hash('sha256', $emailAddress);
        RateLimiter::clear($key);

        $user = User::factory()->create(['email' => $emailAddress]);
        $email = Mockery::mock(EmailService::class);
        $email->shouldReceive('sendOtp')->times(3);
        $this->instance(EmailService::class, $email);

        foreach (['198.51.100.10', '198.51.100.11', '198.51.100.12'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/login/email', ['email' => $emailAddress])
                ->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.13'])
            ->postJson('/login/email', ['email' => $emailAddress])
            ->assertUnprocessable();

        $this->assertDatabaseCount('email_otp_challenges', 3);
    }
    public function test_login_entry_page_exposes_accessible_mobile_login_feedback(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('id="loginMobile"', false)
            ->assertSee('autocomplete="tel"', false)
            ->assertSee('id="loginMessage"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('ورود با ایمیل')
            ->assertDontSee('alert(');
    }

}
