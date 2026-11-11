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
            $table->string('gateway', 40)->nullable();
            $table->string('authority', 160)->nullable();
            $table->string('transaction_id', 160)->nullable();
            $table->string('transaction_id', 190)->nullable();
        });

        DB::table('users')->insert(['id' => 1]);
        DB::table('wallets')->insert(['id' => 10, 'user_id' => 1]);
        DB::table('wallet_transactions')->insert(['id' => 100, 'wallet_id' => 10]);
        DB::table('orders')->insert(['id' => 20, 'user_id' => 1]);
        DB::table('payments')->insert(['id' => 30, 'order_id' => 20, 'gateway' => 'zarinpal', 'authority' => 'legacy-authority-30', 'transaction_id' => 'legacy-transaction-30']);
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
        $this->assertTrue(collect(Schema::getIndexes('payments'))->contains(
            fn (array $index): bool => ($index['unique'] ?? false) && ($index['columns'] ?? []) === ['gateway', 'authority']
        ));
        $this->assertTrue(collect(Schema::getIndexes('payments'))->contains(
            fn (array $index): bool => ($index['unique'] ?? false) && ($index['columns'] ?? []) === ['gateway', 'transaction_id']
        ));
        $this->assertTrue(collect(Schema::getIndexes('payments'))->contains(
            fn (array $index): bool => ($index['unique'] ?? false) && ($index['columns'] ?? []) === ['gateway', 'transaction_id']
        ));

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

    public function test_commerce_reference_migration_preflights_all_relations_before_adding_constraints(): void
    {
        Schema::create('farast_commercial_plans', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('farast_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('plan_id');
        });
        Schema::create('farast_commercial_products', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('farast_commercial_plan_products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('product_id');
        });

        DB::table('farast_commercial_plans')->insert(['id' => 1]);
        DB::table('farast_commercial_products')->insert(['id' => 1]);
        DB::table('farast_subscriptions')->insert(['id' => 1, 'plan_id' => 1]);
        DB::table('farast_commercial_plan_products')->insert([
            'id' => 1,
            'plan_id' => 1,
            'product_id' => 999999,
        ]);

        $migration = require base_path('database/migrations/2026_11_11_000002_enforce_farast_commerce_ledger_references.php');

        try {
            $migration->up();
            $this->fail('The migration must stop when any legacy orphan reference exists.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('farast_commercial_plan_products.product_id has 1 orphan reference(s)', $exception->getMessage());
        }

        $this->assertDatabaseHas('farast_subscriptions', ['id' => 1, 'plan_id' => 1]);
        $this->assertDatabaseHas('farast_commercial_plan_products', ['id' => 1, 'product_id' => 999999]);
        $this->assertFalse(DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('name', 'farast_subscriptions_plan_id_fk_insert')
            ->exists());
    }

    public function test_upgrade_stops_before_mutation_when_gateway_authorities_are_duplicated(): void
    {
        DB::table('payments')->where('id', 30)->update(['authority' => 'duplicate-authority']);
        DB::table('payments')->insert([
            'id' => 31,
            'order_id' => 20,
            'gateway' => 'zarinpal',
            'authority' => 'duplicate-authority',
        ]);

        try {
            $this->runFinancialMigration();
            $this->fail('Duplicate gateway authorities must stop the migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Duplicate payment authority', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('orders', 'idempotency_key'));
        $this->assertDatabaseHas('payments', ['id' => 30, 'authority' => 'duplicate-authority']);
        $this->assertDatabaseHas('payments', ['id' => 31, 'authority' => 'duplicate-authority']);
    }

    public function test_upgrade_rejects_duplicate_empty_gateway_authorities_before_mutation(): void
    {
        DB::table('payments')->where('id', 30)->update(['authority' => '']);
        DB::table('payments')->insert([
            'id' => 31,
            'order_id' => 20,
            'gateway' => 'zarinpal',
            'authority' => '',
        ]);

        try {
            $this->runFinancialMigration();
            $this->fail('Duplicate empty gateway authorities must be normalized before adding the unique index.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Duplicate payment authority', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('orders', 'idempotency_key'));
        $this->assertDatabaseHas('payments', ['id' => 30, 'authority' => '']);
        $this->assertDatabaseHas('payments', ['id' => 31, 'authority' => '']);
    }

    public function test_upgrade_stops_before_mutation_when_gateway_transaction_ids_are_duplicated(): void
    {
        DB::table('payments')->where('id', 30)->update(['transaction_id' => 'duplicate-ref']);
        DB::table('payments')->insert([
            'id' => 31,
            'order_id' => 20,
            'gateway' => 'zarinpal',
            'authority' => 'different-authority',
            'transaction_id' => 'duplicate-ref',
        ]);

        try {
            $this->runFinancialMigration();
            $this->fail('Duplicate gateway transaction IDs must stop the migration before schema mutation.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Duplicate payment transaction ID', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('orders', 'idempotency_key'));
        $this->assertFalse(collect(Schema::getIndexes('payments'))->contains(
            fn (array $index): bool => ($index['unique'] ?? false) && ($index['columns'] ?? []) === ['gateway', 'transaction_id']
        ));
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

        try {
            $this->runFinancialMigration();
            $this->fail('Duplicate legacy idempotency keys must stop the migration before schema mutation.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Duplicate idempotency_key values exist in orders', $exception->getMessage());
        }

        $this->assertFalse(collect(Schema::getIndexes('orders'))->contains(
            fn (array $index): bool => ($index['unique'] ?? false) && ($index['columns'] ?? []) === ['idempotency_key']
        ));
        $this->assertFalse(collect(Schema::getIndexes('payments'))->contains(
            fn (array $index): bool => ($index['unique'] ?? false) && ($index['columns'] ?? []) === ['gateway', 'authority']
        ));
    }
}
