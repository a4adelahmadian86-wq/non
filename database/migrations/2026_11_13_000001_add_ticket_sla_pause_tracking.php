<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'sla_paused_at')) {
                $table->timestamp('sla_paused_at')->nullable();
            }
            if (! Schema::hasColumn('tickets', 'sla_resolve_remaining_minutes')) {
                $table->unsignedInteger('sla_resolve_remaining_minutes')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            foreach (['sla_paused_at', 'sla_resolve_remaining_minutes'] as $column) {
                if (Schema::hasColumn('tickets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
