<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tickets') || ! Schema::hasTable('orders') || Schema::hasColumn('tickets', 'related_order_id')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('related_order_id')
                ->nullable()
                ->constrained('orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tickets') || ! Schema::hasColumn('tickets', 'related_order_id')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['related_order_id']);
            $table->dropColumn('related_order_id');
        });
    }
};
