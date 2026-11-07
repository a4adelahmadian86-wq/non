<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutPaymentClarityTest extends TestCase
{
    use RefreshDatabase;

    private function makeCheckout(int $balance, array $snapshot = ['kind' => 'typing_final']): array
    {
        $user = User::create([
            'name' => 'Checkout QA',
            'mobile' => '09123456789',
            'password' => 'secret',
        ]);

        $wallet = Wallet::create([
            'user_id' => $user->id,
            'balance_rials' => $balance,
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'subtotal_rials' => 100000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 125000,
            'status' => 'pending',
            'pricing_snapshot' => $snapshot,
        ]);

        return [$user, $wallet, $order];
    }

    public function test_checkout_explains_exact_wallet_shortfall_and_links_to_top_up(): void
    {
        [$user, , $order] = $this->makeCheckout(50000);

        $this->actingAs($user)
            ->get(route('checkout', $order))
            ->assertOk()
            ->assertSee('موجودی کیف پول کافی نیست')
            ->assertSee('75,000 ریال')
            ->assertSee('wallet-insufficient', false)
            ->assertSee('aria-describedby=wallet-insufficient', false)
            ->assertSee(route('wallet'), false);
    }

    public function test_checkout_does_not_show_shortfall_when_wallet_covers_the_order(): void
    {
        [$user, , $order] = $this->makeCheckout(150000);

        $this->actingAs($user)
            ->get(route('checkout', $order))
            ->assertOk()
            ->assertDontSee('wallet-insufficient', false)
            ->assertDontSee('aria-describedby=wallet-insufficient', false);
    }

    public function test_zarinpal_option_is_limited_to_eligible_store_orders(): void
    {
        SiteSetting::write('payment_gateway', 'zarinpal');
        SiteSetting::write('gateway_merchant_id', 'test-merchant');

        [$user, , $order] = $this->makeCheckout(0, ['kind' => 'typing_final']);
        $this->actingAs($user)
            ->get(route('checkout', $order))
            ->assertOk()
            ->assertDontSee('پرداخت مستقیم زرین‌پال');

        $storeOrder = Order::create([
            'user_id' => $user->id,
            'subtotal_rials' => 100000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 100000,
            'status' => 'pending',
            'pricing_snapshot' => ['kind' => 'store'],
        ]);

        $this->get(route('checkout', $storeOrder))
            ->assertOk()
            ->assertSee('پرداخت مستقیم زرین‌پال')
            ->assertSee(route('checkout.zarinpal', $storeOrder), false);
    }
}
