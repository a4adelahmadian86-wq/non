<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Financial history must survive account and parent-record deletion.
        // These relationships are intentionally restrictive; account erasure
        // must use a reviewed anonymization workflow rather than cascading away
        // orders, payments, wallet balances or append-only ledger entries.
        $this->replaceForeignKey('orders', 'user_id', 'users', 'orders_user_id_foreign', 'restrict');
        $this->replaceForeignKey('payments', 'order_id', 'orders', 'payments_order_id_foreign', 'restrict');
        $this->replaceForeignKey('wallets', 'user_id', 'users', 'wallets_user_id_foreign', 'restrict');
        $this->replaceForeignKey('wallet_transactions', 'wallet_id', 'wallets', 'wallet_transactions_wallet_id_foreign', 'restrict');
    }

    public function down(): void
    {
        $this->replaceForeignKey('wallet_transactions', 'wallet_id', 'wallets', 'wallet_transactions_wallet_id_foreign', 'cascade');
        $this->replaceForeignKey('wallets', 'user_id', 'users', 'wallets_user_id_foreign', 'cascade');
        $this->replaceForeignKey('payments', 'order_id', 'orders', 'payments_order_id_foreign', 'cascade');
        $this->replaceForeignKey('orders', 'user_id', 'users', 'orders_user_id_foreign', 'cascade');
    }

    private function replaceForeignKey(
        string $tableName,
        string $column,
        string $referencedTable,
        string $constraintName,
        string $deleteRule
    ): void {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $column) || ! Schema::hasTable($referencedTable)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column): void {
            $table->dropForeign([$column]);
        });

        Schema::table($tableName, function (Blueprint $table) use ($column, $referencedTable, $constraintName, $deleteRule): void {
            $foreign = $table->foreign($column, $constraintName)->references('id')->on($referencedTable);
            if ($deleteRule === 'restrict') {
                $foreign->restrictOnDelete();
            } else {
                $foreign->cascadeOnDelete();
            }
        });
    }
};
