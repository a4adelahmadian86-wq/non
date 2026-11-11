<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $user, string $key): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'subtotal_rials' => 2500,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 2500,
            'status' => 'paid',
            'idempotency_key' => $key,
            'paid_at' => now(),
        ]);
    }

    public function test_user_and_order_deletion_cannot_cascade_erase_payment_history(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user, 'retention-order-001');
        $payment = Payment::create([
            'order_id' => $order->id,
            'gateway' => 'zarinpal',
            'authority' => 'RETENTION-AUTH-001',
            'transaction_id' => 'RETENTION-REF-001',
            'amount_rials' => 2500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        try {
            $order->delete();
            self::fail('An order with payment history must not be deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('orders', ['id' => $order->id]);
            $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        }

        $this->expectException(QueryException::class);
        $user->delete();
    }

    public function test_wallet_cannot_be_deleted_while_ledger_entries_exist(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'balance_rials' => 2500]);
        $transaction = WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount_rials' => 2500,
            'balance_before' => 0,
            'balance_after' => 2500,
            'idempotency_key' => 'retention-wallet-001',
            'description' => 'Retention test',
        ]);

        try {
            $wallet->delete();
            self::fail('A wallet with ledger entries must not be deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('wallets', ['id' => $wallet->id]);
            $this->assertDatabaseHas('wallet_transactions', ['id' => $transaction->id]);
        }
    }
}
