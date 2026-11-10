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
            'pricing_snapshot' => [
                'kind' => 'store',
                'cart_items' => [[
                    'title' => 'نمونه فایل دیجیتال',
                    'quantity' => 1,
                    'unit_price_rials' => 100000,
                    'line_total_rials' => 100000,
                ]],
            ],
        ]);

        $this->get(route('checkout', $storeOrder))
            ->assertOk()
            ->assertSee('پرداخت مستقیم زرین‌پال')
            ->assertSee('نمونه فایل دیجیتال')
            ->assertSee('10,000 تومان')
            ->assertDontSee('100,000 ریال')
            ->assertSee(route('checkout.zarinpal', $storeOrder), false);
    }
    public function test_paid_typing_deposit_returns_to_the_original_project_and_restores_upload_acceptance(): void
    {
        \\Illuminate\\Support\\Facades\\Storage::fake('private');

        $user = User::create([
            'name' => 'Deposit QA',
            'mobile' => '09123456780',
            'password' => 'secret',
        ]);

        $project = \\App\\Models\\FarastProject::create([
            'user_id' => $user->id,
            'name' => 'پروژه آزمون فایل',
            'project_type' => 'typing',
            'workflow' => 'source_file',
            'template_code' => 'simple_typing',
            'status' => 'active',
            'context' => ['source_type' => 'printed'],
            'billing_state' => ['status' => 'estimated'],
            'output_state' => ['status' => 'locked'],
            'estimated_pages' => 3,
            'estimated_price_rials' => 90000,
        ]);

        $path = 'typing/'.$user->id.'/homepage-source.pdf';
        $bytes = '%PDF-1.4 deposit project continuity test';
        \\Illuminate\\Support\\Facades\\Storage::disk('private')->put($path, $bytes);

        $order = Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => 30000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 30000,
            'status' => 'deposit_pending',
            'pricing_snapshot' => [
                'kind' => 'typing_deposit',
                'source_path' => $path,
                'source_hash' => hash('sha256', $bytes),
                'estimated_pages' => 3,
                'project_id' => $project->id,
                'deposit_credit_rials' => 30000,
            ],
        ]);

        Wallet::create(['user_id' => $user->id, 'balance_rials' => 50000]);

        $response = $this->actingAs($user)->post(route('checkout.wallet', $order), [
            'accept_terms' => '1',
        ]);

        $response->assertRedirect(route('editor', ['project' => $project->id]));
        $this->assertSame($order->id, session('typing_preflight_deposit_order'));
        $this->assertSame($project->id, session('typing_preflight_accepted.project_id'));
        $this->assertSame($path, session('typing_preflight_accepted.path'));
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'deposit_paid',
        ]);
    }

}
