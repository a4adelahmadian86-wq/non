<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BlockedAccountSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/__test/active-account-guard', fn () => response('allowed'));
    }

    public function test_blocked_account_is_logged_out_and_html_request_is_redirected(): void
    {
        $user = User::factory()->create(['is_blocked' => true]);

        $this->actingAs($user)
            ->withSession(['sensitive_session_value' => 'must-be-cleared'])
            ->get('/__test/active-account-guard')
            ->assertRedirect(route('login'))
            ->assertSessionHas('warning', 'حساب کاربری شما مسدود شده است. برای پیگیری با پشتیبانی تماس بگیرید.');

        $this->assertGuest();
        $this->assertFalse(session()->has('sensitive_session_value'));
    }

    public function test_blocked_account_receives_json_forbidden_and_session_is_invalidated(): void
    {
        $user = User::factory()->create(['is_blocked' => true]);

        $this->actingAs($user)
            ->withSession(['sensitive_session_value' => 'must-be-cleared'])
            ->getJson('/__test/active-account-guard')
            ->assertForbidden()
            ->assertJsonPath('message', 'حساب کاربری شما مسدود شده است.');

        $this->assertGuest();
        $this->assertFalse(session()->has('sensitive_session_value'));
    }

    public function test_active_account_passes_through_the_guard(): void
    {
        $user = User::factory()->create(['is_blocked' => false]);

        $this->actingAs($user)
            ->get('/__test/active-account-guard')
            ->assertOk()
            ->assertSeeText('allowed');

        $this->assertAuthenticatedAs($user);
    }
}
