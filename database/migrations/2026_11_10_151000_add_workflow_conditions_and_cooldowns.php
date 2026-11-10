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
        // Never silently discard configured automation rules or event snapshots.
        if (Schema::hasTable('farast_workflow_events')
            && Schema::hasColumn('farast_workflow_events', 'matched_at')
            && DB::table('farast_workflow_events')->whereNotNull('matched_at')->exists()) {
            throw new RuntimeException('Refusing to remove matched_at while workflow event snapshots exist.');
        }

        if (Schema::hasTable('farast_workflows')) {
            foreach (['conditions', 'cooldown_seconds', 'last_triggered_at'] as $column) {
                if (Schema::hasColumn('farast_workflows', $column)
                    && DB::table('farast_workflows')->whereNotNull($column)->exists()) {
                    throw new RuntimeException("Refusing to remove populated workflow column {$column}.");
                }
            }
        }

        if (Schema::hasTable('farast_workflow_events') && Schema::hasColumn('farast_workflow_events', 'matched_at')) {
            Schema::table('farast_workflow_events', function (Blueprint $table): void {
                $table->dropIndex(['matched_at']);
                $table->dropColumn('matched_at');
            });
        }

        if (Schema::hasTable('farast_workflows')) {
            Schema::table('farast_workflows', function (Blueprint $table): void {
                foreach (['conditions', 'cooldown_seconds', 'last_triggered_at'] as $column) {
                    if (Schema::hasColumn('farast_workflows', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
