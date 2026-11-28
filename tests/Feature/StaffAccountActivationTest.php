<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\User;
use App\Services\EmailService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffAccountActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_customer_registration_receives_a_default_primary_responsibility(): void
    {
        $session = app('session')->driver();
        $session->flush();
        $session->put('register.mobile', '09120004570');
        $session->put('register.verified_mobile', '09120004570');
        $session->put('register.verified_at', now()->timestamp);

        $request = Request::create('/register', 'POST', [
            'name' => 'مشتری جدید',
            'password' => 'SecureCustomerPassword123',
            'password_confirmation' => 'SecureCustomerPassword123',
        ]);
        $request->setLaravelSession($session);

        (new AuthController())->registerStore($request, \Mockery::mock(EmailService::class));

        $user = User::query()->where('mobile', '09120004570')->firstOrFail();
        $this->assertDatabaseHas('role_assignments', [
            'user_id' => $user->id,
            'is_primary' => true,
            'active_primary_user_id' => $user->id,
            'title' => 'مشتری عادی',
            'ended_at' => null,
        ]);
    }

    public function test_manager_created_unverified_account_uses_the_existing_mobile_for_otp_activation(): void
    {
        $user = User::factory()->create([
            'mobile' => '09120004567',
            'password' => null,
            'role' => User::ROLE_EMPLOYEE,
            'is_verified' => false,
        ]);
        $session = app('session')->driver();
        $session->flush();
        $sms = \Mockery::mock(SmsService::class);
        $sms->shouldReceive('sendOtp')->once();

        app()->instance(SmsService::class, $sms);
        $request = Request::create('/login/phone', 'POST', ['mobile' => $user->mobile]);
        $request->setLaravelSession($session);

        $response = (new AuthController())->phoneContinue($request);

        $this->assertSame(route('register'), $response->getData(true)['next']);
        $this->assertSame('activate', $response->getData(true)['mode']);
        $this->assertSame($user->mobile, $session->get('register.mobile'));
        $this->assertTrue($session->get('register.staff_activation'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_unverified_preprovisioned_account_cannot_skip_mobile_verification_even_if_it_has_a_password(): void
    {
        $user = User::factory()->create([
            'mobile' => '09120004569',
            'password' => 'TemporaryProvisionedPassword123',
            'role' => User::ROLE_EMPLOYEE,
            'is_verified' => false,
        ]);
        $session = app('session')->driver();
        $session->flush();
        $sms = \Mockery::mock(SmsService::class);
        $sms->shouldReceive('sendOtp')->once();
        app()->instance(SmsService::class, $sms);

        $request = Request::create('/login/phone', 'POST', ['mobile' => $user->mobile]);
        $request->setLaravelSession($session);
        $response = (new AuthController())->phoneContinue($request);

        $this->assertSame('activate', $response->getData(true)['mode']);
        $this->assertSame(route('register'), $response->getData(true)['next']);
    }

    public function test_staff_activation_sets_password_without_replacing_role_or_creating_a_duplicate_user(): void
    {
        $user = User::factory()->create([
            'name' => 'کارشناس اجرایی',
            'mobile' => '09120004568',
            'email' => 'staff@example.test',
            'password' => null,
            'role' => User::ROLE_EMPLOYEE,
            'is_verified' => false,
        ]);
        $session = app('session')->driver();
        $session->flush();
        $session->put('register.mobile', $user->mobile);
        $session->put('register.staff_activation', true);
        $session->put('register.verified_mobile', $user->mobile);
        $session->put('register.verified_at', now()->timestamp);

        $request = Request::create('/register', 'POST', [
            'name' => 'کارشناس اجرایی',
            'email' => 'staff@example.test',
            'password' => 'SecureStaffPassword123',
            'password_confirmation' => 'SecureStaffPassword123',
        ]);
        $request->setLaravelSession($session);

        (new AuthController())->registerStore($request, \Mockery::mock(EmailService::class));

        $this->assertDatabaseCount('users', 1);
        $fresh = $user->fresh();
        $this->assertSame(User::ROLE_EMPLOYEE, $fresh->role);
        $this->assertTrue($fresh->is_verified);
        $this->assertTrue(Hash::check('SecureStaffPassword123', $fresh->password));
        $this->assertSame('staff@example.test', $fresh->email);
    }
}
