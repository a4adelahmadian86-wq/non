<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\WalletLedgerReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletLedgerReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function paidOrder(User $user, int $amount, string $kind): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => $amount,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => $amount,
            'status' => 'paid',
            'pricing_snapshot' => ['kind' => $kind],
            'paid_at' => now(),
        ]);
    }

    private function payment(Order $order, string $gateway, int $amount): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'gateway' => $gateway,
            'amount_rials' => $amount,
            'status' => 'paid',
            'transaction_id' => 'TEST-LEDGER-'.uniqid(),
            'paid_at' => now(),
        ]);
    }

    public function test_wallet_balance_and_ledger_reconcile_against_top_up_and_store_payment_sources(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'balance_rials' => 700]);

        $topUp = $this->paidOrder($user, 1000, 'wallet_topup');
        $this->payment($topUp, 'zarinpal', 1000);
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount_rials' => 1000,
            'balance_before' => 0,
            'balance_after' => 1000,
            'reference_type' => 'wallet_topup',
            'reference_id' => $topUp->id,
            'description' => 'Test top-up',
            'idempotency_key' => 'ledger-topup-'.$topUp->id,
        ]);

        $storeOrder = $this->paidOrder($user, 300, 'store');
        $this->payment($storeOrder, 'wallet', 300);
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'amount_rials' => 300,
            'balance_before' => 1000,
            'balance_after' => 700,
            'reference_type' => 'store_order',
            'reference_id' => $storeOrder->id,
            'description' => 'Test store order',
            'idempotency_key' => 'ledger-store-order-'.$storeOrder->id,
        ]);

        $result = app(WalletLedgerReconciliationService::class)->reconcile(walletId: $wallet->id);

        $this->assertSame(1, $result['summary']['wallets_checked']);
        $this->assertSame(2, $result['summary']['transactions_checked']);
        $this->assertSame('balanced', $result['wallets'][0]['status']);
        $this->assertSame(700, $result['wallets'][0]['balance_rials']);
        $this->assertSame([], $result['wallets'][0]['issues']);
    }

    public function test_wallet_reconciliation_detects_broken_balance_chain_and_unmatched_reference(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'balance_rials' => 40]);

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount_rials' => 50,
            'balance_before' => 0,
            'balance_after' => 50,
            'reference_type' => 'order',
            'reference_id' => 999999,
            'description' => 'Orphaned ledger entry',
            'idempotency_key' => 'ledger-orphaned-entry',
        ]);

        $result = app(WalletLedgerReconciliationService::class)->reconcile(userId: $user->id);
        $codes = array_column($result['wallets'][0]['issues'], 'code');

        $this->assertSame('mismatch', $result['wallets'][0]['status']);
        $this->assertContains('reference_not_reconciled', $codes);
        $this->assertContains('wallet_balance_differs_from_ledger', $codes);
    }

    public function test_nonzero_wallet_without_ledger_is_reported_as_unverified_opening_balance(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'balance_rials' => 250000]);

        $result = app(WalletLedgerReconciliationService::class)->reconcile(walletId: $wallet->id);

        $this->assertSame('needs_review', $result['wallets'][0]['status']);
        $this->assertSame(250000, $result['wallets'][0]['opening_balance_rials']);
        $this->assertSame('opening_balance_unverified', $result['wallets'][0]['issues'][0]['code']);
    }

    public function test_financial_history_cannot_be_deleted_through_parent_cascades(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'balance_rials' => 500]);

        $order = $this->paidOrder($user, 500, 'wallet_topup');
        $payment = $this->payment($order, 'zarinpal', 500);
        $transaction = WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount_rials' => 500,
            'balance_before' => 0,
            'balance_after' => 500,
            'reference_type' => 'wallet_topup',
            'reference_id' => $order->id,
            'description' => 'Retention test top-up',
            'idempotency_key' => 'ledger-retention-'.$order->id,
        ]);

        try {
            $wallet->delete();
            $this->fail('A wallet with a balance or ledger entries must not be deleted.');
        } catch (LogicException $exception) {
            $this->assertSame('wallet_with_balance_or_ledger_cannot_be_deleted', $exception->getMessage());
        }

        try {
            \\Illuminate\\Support\\Facades\\DB::table('wallets')->where('id', $wallet->id)->delete();
            $this->fail('The database must prevent deleting a wallet referenced by ledger entries.');
        } catch (QueryException) {
            $this->assertDatabaseHas('wallet_transactions', ['id' => $transaction->id]);
        }

        try {
            \\Illuminate\\Support\\Facades\\DB::table('orders')->where('id', $order->id)->delete();
            $this->fail('The database must prevent deleting an order referenced by a payment.');
        } catch (QueryException) {
            $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        }

        try {
            \\Illuminate\\Support\\Facades\\DB::table('users')->where('id', $user->id)->delete();
            $this->fail('The database must prevent deleting a customer with financial history.');
        } catch (QueryException) {
            $this->assertDatabaseHas('orders', ['id' => $order->id]);
            $this->assertDatabaseHas('wallet_transactions', ['id' => $transaction->id]);
        }
    }

    public function test_typing_deposit_ledger_reference_uses_deposit_paid_state_not_full_order_paid_state(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'balance_rials' => 800]);

        $topUp = $this->paidOrder($user, 1000, 'wallet_topup');
        $this->payment($topUp, 'zarinpal', 1000);
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount_rials' => 1000,
            'balance_before' => 0,
            'balance_after' => 1000,
            'reference_type' => 'wallet_topup',
            'reference_id' => $topUp->id,
            'idempotency_key' => 'ledger-deposit-test-topup',
        ]);

        $deposit = Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => 200,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 200,
            'status' => 'deposit_paid',
            'pricing_snapshot' => ['kind' => 'typing_deposit'],
            'paid_at' => now(),
        ]);
        $this->payment($deposit, 'wallet', 200);
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'amount_rials' => 200,
            'balance_before' => 1000,
            'balance_after' => 800,
            'reference_type' => 'typing_deposit',
            'reference_id' => $deposit->id,
            'idempotency_key' => 'ledger-typing-deposit-'.$deposit->id,
        ]);

        $result = app(WalletLedgerReconciliationService::class)->reconcile(walletId: $wallet->id);

        $this->assertSame('balanced', $result['wallets'][0]['status']);
    }
}
