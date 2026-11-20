<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommerceLedgerForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_cannot_reference_a_missing_commercial_plan(): void
    {
        $this->expectException(QueryException::class);

        DB::table('farast_subscriptions')->insert([
            'subscription_id' => (string) Str::uuid(),
            'plan_id' => 999999,
            'starts_at' => now(),
        ]);
    }

    public function test_invoice_items_cannot_reference_a_missing_invoice(): void
    {
        $this->expectException(QueryException::class);

        DB::table('farast_invoice_items')->insert([
            'invoice_id' => 999999,
            'description' => 'Invalid invoice reference',
            'quantity' => 1,
            'unit' => 'item',
        ]);
    }

    public function test_wallet_reservations_cannot_reference_a_missing_wallet(): void
    {
        $this->expectException(QueryException::class);

        DB::table('farast_wallet_reservations')->insert([
            'reservation_id' => (string) Str::uuid(),
            'idempotency_key' => 'missing-wallet-'.Str::uuid(),
            'wallet_id' => 999999,
            'amount' => 1000,
        ]);
    }

    public function test_commercial_plan_cannot_be_deleted_while_subscriptions_reference_it(): void
    {
        $planId = DB::table('farast_commercial_plans')->insertGetId([
            'code' => 'retained-plan-'.Str::uuid(),
            'name' => 'Retained plan',
            'price' => 1000,
            'currency' => 'IRR',
        ]);

        DB::table('farast_subscriptions')->insert([
            'subscription_id' => (string) Str::uuid(),
            'plan_id' => $planId,
            'starts_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('farast_commercial_plans')->where('id', $planId)->delete();
    }
}
