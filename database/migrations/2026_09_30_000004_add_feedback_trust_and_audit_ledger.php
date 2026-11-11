<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('farast_feedback_evidence')) {
            $columns = Schema::getColumnListing('farast_feedback_evidence');

            Schema::table('farast_feedback_evidence', function (Blueprint $table) use ($columns): void {
                if (! in_array('user_id', $columns, true)) {
                    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                }

                if (! in_array('project_id', $columns, true)) {
                    $table->foreignId('project_id')->nullable()->constrained('farast_projects')->nullOnDelete();
                }

                if (! in_array('source', $columns, true)) {
                    $table->string('source', 40)->default('user');
                }
            });
        }

        if (! Schema::hasTable('farast_audit_events')) {
            Schema::create('farast_audit_events', function (Blueprint $table): void {
                $table->id();
                $table->uuid('event_id')->unique();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('project_id')->nullable()->constrained('farast_projects')->nullOnDelete();
                $table->string('event_type', 100);
                $table->string('aggregate_type', 80)->nullable();
                $table->unsignedBigInteger('aggregate_id')->nullable();
                $table->string('source', 60)->default('application');
                $table->json('payload_meta')->nullable();
                $table->string('payload_checksum', 64);
                $table->string('previous_checksum', 64)->nullable();
                $table->timestamps();
                $table->index(['event_type', 'created_at']);
                $table->index(['aggregate_type', 'aggregate_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('farast_audit_events');

        // farast_feedback_evidence predates this migration on some installations.
        // Its schema is reconciled by a forward-only migration; do not drop data
        // from a table whose original ownership predates this migration.
    }
};
