<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('farast_workflows')) {
            Schema::table('farast_workflows', function (Blueprint $table): void {
                if (! Schema::hasColumn('farast_workflows', 'conditions')) {
                    $table->json('conditions')->nullable();
                }
                if (! Schema::hasColumn('farast_workflows', 'cooldown_seconds')) {
                    $table->unsignedInteger('cooldown_seconds')->default(0);
                }
                if (! Schema::hasColumn('farast_workflows', 'last_triggered_at')) {
                    $table->timestamp('last_triggered_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('farast_workflow_events') && ! Schema::hasColumn('farast_workflow_events', 'matched_at')) {
            Schema::table('farast_workflow_events', function (Blueprint $table): void {
                $table->timestamp('matched_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: these migrations may adopt pre-existing
        // tables/columns and contain live access, automation, or audit data.
        // Schema retirement must be an explicit, separately reviewed operation.
    }
};
