<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->setDeleteRule('orders', 'orders_user_id_foreign', 'user_id', 'users', 'restrict');
        $this->setDeleteRule('payments', 'payments_order_id_foreign', 'order_id', 'orders', 'restrict');
        $this->setDeleteRule('wallets', 'wallets_user_id_foreign', 'user_id', 'users', 'restrict');
        $this->setDeleteRule('wallet_transactions', 'wallet_transactions_wallet_id_foreign', 'wallet_id', 'wallets', 'restrict');
    }

    public function down(): void
    {
        $this->setDeleteRule('wallet_transactions', 'wallet_transactions_wallet_id_foreign', 'wallet_id', 'wallets', 'cascade');
        $this->setDeleteRule('wallets', 'wallets_user_id_foreign', 'user_id', 'users', 'cascade');
        $this->setDeleteRule('payments', 'payments_order_id_foreign', 'order_id', 'orders', 'cascade');
        $this->setDeleteRule('orders', 'orders_user_id_foreign', 'user_id', 'users', 'cascade');
    }

    private function setDeleteRule(string $table, string $foreignKey, string $column, string $parent, string $rule): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $foreignKeys = collect(Schema::getForeignKeys($table));
        $existing = $foreignKeys->firstWhere('name', $foreignKey);
        if ($existing && strtolower((string) ($existing['on_delete'] ?? '')) === $rule) {
            return;
        }

        if ($existing) {
            Schema::table($table, function (Blueprint $blueprint) use ($foreignKey): void {
                $blueprint->dropForeign($foreignKey);
            });
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $parent, $rule): void {
            $foreign = $blueprint->foreign($column)->references('id')->on($parent);
            if ($rule === 'restrict') {
                $foreign->restrictOnDelete();
            } else {
                $foreign->cascadeOnDelete();
            }
        });
    }
};
