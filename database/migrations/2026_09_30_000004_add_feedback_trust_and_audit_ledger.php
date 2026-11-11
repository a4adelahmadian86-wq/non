<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('farast_feedback_evidence')) {
            Schema::table('farast_feedback_evidence', function (Blueprint $table) {
                if (! Schema::hasColumn('farast_feedback_evidence', 'user_id')) {
                    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'project_id')) {
                    $table->foreignId('project_id')->nullable()->constrained('farast_projects')->nullOnDelete();
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'feedback_id')) {
                    $table->foreignId('feedback_id')->nullable()->constrained('ai_feedback')->nullOnDelete();
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'tool_id')) {
                    $table->foreignId('tool_id')->nullable()->constrained('farast_tools')->nullOnDelete();
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'tool_version_id')) {
                    $table->foreignId('tool_version_id')->nullable()->constrained('farast_tool_versions')->nullOnDelete();
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'source')) {
                    $table->string('source', 40)->default('user');
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'evidence_type')) {
                    $table->string('evidence_type', 80)->default('general');
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'input_meta')) {
                    $table->json('input_meta')->nullable();
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'output_meta')) {
                    $table->json('output_meta')->nullable();
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'confidence')) {
                    $table->decimal('confidence', 6, 4)->nullable();
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'frequency')) {
                    $table->unsignedInteger('frequency')->default(1);
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'independent_confirmations')) {
                    $table->unsignedInteger('independent_confirmations')->default(0);
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'status')) {
                    $table->string('status', 30)->default('pending');
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'reviewer_notes')) {
                    $table->text('reviewer_notes')->nullable();
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'checksum')) {
                    $table->string('checksum', 64)->nullable();
                }
            });
        }

        if (! Schema::hasTable('farast_audit_events')) {
            Schema::create('farast_audit_events', function (Blueprint $table) {
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
        if (Schema::hasTable('farast_audit_events') && DB::table('farast_audit_events')->exists()) {
            throw new RuntimeException('Cannot roll back the audit ledger migration while audit events exist.');
        }

        Schema::dropIfExists('farast_audit_events');

        // Feedback evidence is durable review data. Its additive columns are
        // intentionally retained on rollback rather than risking evidence loss.
    }
};
