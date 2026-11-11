<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('farast_feedback_evidence')) {
            throw new RuntimeException('farast_feedback_evidence must exist before its schema can be reconciled.');
        }

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

        $indexes = Schema::getIndexes('farast_feedback_evidence');
        $hasIndex = static function (array $columns) use ($indexes): bool {
            foreach ($indexes as $index) {
                if (($index['columns'] ?? []) === $columns) {
                    return true;
                }
            }

            return false;
        };

        Schema::table('farast_feedback_evidence', function (Blueprint $table) use ($hasIndex): void {
            if (! $hasIndex(['project_id', 'status'])) {
                $table->index(['project_id', 'status'], 'farast_feedback_evidence_project_status_index');
            }
            if (! $hasIndex(['tool_id', 'tool_version_id', 'status'])) {
                $table->index(['tool_id', 'tool_version_id', 'status'], 'farast_feedback_evidence_tool_version_status_index');
            }
            if (! $hasIndex(['feedback_id', 'status'])) {
                $table->index(['feedback_id', 'status'], 'farast_feedback_evidence_feedback_status_index');
            }
        });
    }

    public function down(): void
    {
        // Keep additive evidence columns and their indexes: they may contain
        // durable review data created after this migration was deployed.
    }
};
