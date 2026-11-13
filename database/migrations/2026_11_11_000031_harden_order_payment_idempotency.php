<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateAuthority = DB::table('payments')
            ->select('gateway', 'authority')
            ->whereNotNull('authority')
            ->groupBy('gateway', 'authority')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->get();

        if ($duplicateAuthority->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot enforce payment authority uniqueness: duplicate gateway/authority pairs exist. '
                .'Reconcile these payment records before retrying: '
                .$duplicateAuthority->map(fn ($row) => $row->gateway.':'.$row->authority)->implode(', ')
            );
        }

        $duplicateTransactions = DB::table('payments')
            ->select('gateway', 'transaction_id')
            ->whereNotNull('transaction_id')
            ->groupBy('gateway', 'transaction_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->get();

        if ($duplicateTransactions->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot enforce payment transaction uniqueness: duplicate gateway/transaction pairs exist. '
                .'Reconcile these payment records before retrying: '
                .$duplicateTransactions->map(fn ($row) => $row->gateway.':'.$row->transaction_id)->implode(', ')
            );
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'idempotency_key')) {
                $table->string('idempotency_key', 120)->nullable();
            }
        });

        Schema::table('payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('payments', 'idempotency_key')) {
                $table->string('idempotency_key', 120)->nullable();
            }
        });

        $duplicateOrderKeys = DB::table('orders')
            ->select('user_id', 'idempotency_key')
            ->whereNotNull('idempotency_key')
            ->groupBy('user_id', 'idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->get();

        if ($duplicateOrderKeys->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot enforce per-customer order idempotency: duplicate user/key pairs exist. '
                .'Reconcile these orders before retrying: '
                .$duplicateOrderKeys->map(fn ($row) => $row->user_id.':'.$row->idempotency_key)->implode(', ')
            );
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasIndex('orders', 'orders_user_idempotency_unique')) {
                $table->unique(['user_id', 'idempotency_key'], 'orders_user_idempotency_unique');
            }
        });

        Schema::table('payments', function (Blueprint $table): void {
            if (! Schema::hasIndex('payments', 'payments_idempotency_key_unique')) {
                $table->unique('idempotency_key', 'payments_idempotency_key_unique');
            }
            if (! Schema::hasIndex('payments', 'payments_gateway_authority_unique')) {
                $table->unique(['gateway', 'authority'], 'payments_gateway_authority_unique');
            }
            if (! Schema::hasIndex('payments', 'payments_gateway_transaction_unique')) {
                $table->unique(['gateway', 'transaction_id'], 'payments_gateway_transaction_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            if (Schema::hasIndex('payments', 'payments_gateway_transaction_unique')) {
                $table->dropUnique('payments_gateway_transaction_unique');
            }
            if (Schema::hasIndex('payments', 'payments_gateway_authority_unique')) {
                $table->dropUnique('payments_gateway_authority_unique');
            }
            if (Schema::hasIndex('payments', 'payments_idempotency_key_unique')) {
                $table->dropUnique('payments_idempotency_key_unique');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasIndex('orders', 'orders_user_idempotency_unique')) {
                $table->dropUnique('orders_user_idempotency_unique');
            }
        });

        Schema::table('payments', function (Blueprint $table): void {
            if (Schema::hasColumn('payments', 'idempotency_key')) {
                $table->dropColumn('idempotency_key');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'idempotency_key')) {
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
