<?php

namespace Tests\\Feature;

use App\\Models\\Order;
use App\\Models\\Payment;
use App\\Models\\SiteSetting;
use App\\Models\\User;
use App\\Models\\Wallet;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Http;
use Tests\\TestCase;

class WalletTopUpTest extends TestCase
{
    use RefreshDatabase;

    private function enableGateway(): void
    {
        SiteSetting::write('payment_gateway', 'zarinpal');
        SiteSetting::write('gateway_merchant_id', 'test-merchant-id');
        SiteSetting::write('zarinpal_sandbox', true);
    }

    public function test_top_up_is_rejected_when_gateway_is_not_configured(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/wallet')
            ->post('/wallet/top-up', ['amount_rials' => 100000])
            ->assertRedirect('/wallet')
            ->assertSessionHasErrors('topup');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_verified_top_up_credits_wallet_exactly_once(): void
    {
        $this->enableGateway();
        $user = User::factory()->create();

        Http::fake([
            'https://sandbox.zarinpal.com/pg/v4/payment/request.json' => Http::response([
                'data' => ['code' => 100, 'authority' => 'A000000000000000000000000000000001'],
            ], 200),
            'https://sandbox.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 123456789],
            ], 200),
        ]);

        $this->actingAs($user)
            ->post('/wallet/top-up', ['amount_rials' => 250000])
            ->assertRedirect('https://sandbox.zarinpal.com/pg/StartPay/A000000000000000000000000000000001');

        $order = Order::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('wallet_topup', $order->pricing_snapshot['kind']);
        $this->assertSame('pending', $order->status);

        $this->actingAs($user)
            ->get('/wallet/top-up/zarinpal/callback?Authority=A000000000000000000000000000000001&Status=OK')
            ->assertRedirect(route('wallet'))
            ->assertSessionHas('status');

        $this->assertSame(250000, (int) Wallet::query()->where('user_id', $user->id)->value('balance_rials'));
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'paid']);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => Wallet::query()->where('user_id', $user->id)->value('id'),
            'type' => 'credit',
            'amount_rials' => 250000,
            'idempotency_key' => 'wallet-topup-order-'.$order->id,
        ]);

        $this->get('/wallet/top-up/zarinpal/callback?Authority=A000000000000000000000000000000001&Status=OK')
            ->assertRedirect(route('wallet'));

        $this->assertSame(250000, (int) Wallet::query()->where('user_id', $user->id)->value('balance_rials'));
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_cancelled_top_up_never_credits_wallet(): void
    {
        $this->enableGateway();
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => 50000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 50000,
            'status' => 'pending',
            'pricing_snapshot' => ['kind' => 'wallet_topup', 'amount_rials' => 50000],
        ]);
        Payment::create([
            'order_id' => $order->id,
            'gateway' => 'zarinpal',
            'authority' => 'A000000000000000000000000000000002',
            'transaction_id' => 'A000000000000000000000000000000002',
            'amount_rials' => 50000,
            'status' => 'pending',
        ]);

        $this->get('/wallet/top-up/zarinpal/callback?Authority=A000000000000000000000000000000002&Status=NOK')
            ->assertRedirect(route('wallet'))
            ->assertSessionHasErrors('topup');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'failed']);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'failed']);
        $this->assertDatabaseMissing('wallet_transactions', ['reference_id' => $order->id]);
        $this->assertSame(0, (int) Wallet::query()->where('user_id', $user->id)->value('balance_rials'));
    }

    public function test_top_up_rejects_mismatched_payment_amount_without_calling_gateway(): void
    {
        $this->enableGateway();
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => 10000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 10000,
            'status' => 'pending',
            'pricing_snapshot' => ['kind' => 'wallet_topup', 'amount_rials' => 10000],
        ]);
        Payment::create([
            'order_id' => $order->id,
            'gateway' => 'zarinpal',
            'authority' => 'A000000000000000000000000000000003',
            'amount_rials' => 20000,
            'status' => 'pending',
        ]);
        Http::fake();

        $this->get('/wallet/top-up/zarinpal/callback?Authority=A000000000000000000000000000000003&Status=OK')
            ->assertRedirect(route('wallet'))
            ->assertSessionHasErrors('topup');

        Http::assertNothingSent();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
        $this->assertSame(0, (int) Wallet::query()->where('user_id', $user->id)->value('balance_rials'));
    }
}
