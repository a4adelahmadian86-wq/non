<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $relations = [
            ['farast_subscriptions', 'plan_id', 'farast_commercial_plans'],
            ['farast_commercial_plan_products', 'plan_id', 'farast_commercial_plans'],
            ['farast_commercial_plan_products', 'product_id', 'farast_commercial_products'],
            ['farast_usage_events', 'entitlement_id', 'farast_entitlements'],
            ['farast_usage_events', 'pricing_policy_version_id', 'farast_pricing_policy_versions'],
            ['farast_usage_reservations', 'entitlement_id', 'farast_entitlements'],
            ['farast_usage_reservations', 'pricing_policy_version_id', 'farast_pricing_policy_versions'],
            ['farast_usage_reservations', 'usage_event_id', 'farast_usage_events'],
            ['farast_charges', 'usage_event_id', 'farast_usage_events'],
            ['farast_charges', 'reservation_id', 'farast_usage_reservations'],
            ['farast_charges', 'order_id', 'orders'],
            ['farast_charges', 'invoice_id', 'farast_invoices'],
            ['farast_charges', 'pricing_policy_version_id', 'farast_pricing_policy_versions'],
            ['farast_invoice_items', 'invoice_id', 'farast_invoices'],
            ['farast_invoice_items', 'charge_id', 'farast_charges'],
            ['farast_refunds', 'charge_id', 'farast_charges'],
            ['farast_refunds', 'payment_id', 'payments'],
            ['farast_wallet_reservations', 'wallet_id', 'wallets'],
            ['farast_wallet_reservations', 'charge_id', 'farast_charges'],
            ['farast_price_quotes', 'pricing_policy_version_id', 'farast_pricing_policy_versions'],
            ['farast_output_authorizations', 'pricing_policy_version_id', 'farast_pricing_policy_versions'],
            ['farast_output_authorizations', 'charge_id', 'farast_charges'],
        ];

        // Validate the entire relationship set before applying any DDL.
        foreach ($relations as [$child, $column, $parent]) {
            $this->assertRelationReady($child, $column, $parent);
        }

        foreach ($relations as [$child, $column, $parent]) {
            $this->enforceRelation($child, $column, $parent);
        }
    }

    private function assertRelationReady(string $child, string $column, string $parent): void
    {
        foreach ([$child, $parent] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Cannot enforce {$child}.{$column}: required table {$table} does not exist. No constraints were changed.");
            }
        }

        if (! Schema::hasColumn($child, $column) || ! Schema::hasColumn($parent, 'id')) {
            throw new RuntimeException("Cannot enforce {$child}.{$column} -> {$parent}.id: required column is missing. No constraints were changed.");
        }

        $orphans = DB::table($child.' as child')
            ->leftJoin($parent.' as parent', 'child.'.$column, '=', 'parent.id')
            ->whereNotNull('child.'.$column)
            ->whereNull('parent.id')
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Database integrity preflight failed: {$child}.{$column} has {$orphans} orphan reference(s) to {$parent}.id. No constraints or rows were changed; reconcile before retrying."
            );
        }
    }

    private function enforceRelation(string $child, string $column, string $parent): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->enforceSqliteRelation($child, $column, $parent);

            return;
        }

        $foreignKey = collect(Schema::getForeignKeys($child))->first(
            fn (array $key): bool => ($key['columns'] ?? []) === [$column]
                && ($key['foreign_table'] ?? null) === $parent
                && ($key['foreign_columns'] ?? ['id']) === ['id']
        );

        if ($foreignKey && strtolower((string) ($foreignKey['on_delete'] ?? '')) === 'restrict') {
            return;
        }

        Schema::table($child, function (Blueprint $table) use ($child, $column, $parent, $foreignKey): void {
            if ($foreignKey) {
                $table->dropForeign($foreignKey['name']);
            }

            $table->foreign($column, $child.'_'.$column.'_foreign')
                ->references('id')
                ->on($parent)
                ->restrictOnDelete();
        });
    }

    private function enforceSqliteRelation(string $child, string $column, string $parent): void
    {
        $insertTrigger = $child.'_'.$column.'_fk_insert';
        $updateTrigger = $child.'_'.$column.'_fk_update';
        $deleteTrigger = $parent.'_'.substr(hash('sha256', $child.'.'.$column), 0, 12).'_restrict_delete';

        DB::unprepared(
            'CREATE TRIGGER IF NOT EXISTS "'.$insertTrigger.'" '
            .'BEFORE INSERT ON "'.$child.'" FOR EACH ROW '
            .'WHEN NEW."'.$column.'" IS NOT NULL AND NOT EXISTS ('
            .'SELECT 1 FROM "'.$parent.'" WHERE "id" = NEW."'.$column.'"'
            .') BEGIN SELECT RAISE(ABORT, \'foreign_key_violation: '.$child.'.'.$column.'\'); END'
        );

        DB::unprepared(
            'CREATE TRIGGER IF NOT EXISTS "'.$updateTrigger.'" '
            .'BEFORE UPDATE OF "'.$column.'" ON "'.$child.'" FOR EACH ROW '
            .'WHEN NEW."'.$column.'" IS NOT NULL AND NOT EXISTS ('
            .'SELECT 1 FROM "'.$parent.'" WHERE "id" = NEW."'.$column.'"'
            .') BEGIN SELECT RAISE(ABORT, \'foreign_key_violation: '.$child.'.'.$column.'\'); END'
        );

        DB::unprepared(
            'CREATE TRIGGER IF NOT EXISTS "'.$deleteTrigger.'" '
            .'BEFORE DELETE ON "'.$parent.'" FOR EACH ROW '
            .'WHEN EXISTS (SELECT 1 FROM "'.$child.'" WHERE "'.$column.'" = OLD."id") '
            .'BEGIN SELECT RAISE(ABORT, \'foreign_key_restrict: '.$child.'.'.$column.'\'); END'
        );
    }

    public function down(): void
    {
        // Intentionally non-destructive: rollback must not weaken ledger references.
    }
};
