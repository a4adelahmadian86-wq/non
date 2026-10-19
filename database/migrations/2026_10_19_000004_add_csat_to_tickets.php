<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'csat_score')) {
                $table->unsignedTinyInteger('csat_score')->nullable()->after('resolved_at');
            }
            if (! Schema::hasColumn('tickets', 'csat_comment')) {
                $table->string('csat_comment', 500)->nullable()->after('csat_score');
            }
            if (! Schema::hasColumn('tickets', 'csat_at')) {
                $table->timestamp('csat_at')->nullable()->after('csat_comment');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            foreach (['csat_score', 'csat_comment', 'csat_at'] as $col) {
                if (Schema::hasColumn('tickets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
