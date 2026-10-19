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
            if (! Schema::hasColumn('tickets', 'sla_first_warned_at')) {
                $table->timestamp('sla_first_warned_at')->nullable()->after('sla_resolve_due_at');
            }
            if (! Schema::hasColumn('tickets', 'sla_first_breached_at')) {
                $table->timestamp('sla_first_breached_at')->nullable()->after('sla_first_warned_at');
            }
            if (! Schema::hasColumn('tickets', 'sla_resolve_warned_at')) {
                $table->timestamp('sla_resolve_warned_at')->nullable()->after('sla_first_breached_at');
            }
            if (! Schema::hasColumn('tickets', 'sla_resolve_breached_at')) {
                $table->timestamp('sla_resolve_breached_at')->nullable()->after('sla_resolve_warned_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            foreach (['sla_first_warned_at', 'sla_first_breached_at', 'sla_resolve_warned_at', 'sla_resolve_breached_at'] as $col) {
                if (Schema::hasColumn('tickets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
