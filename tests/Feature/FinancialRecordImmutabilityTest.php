<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreCategory;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class FinancialRecordImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private function paidOrder(User $user): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => 10000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 10000,
            'status' => 'paid',
            'pricing_snapshot' => [
                'kind' => 'store',
                'coupon_code' => 'TEST10',
                'coupon_redemption_pending' => true,
            ],
            'paid_at' => now(),
        ]);
    }

    public function test_paid_order_amounts_and_pricing_snapshot_are_immutable_except_coupon_redemption_markers(): void
    {
        $user = User::factory()->create();
        $order = $this->paidOrder($user);

        $snapshot = $order->pricing_snapshot;
        $snapshot['coupon_redemption_pending'] = false;
        $snapshot['coupon_redeemed_at'] = now()->toIso8601String();
        $order->update(['pricing_snapshot' => $snapshot]);

        $this->assertFalse($order->fresh()->pricing_snapshot['coupon_redemption_pending']);

        try {
            $order->update(['total_rials' => 9000]);
            $this->fail('Settled order totals must not be editable.');
        } catch (LogicException $exception) {
            $this->assertSame('paid_order_financial_snapshot_is_immutable', $exception->getMessage());
        }

        try {
            $order->delete();
            $this->fail('Settled orders must not be deleted.');
        } catch (LogicException $exception) {
            $this->assertSame('settled_order_cannot_be_deleted', $exception->getMessage());
        }

        try {
            $snapshot = $order->fresh()->pricing_snapshot;
            $snapshot['total_rials'] = 9000;
            $order->update(['pricing_snapshot' => $snapshot]);
            $this->fail('Paid pricing snapshots must not be rewritten.');
        } catch (LogicException $exception) {
            $this->assertSame('paid_order_pricing_snapshot_is_immutable', $exception->getMessage());
        }
    }

    public function test_settled_payment_financial_identity_is_immutable_but_gateway_reconciliation_metadata_can_be_appended(): void
    {
        $user = User::factory()->create();
        $order = $this->paidOrder($user);
        $payment = Payment::create([
            'order_id' => $order->id,
            'gateway' => 'zarinpal',
            'amount_rials' => 10000,
            'status' => 'paid',
            'transaction_id' => 'IMMUTABLE-PAYMENT-1',
            'gateway_response' => ['ref_id' => 'REF-1'],
            'paid_at' => now(),
        ]);

        $payment->update(['gateway_response' => [
            'ref_id' => 'REF-1',
            'coupon_redemption_reconciliation' => ['status' => 'required'],
        ]]);
        $this->assertSame('required', data_get($payment->fresh()->gateway_response, 'coupon_redemption_reconciliation.status'));

        try {
            $payment->update(['amount_rials' => 9000]);
            $this->fail('Settled payment amounts must not be editable.');
        } catch (LogicException $exception) {
            $this->assertSame('settled_payment_financial_record_is_immutable', $exception->getMessage());
        }

        try {
            $payment->delete();
            $this->fail('Settled payment records must not be deleted.');
        } catch (LogicException $exception) {
            $this->assertSame('settled_payment_cannot_be_deleted', $exception->getMessage());
        }
    }

    public function test_wallet_transactions_and_order_item_snapshots_are_append_only(): void
    {
        $user = User::factory()->create();
        $order = $this->paidOrder($user);
        $wallet = Wallet::create(['user_id' => $user->id, 'balance_rials' => 10000]);
        $transaction = WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'amount_rials' => 1000,
            'balance_before' => 10000,
            'balance_after' => 9000,
            'reference_type' => 'store_order',
            'reference_id' => $order->id,
            'description' => 'Immutable ledger entry',
            'idempotency_key' => 'immutable-ledger-'.$order->id,
        ]);

        try {
            $transaction->update(['balance_after' => 8000]);
            $this->fail('Wallet ledger entries must not be editable.');
        } catch (LogicException $exception) {
            $this->assertSame('wallet_ledger_entries_are_append_only', $exception->getMessage());
        }

        try {
            $transaction->delete();
            $this->fail('Wallet ledger entries must not be deleted.');
        } catch (LogicException $exception) {
            $this->assertSame('wallet_ledger_entries_cannot_be_deleted', $exception->getMessage());
        }

        $category = StoreCategory::create([
            'name' => 'Immutable snapshot category',
            'slug' => 'immutable-snapshot-category',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'seller_id' => $user->id,
            'title' => 'Immutable snapshot product',
            'slug' => 'immutable-snapshot-product',
            'sku' => 'IMMUTABLE-001',
            'type' => 'digital_file',
            'price_rials' => 10000,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $orderItem = StoreOrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title_snapshot' => $product->title,
            'sku_snapshot' => $product->sku,
            'quantity' => 1,
            'unit_price_rials' => 10000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 10000,
            'product_snapshot' => ['title' => $product->title, 'sku' => $product->sku],
        ]);

        try {
            $orderItem->update(['title_snapshot' => 'Mutated title']);
            $this->fail('Order item snapshots must not be editable.');
        } catch (LogicException $exception) {
            $this->assertSame('store_order_item_snapshots_are_immutable', $exception->getMessage());
        }

        try {
            $orderItem->delete();
            $this->fail('Order item snapshots must not be deleted.');
        } catch (LogicException $exception) {
            $this->assertSame('store_order_item_snapshots_cannot_be_deleted', $exception->getMessage());
        }
    }
}
