<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialIdempotencyAndRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $mobile): User
    {
        return User::create([
            'name' => 'Financial QA '.$mobile,
            'mobile' => $mobile,
            'email' => $mobile.'@example.test',
            'password' => 'secret',
            'role' => User::ROLE_EMPLOYEE,
        ]);
    }

    private function order(User $user, string $key): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'idempotency_key' => $key,
            'subtotal_rials' => 10000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 10000,
            'status' => 'pending',
            'pricing_snapshot' => ['kind' => 'integrity-test'],
        ]);
    }

    public function test_order_and_payment_idempotency_keys_resolve_to_one_record(): void
    {
        $user = $this->user('09140001001');
        $order = $this->order($user, 'test-order-once');

        $sameOrder = Order::firstOrCreate(
            ['idempotency_key' => 'test-order-once'],
            ['user_id' => $user->id, 'subtotal_rials' => 10000, 'total_rials' => 10000]
        );
        $this->assertSame($order->id, $sameOrder->id);

        $payment = Payment::firstOrCreate(
            ['idempotency_key' => 'test-payment-once'],
            ['order_id' => $order->id, 'gateway' => 'test', 'amount_rials' => 10000, 'status' => 'pending']
        );
        $samePayment = Payment::firstOrCreate(
            ['idempotency_key' => 'test-payment-once'],
            ['order_id' => $order->id, 'gateway' => 'test', 'amount_rials' => 10000, 'status' => 'pending']
        );

        $this->assertSame($payment->id, $samePayment->id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_payment_history_prevents_order_deletion(): void
    {
        $user = $this->user('09140001002');
        $order = $this->order($user, 'retained-order');

        Payment::create([
            'order_id' => $order->id,
            'idempotency_key' => 'retained-payment',
            'gateway' => 'test',
            'amount_rials' => 10000,
            'status' => 'paid',
        ]);

        $this->expectException(QueryException::class);
        $order->delete();
    }

    public function test_wallet_ledger_prevents_wallet_and_account_deletion(): void
    {
        $user = $this->user('09140001003');
        $wallet = Wallet::create(['user_id' => $user->id, 'balance_rials' => 5000]);

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount_rials' => 5000,
            'balance_before' => 0,
            'balance_after' => 5000,
            'reference_type' => 'test',
            'reference_id' => 1,
            'description' => 'Retention test',
            'idempotency_key' => 'retention-wallet-ledger',
        ]);

        try {
            $wallet->delete();
            $this->fail('A wallet with ledger entries must not be deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('wallets', ['id' => $wallet->id]);
            $this->assertDatabaseHas('wallet_transactions', ['wallet_id' => $wallet->id]);
        }

        $this->expectException(QueryException::class);
        $user->delete();
    }

    public function test_wallet_ledger_entries_are_immutable_through_eloquent(): void
    {
        $user = $this->user('09140001005');
        $wallet = Wallet::create(['user_id' => $user->id, 'balance_rials' => 5000]);
        $transaction = WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount_rials' => 5000,
            'balance_before' => 0,
            'balance_after' => 5000,
            'reference_type' => 'test',
            'reference_id' => 2,
            'description' => 'Immutable ledger test',
            'idempotency_key' => 'immutable-wallet-ledger',
        ]);

        try {
            $transaction->update(['description' => 'tampered']);
            $this->fail('Wallet ledger entries must not be updated.');
        } catch (\LogicException $exception) {
            $this->assertSame('wallet_transaction_is_immutable', $exception->getMessage());
        }

        try {
            $transaction->delete();
            $this->fail('Wallet ledger entries must not be deleted.');
        } catch (\LogicException $exception) {
            $this->assertSame('wallet_transaction_is_immutable', $exception->getMessage());
        }

        $this->assertDatabaseHas('wallet_transactions', [
            'id' => $transaction->id,
            'description' => 'Immutable ledger test',
        ]);
        try {
            DB::table('wallet_transactions')->where('id', $transaction->id)->update(['description' => 'query-builder tampering']);
            $this->fail('Database constraints must reject query-builder updates to wallet ledger entries.');
        } catch (QueryException) {
            $this->assertDatabaseHas('wallet_transactions', [
                'id' => $transaction->id,
                'description' => 'Immutable ledger test',
            ]);
        }

        try {
            DB::table('wallet_transactions')->where('id', $transaction->id)->delete();
            $this->fail('Database constraints must reject query-builder deletes of wallet ledger entries.');
        } catch (QueryException) {
            $this->assertDatabaseHas('wallet_transactions', [
                'id' => $transaction->id,
                'description' => 'Immutable ledger test',
            ]);
        }

    }

    public function test_account_with_order_history_cannot_be_deleted(): void
    {
        $user = $this->user('09140001004');
        $this->order($user, 'account-order-retention');

        $this->expectException(QueryException::class);
        $user->delete();
    }
}
