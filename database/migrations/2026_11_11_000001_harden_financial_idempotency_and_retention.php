<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->preflightIdempotencyKeys('orders');
        $this->preflightIdempotencyKeys('payments');
        $this->preflightGatewayAuthorities();
        $this->preflightGatewayTransactionIds();
        $this->preflightCoreReferences();

        $this->ensureIdempotencyColumn('orders', 'legacy-order-');
        $this->ensureIdempotencyColumn('payments', 'legacy-payment-');
        $this->ensureGatewayAuthorityIndex();
        $this->ensureGatewayTransactionIndex();

        // Financial history must survive account, wallet, and order deletion.
        $this->restrictDelete('wallets', 'user_id', 'users');
        $this->restrictDelete('wallet_transactions', 'wallet_id', 'wallets');
        $this->restrictDelete('orders', 'user_id', 'users');
        $this->restrictDelete('payments', 'order_id', 'orders');
    }

    private function preflightIdempotencyKeys(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'idempotency_key')) {
            return;
        }

        $duplicates = DB::table($tableName)
            ->select('idempotency_key')
            ->whereNotNull('idempotency_key')
            ->where('idempotency_key', '<>', '')
            ->groupBy('idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();

        if ($duplicates) {
            throw new RuntimeException("Duplicate idempotency_key values already exist in {$tableName}; no schema or data was changed.");
        }
    }

    private function preflightGatewayAuthorities(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        if (! Schema::hasColumn('payments', 'gateway') || ! Schema::hasColumn('payments', 'authority')) {
            throw new RuntimeException('Cannot enforce payment authority uniqueness: payments.gateway or payments.authority is missing.');
        }

        $missingGateway = DB::table('payments')
            ->whereNotNull('authority')
            ->whereNull('gateway')
            ->exists();

        if ($missingGateway) {
            throw new RuntimeException('A payment authority exists without a gateway; assign the correct gateway before enforcing uniqueness.');
        }

        $duplicate = DB::table('payments')
            ->select('gateway', 'authority')
            ->whereNotNull('authority')
            ->groupBy('gateway', 'authority')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new RuntimeException('Duplicate payment authority exists for gateway '.$duplicate->gateway.'; no schema or data was changed.');
        }
    }

    private function preflightGatewayTransactionIds(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        if (! Schema::hasColumn('payments', 'gateway') || ! Schema::hasColumn('payments', 'transaction_id')) {
            throw new RuntimeException('Cannot enforce payment transaction uniqueness: payments.gateway or payments.transaction_id is missing.');
        }

        $missingGateway = DB::table('payments')
            ->whereNotNull('transaction_id')
            ->whereNull('gateway')
            ->exists();

        if ($missingGateway) {
            throw new RuntimeException('A payment transaction ID exists without a gateway; assign the correct gateway before enforcing uniqueness.');
        }

        $duplicate = DB::table('payments')
            ->select('gateway', 'transaction_id')
            ->whereNotNull('transaction_id')
            ->groupBy('gateway', 'transaction_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new RuntimeException('Duplicate payment transaction ID exists for gateway '.$duplicate->gateway.'; no schema or data was changed.');
        }
    }

    private function preflightCoreReferences(): void
    {
        foreach ([
            ['wallets', 'user_id', 'users'],
            ['wallet_transactions', 'wallet_id', 'wallets'],
            ['orders', 'user_id', 'users'],
            ['payments', 'order_id', 'orders'],
        ] as [$child, $column, $parent]) {
            if (! Schema::hasTable($child) || ! Schema::hasTable($parent) || ! Schema::hasColumn($child, $column)) {
                throw new RuntimeException("Cannot enforce financial retention: required relation {$child}.{$column} -> {$parent}.id is missing.");
            }

            $orphans = DB::table($child.' as child')
                ->leftJoin($parent.' as parent', 'child.'.$column, '=', 'parent.id')
                ->whereNotNull('child.'.$column)
                ->whereNull('parent.id')
                ->count();

            if ($orphans > 0) {
                throw new RuntimeException("Financial retention preflight failed: {$child}.{$column} has {$orphans} orphan reference(s); no schema or data was changed.");
            }
        }
    }

    private function ensureGatewayAuthorityIndex(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        $hasUniqueIndex = collect(Schema::getIndexes('payments'))->contains(
            fn (array $index): bool => ($index['unique'] ?? false)
                && ($index['columns'] ?? []) === ['gateway', 'authority']
        );

        if (! $hasUniqueIndex) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->unique(['gateway', 'authority'], 'payments_gateway_authority_unique');
            });
        }
    }

    private function ensureGatewayTransactionIndex(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        $hasUniqueIndex = collect(Schema::getIndexes('payments'))->contains(
            fn (array $index): bool => ($index['unique'] ?? false)
                && ($index['columns'] ?? []) === ['gateway', 'transaction_id']
        );

        if (! $hasUniqueIndex) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->unique(['gateway', 'transaction_id'], 'payments_gateway_transaction_id_unique');
            });
        }
    }

    private function ensureIdempotencyColumn(string $tableName, string $legacyPrefix): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (! Schema::hasColumn($tableName, 'idempotency_key')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('idempotency_key', 190)->nullable();
            });
        }

        DB::table($tableName)
            ->where(function ($query): void {
                $query->whereNull('idempotency_key')->orWhere('idempotency_key', '');
            })
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($tableName, $legacyPrefix): void {
                foreach ($rows as $row) {
                    DB::table($tableName)->where('id', $row->id)->where(function ($query): void {
                        $query->whereNull('idempotency_key')->orWhere('idempotency_key', '');
                    })->update(['idempotency_key' => $legacyPrefix.$row->id]);
                }
            });

        $duplicates = DB::table($tableName)
            ->select('idempotency_key')
            ->whereNotNull('idempotency_key')
            ->groupBy('idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();

        if ($duplicates) {
            throw new RuntimeException("Duplicate idempotency_key values exist in {$tableName}; resolve them before applying financial integrity constraints.");
        }

        $hasUniqueKey = collect(Schema::getIndexes($tableName))->contains(
            fn (array $index): bool => ($index['unique'] ?? false)
                && ($index['columns'] ?? []) === ['idempotency_key']
        );

        if (! $hasUniqueKey) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->unique('idempotency_key', $tableName.'_idempotency_key_unique');
            });
        }
    }

    private function restrictDelete(string $tableName, string $column, string $parentTable): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasTable($parentTable) || ! Schema::hasColumn($tableName, $column)) {
            return;
        }

        // SQLite cannot drop an existing foreign key by name. Preserve its
        // existing schema and enforce the same retention boundary with a
        // BEFORE DELETE trigger on the referenced parent table.
        if (DB::getDriverName() === 'sqlite') {
            $trigger = $tableName.'_'.$column.'_retain_delete';
            DB::unprepared(
                'CREATE TRIGGER IF NOT EXISTS "'.$trigger.'" '
                .'BEFORE DELETE ON "'.$parentTable.'" '
                .'FOR EACH ROW WHEN EXISTS ('
                .'SELECT 1 FROM "'.$tableName.'" WHERE "'.$column.'" = OLD."id"'
                .') BEGIN '
                ."SELECT RAISE(ABORT, 'financial_history_restricts_delete'); "
                .'END'
            );

            return;
        }

        $foreignKey = collect(Schema::getForeignKeys($tableName))->first(
            fn (array $key): bool => ($key['columns'] ?? []) === [$column]
                && ($key['foreign_table'] ?? null) === $parentTable
        );

        if ($foreignKey && strtolower((string) ($foreignKey['on_delete'] ?? '')) === 'restrict') {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $parentTable, $foreignKey): void {
            if ($foreignKey) {
                $table->dropForeign($foreignKey['name']);
            }

            $table->foreign($column)
                ->references('id')
                ->on($parentTable)
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: rolling back must not restore cascading
        // deletion of wallets, orders, payments, or their financial history.
        // Idempotency keys are retained because existing records may depend on them.
    }
};
