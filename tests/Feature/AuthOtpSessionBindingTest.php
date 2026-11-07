<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\User;
use App\Services\EmailService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuthOtpSessionBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rejects_a_verified_flag_bound_to_another_phone(): void
    {
        $session = app('session')->driver();
        $session->flush();
        $session->put('register.mobile', '09120002222');
        $session->put('register.verified', true);
        $session->put('register.verified_mobile', '09120001111');

        $request = Request::create('/register', 'POST', []);
        $request->setLaravelSession($session);

        try {
            (new AuthController())->registerStore($request, \Mockery::mock(EmailService::class));
            $this->fail('Registration must require verification of the exact target phone.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_requesting_a_new_registration_otp_clears_previous_phone_verification(): void
    {
        $session = app('session')->driver();
        $session->flush();
        $session->put('register.verified', true);
        $session->put('register.verified_mobile', '09120001111');

        $sms = \Mockery::mock(SmsService::class);
        $sms->shouldReceive('sendOtp')->once();

        $request = Request::create('/login/request-otp', 'POST', ['mobile' => '09120002222']);
        $request->setLaravelSession($session);

        (new AuthController())->requestOtp($request, $sms);

        $this->assertSame('09120002222', $session->get('register.mobile'));
        $this->assertFalse((bool) $session->get('register.verified', false));
        $this->assertNull($session->get('register.verified_mobile'));
    }

    public function test_password_reset_rejects_verification_bound_to_another_phone(): void
    {
        $user = User::factory()->create(['mobile' => '09120002222']);
        $session = app('session')->driver();
        $session->flush();
        $session->put('password_reset.mobile', $user->mobile);
        $session->put('password_reset.verified', true);
        $session->put('password_reset.verified_mobile', '09120001111');

        $request = Request::create('/password/reset', 'POST', [
            'password' => 'NewSecurePassword123',
            'password_confirmation' => 'NewSecurePassword123',
        ]);
        $request->setLaravelSession($session);

        try {
            (new AuthController())->resetPassword($request, \Mockery::mock(EmailService::class));
            $this->fail('Password reset must require verification of the exact target phone.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse(password_verify('NewSecurePassword123', $user->fresh()->password));
    }

    public function test_new_email_otp_request_clears_previous_email_login_identity(): void
    {
        $email = 'new-account@example.test';
        User::factory()->create(['email' => $email]);

        $session = app('session')->driver();
        $session->flush();
        $session->put('email_login.user_id', 99999);
        $session->put('email_login.mobile', '09120001111');

        $mailer = \Mockery::mock(EmailService::class);
        $mailer->shouldReceive('sendOtp')->once();

        $request = Request::create('/login/email', 'POST', ['email' => $email]);
        $request->setLaravelSession($session);

        (new AuthController())->requestEmailOtp($request, $mailer);

        $this->assertSame($email, $session->get('email_login.email'));
        $this->assertNull($session->get('email_login.user_id'));
        $this->assertNull($session->get('email_login.mobile'));
    }
}
