<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CapabilityService;
use App\Services\CommerceAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BlockedAccountServiceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocked_administrator_receives_no_capabilities(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_blocked' => true,
        ]);

        $capabilities = app(CapabilityService::class);

        $this->assertFalse($capabilities->forUser($user)['active']);
        $this->assertFalse($capabilities->forUser($user)['unlimited']);
        $this->assertFalse($capabilities->allowed($user, 'can_ai'));
        $this->assertFalse($capabilities->allowed($user, 'can_type'));
    }

    public function test_blocked_administrator_cannot_receive_commerce_authorization(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_blocked' => true,
        ]);

        $decision = app(CommerceAuthorizationService::class)->authorize($user, 'professional.ai');

        $this->assertFalse($decision->allowed);
        $this->assertSame('account_blocked', $decision->denialReason);
    }

    public function test_blocked_administrator_cannot_create_a_usage_reservation(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_blocked' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('account_blocked');

        app(CommerceAuthorizationService::class)->reserve(
            $user,
            'professional.ai',
            1,
            [],
            ['idempotency_key' => 'blocked-account-reservation'],
        );
    }
}
