<?php

namespace Tests\Feature;

use App\Models\FarastCharge;
use App\Models\FarastPricingPolicyVersion;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CommerceAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RefundIdempotencyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function createCharge(User $user, string $key): FarastCharge
    {
        $reservation = app(CommerceAuthorizationService::class)->reserve(
            $user,
            'refund.integrity',
            1,
            [],
            ['policy_code' => 'refund.integrity', 'unit' => 'request', 'idempotency_key' => $key],
        );

        app(CommerceAuthorizationService::class)->commit($reservation);

        return FarastCharge::where('idempotency_key', $key)->firstOrFail();
    }

    protected function setUp(): void
    {
        parent::setUp();

        FarastPricingPolicyVersion::create([
            'policy_code' => 'refund.integrity',
            'version' => 1,
            'capability_code' => 'refund.integrity',
            'unit' => 'request',
            'currency' => 'IRR',
            'unit_price' => 10000,
            'additional_unit_price' => 10000,
            'fee_amount' => 500,
            'discount_basis_points' => 0,
            'tax_basis_points' => 1000,
            'payg_multiplier_basis_points' => 10000,
            'included_quantity' => 0,
            'effective_from' => now()->subMinute(),
            'checksum' => hash('sha256', 'refund.integrity|1'),
        ]);
    }

    public function test_same_refund_request_is_idempotent_but_key_cannot_be_reused_for_another_charge(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        Wallet::create(['user_id' => $firstUser->id, 'balance_rials' => 50000]);
        Wallet::create(['user_id' => $secondUser->id, 'balance_rials' => 50000]);

        $firstCharge = $this->createCharge($firstUser, 'charge-one');
        $secondCharge = $this->createCharge($secondUser, 'charge-two');
        $commerce = app(CommerceAuthorizationService::class);

        $firstRefund = $commerce->refund($firstCharge, 1000, 'support adjustment', 'shared-refund-key');
        $retry = $commerce->refund($firstCharge, 1000, 'support adjustment', 'shared-refund-key');

        $this->assertSame($firstRefund->id, $retry->id);
        $this->assertSame(1, FarastRefund::where('charge_id', $firstCharge->id)->count());

        $balanceBefore = (int) Wallet::where('user_id', $secondUser->id)->value('balance_rials');

        try {
            $commerce->refund($secondCharge, 1000, 'support adjustment', 'shared-refund-key');
            $this->fail('A refund idempotency key must not be reusable for another charge.');
        } catch (RuntimeException $exception) {
            $this->assertSame('refund_idempotency_key_conflict', $exception->getMessage());
        }

        $this->assertSame($balanceBefore, (int) Wallet::where('user_id', $secondUser->id)->value('balance_rials'));
        $this->assertSame(1, \App\Models\FarastRefund::where('idempotency_key', 'shared-refund-key')->count());
    }

    public function test_same_refund_key_cannot_be_reused_with_a_different_amount_or_reason(): void
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'balance_rials' => 50000]);
        $charge = $this->createCharge($user, 'charge-amount');

        $commerce = app(CommerceAuthorizationService::class);
        $commerce->refund($charge, 1000, 'original reason', 'refund-amount-key');

        try {
            $commerce->refund($charge, 2000, 'changed reason', 'refund-amount-key');
            $this->fail('A refund retry must match the original amount and reason.');
        } catch (RuntimeException $exception) {
            $this->assertSame('refund_idempotency_key_conflict', $exception->getMessage());
        }

        $this->assertSame(1, \App\Models\FarastRefund::where('idempotency_key', 'refund-amount-key')->count());
    }
}
