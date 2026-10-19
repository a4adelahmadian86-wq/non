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
            if (! Schema::hasColumn('tickets', 'sla_first_due_at')) {
                $table->timestamp('sla_first_due_at')->nullable()->after('closed_at');
            }
            if (! Schema::hasColumn('tickets', 'sla_resolve_due_at')) {
                $table->timestamp('sla_resolve_due_at')->nullable()->after('sla_first_due_at');
            }
            if (! Schema::hasColumn('tickets', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('sla_resolve_due_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            foreach (['sla_first_due_at', 'sla_resolve_due_at', 'resolved_at'] as $col) {
                if (Schema::hasColumn('tickets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
