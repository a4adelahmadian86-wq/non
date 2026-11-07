<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('farast_workflow_events')) {
            Schema::table('farast_workflow_events', function (Blueprint $table): void {
                if (! Schema::hasColumn('farast_workflow_events', 'status')) {
                    $table->string('status', 24)->default('pending');
                }
                if (! Schema::hasColumn('farast_workflow_events', 'attempts')) {
                    $table->unsignedInteger('attempts')->default(0);
                }
                if (! Schema::hasColumn('farast_workflow_events', 'failed_workflows')) {
                    $table->unsignedInteger('failed_workflows')->default(0);
                }
                if (! Schema::hasColumn('farast_workflow_events', 'error_summary')) {
                    $table->text('error_summary')->nullable();
                }
            });

            DB::table('farast_workflow_events')
                ->whereNotNull('processed_at')
                ->update(['status' => 'completed']);
        }

        if (! Schema::hasTable('farast_workflow_event_workflows')) {
            Schema::create('farast_workflow_event_workflows', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('event_id')->constrained('farast_workflow_events')->cascadeOnDelete();
                $table->foreignId('workflow_id')->constrained('farast_workflows')->cascadeOnDelete();
                $table->string('status', 20)->default('pending');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->unique(['event_id', 'workflow_id']);
                $table->index(['status', 'updated_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('farast_workflow_event_workflows');

        if (Schema::hasTable('farast_workflow_events')) {
            Schema::table('farast_workflow_events', function (Blueprint $table): void {
                foreach (['status', 'attempts', 'failed_workflows', 'error_summary'] as $column) {
                    if (Schema::hasColumn('farast_workflow_events', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
