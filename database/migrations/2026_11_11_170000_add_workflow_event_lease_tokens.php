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
    }

    public function down(): void
    {
        // Keep lease metadata on rollback; it protects live workflow executions.
    }
};
