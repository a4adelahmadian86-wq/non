<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinancialIdempotencyMigrationUpgradeTest extends TestCase
{
    private string $previousDefault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDefault = (string) config('database.default');
        config([
            'database.connections.integrity_upgrade' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('integrity_upgrade');
        DB::setDefaultConnection('integrity_upgrade');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
        });

        DB::table('users')->insert(['id' => 1]);
        DB::table('wallets')->insert(['id' => 10, 'user_id' => 1]);
        DB::table('wallet_transactions')->insert(['id' => 100, 'wallet_id' => 10]);
        DB::table('orders')->insert(['id' => 20, 'user_id' => 1]);
        DB::table('payments')->insert(['id' => 30, 'order_id' => 20]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->previousDefault);
        DB::purge('integrity_upgrade');

        parent::tearDown();
    }

    private function runFinancialMigration(): void
    {
        $migration = require base_path('database/migrations/2026_11_11_000001_harden_financial_idempotency_and_retention.php');
        $migration->up();
    }

    public function test_upgrade_backfills_existing_records_and_is_repeatable(): void
    {
        $this->runFinancialMigration();
        $this->runFinancialMigration();

        $this->assertSame('legacy-order-20', DB::table('orders')->where('id', 20)->value('idempotency_key'));
        $this->assertSame('legacy-payment-30', DB::table('payments')->where('id', 30)->value('idempotency_key'));

        $this->expectException(QueryException::class);
        DB::table('orders')->insert([
            'id' => 21,
            'user_id' => 1,
            'idempotency_key' => 'legacy-order-20',
        ]);
    }

    public function test_upgrade_preserves_existing_financial_rows_from_cascade_deletion(): void
    {
        $this->runFinancialMigration();

        foreach ([['users', 1], ['wallets', 10], ['orders', 20]] as [$table, $id]) {
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail("Deleting {$table} row {$id} must be blocked while financial history exists.");
            } catch (QueryException) {
                $this->assertDatabaseHas($table, ['id' => $id]);
            }
        }

        $this->assertDatabaseHas('wallet_transactions', ['id' => 100, 'wallet_id' => 10]);
        $this->assertDatabaseHas('payments', ['id' => 30, 'order_id' => 20]);
    }

    public function test_commerce_reference_migration_aborts_and_preserves_orphaned_rows(): void
    {
        Schema::create('farast_commercial_plans', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('farast_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('plan_id');
        });

        DB::table('farast_subscriptions')->insert([
            'id' => 1,
            'plan_id' => 999999,
        ]);

        $migration = require base_path('database/migrations/2026_11_11_000002_enforce_farast_commerce_ledger_references.php');

        try {
            $migration->up();
            $this->fail('The migration must stop when legacy orphan references exist.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('farast_subscriptions.plan_id has 1 orphan reference(s)', $exception->getMessage());
        }

        $this->assertDatabaseHas('farast_subscriptions', [
            'id' => 1,
            'plan_id' => 999999,
        ]);
    }

    public function test_upgrade_stops_without_merging_duplicate_legacy_keys(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('idempotency_key', 190)->nullable();
        });

        DB::table('orders')->where('id', 20)->update(['idempotency_key' => 'duplicate-legacy-key']);
        DB::table('orders')->insert([
            'id' => 21,
            'user_id' => 1,
            'idempotency_key' => 'duplicate-legacy-key',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->runFinancialMigration();
    }
}
