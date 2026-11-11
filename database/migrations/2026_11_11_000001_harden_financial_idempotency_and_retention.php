<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureIdempotencyColumn('orders', 'legacy-order-');
        $this->ensureIdempotencyColumn('payments', 'legacy-payment-');

        // Financial history must survive account, wallet, and order deletion.
        $this->restrictDelete('wallets', 'user_id', 'users');
        $this->restrictDelete('wallet_transactions', 'wallet_id', 'wallets');
        $this->restrictDelete('orders', 'user_id', 'users');
        $this->restrictDelete('payments', 'order_id', 'orders');
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
