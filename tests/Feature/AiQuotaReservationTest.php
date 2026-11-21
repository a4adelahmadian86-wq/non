<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\User;
use App\Services\AI\AiQuotaService;
use App\Services\CapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AiQuotaReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_ai_requests_reserve_daily_quota_before_provider_execution(): void
    {
        $user = User::factory()->create();
        $capabilities = Mockery::mock(CapabilityService::class);
        $capabilities->shouldReceive('forUser')->twice()->with(Mockery::on(
            fn (User $resolved): bool => $resolved->is($user)
        ))->andReturn([
            'active' => true,
            'can_ai' => true,
            'unlimited' => false,
            'daily_ai_requests' => 1,
        ]);

        $quota = new AiQuotaService($capabilities);
        $interaction = $quota->reserveInteraction($user, [
            'provider' => 'unresolved',
            'model' => 'pending',
            'operation' => 'selection.rewrite',
            'request_id' => 'quota-reservation-test-1',
            'status' => 'started',
        ]);

        $this->assertSame('started', $interaction->status);
        $this->assertDatabaseHas('ai_interactions', [
            'id' => $interaction->id,
            'user_id' => $user->id,
            'request_id' => 'quota-reservation-test-1',
        ]);

        try {
            $quota->reserveInteraction($user, [
                'provider' => 'unresolved',
                'model' => 'pending',
                'operation' => 'selection.rewrite',
                'request_id' => 'quota-reservation-test-2',
                'status' => 'started',
            ]);
            $this->fail('A pending request must consume the daily quota.');
        } catch (HttpException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
        }

        $this->assertSame(1, AiInteraction::where('user_id', $user->id)->count());
    }

    public function test_blocked_accounts_cannot_reserve_ai_requests(): void
    {
        $user = User::factory()->create();
        $capabilities = Mockery::mock(CapabilityService::class);
        $capabilities->shouldReceive('forUser')->once()->with(Mockery::on(
            fn (User $resolved): bool => $resolved->is($user)
        ))->andReturn([
            'active' => false,
            'can_ai' => false,
            'unlimited' => false,
            'daily_ai_requests' => 0,
        ]);

        $quota = new AiQuotaService($capabilities);

        try {
            $quota->reserveInteraction($user, [
                'provider' => 'unresolved',
                'model' => 'pending',
                'operation' => 'selection.rewrite',
                'request_id' => 'quota-reservation-blocked',
                'status' => 'started',
            ]);
            $this->fail('Blocked accounts must not reserve AI requests.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(0, AiInteraction::where('user_id', $user->id)->count());
    }
}
