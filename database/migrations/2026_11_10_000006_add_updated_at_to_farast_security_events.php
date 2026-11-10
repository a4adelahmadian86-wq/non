<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('farast_security_events') || Schema::hasColumn('farast_security_events', 'updated_at')) {
            return;
        }

        Schema::table('farast_security_events', function (Blueprint $table): void {
            $table->timestamp('updated_at')->nullable();
        });

        if (Schema::hasColumn('farast_security_events', 'created_at')) {
            DB::table('farast_security_events')
                ->whereNull('updated_at')
                ->update(['updated_at' => DB::raw('created_at')]);
        }
    }

    public function down(): void
    {
        // Keep the compatibility column: this migration is intentionally safe for
        // databases where the column may already have existed before it was applied.
    }
};
