<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('farast_workflow_event_workflows')
            && ! Schema::hasColumn('farast_workflow_event_workflows', 'lease_token')) {
            Schema::table('farast_workflow_event_workflows', function (Blueprint $table): void {
                $table->string('lease_token', 64)->nullable()->index();
            });
        }

        if (Schema::hasTable('farast_workflow_event_workflows')
            && ! Schema::hasColumn('farast_workflow_event_workflows', 'run_id')) {
            Schema::table('farast_workflow_event_workflows', function (Blueprint $table): void {
                $table->unsignedBigInteger('run_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('farast_workflow_events')) {
            Schema::table('farast_workflow_events', function (Blueprint $table): void {
                if (! Schema::hasColumn('farast_workflow_events', 'started_at')) {
                    $table->timestamp('started_at')->nullable();
                }
                if (! Schema::hasColumn('farast_workflow_events', 'failed_at')) {
                    $table->timestamp('failed_at')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        // Preserve event and execution history. Retirement is a separate reviewed operation.
    }
};
