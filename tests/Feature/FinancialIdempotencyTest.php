<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $user, string $key): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => 1000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 1000,
            'status' => 'pending',
            'idempotency_key' => $key,
        ]);
    }

    public function test_models_generate_fallback_idempotency_keys(): void
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'subtotal_rials' => 1000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 1000,
            'status' => 'pending',
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'gateway' => 'wallet',
            'amount_rials' => 1000,
            'status' => 'pending',
        ]);

        self::assertNotEmpty($order->idempotency_key);
        self::assertNotEmpty($payment->idempotency_key);
    }

    public function test_different_customers_can_use_the_same_order_idempotency_key(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $firstOrder = $this->order($first, 'checkout-request-001');
        $secondOrder = $this->order($second, 'checkout-request-001');

        self::assertNotSame($firstOrder->id, $secondOrder->id);
        self::assertSame($first->id, $firstOrder->user_id);
        self::assertSame($second->id, $secondOrder->user_id);
    }

    public function test_order_idempotency_key_remains_unique_for_the_same_customer(): void
    {
        $user = User::factory()->create();
        $this->order($user, 'checkout-request-001');

        $this->expectException(QueryException::class);
        $this->order($user, 'checkout-request-001');
    }

    public function test_gateway_authority_is_unique_per_gateway(): void
    {
        $user = User::factory()->create();
        $orderA = $this->order($user, 'order-a');
        $orderB = $this->order($user, 'order-b');

        Payment::create([
            'order_id' => $orderA->id,
            'gateway' => 'zarinpal',
            'authority' => 'AUTH-UNIQUE-001',
            'amount_rials' => 1000,
            'status' => 'pending',
        ]);

        $this->expectException(QueryException::class);
        Payment::create([
            'order_id' => $orderB->id,
            'gateway' => 'zarinpal',
            'authority' => 'AUTH-UNIQUE-001',
            'amount_rials' => 1000,
            'status' => 'pending',
        ]);
    }

    public function test_gateway_transaction_reference_is_unique_per_gateway(): void
    {
        $user = User::factory()->create();
        $orderA = $this->order($user, 'order-c');
        $orderB = $this->order($user, 'order-d');

        Payment::create([
            'order_id' => $orderA->id,
            'gateway' => 'zarinpal',
            'transaction_id' => 'REF-UNIQUE-001',
            'amount_rials' => 1000,
            'status' => 'paid',
        ]);

        $this->expectException(QueryException::class);
        Payment::create([
            'order_id' => $orderB->id,
            'gateway' => 'zarinpal',
            'transaction_id' => 'REF-UNIQUE-001',
            'amount_rials' => 1000,
            'status' => 'paid',
        ]);
    }

    public function test_payment_idempotency_key_is_unique(): void
    {
        $user = User::factory()->create();
        $orderA = $this->order($user, 'order-e');
        $orderB = $this->order($user, 'order-f');

        Payment::create([
            'order_id' => $orderA->id,
            'gateway' => 'wallet',
            'idempotency_key' => 'wallet-attempt-001',
            'amount_rials' => 1000,
            'status' => 'paid',
        ]);

        $this->expectException(QueryException::class);
        Payment::create([
            'order_id' => $orderB->id,
            'gateway' => 'wallet',
            'idempotency_key' => 'wallet-attempt-001',
            'amount_rials' => 1000,
            'status' => 'paid',
        ]);
    }
}
