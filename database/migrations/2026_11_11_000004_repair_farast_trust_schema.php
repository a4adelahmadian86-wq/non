<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureFeedbackEvidenceColumns();
        $this->ensureReviewCaseColumns();
        $this->ensureApprovedKnowledgeColumns();
        $this->ensureEvaluationDatasetColumns();
        $this->ensureEvaluationCaseColumns();

        $this->ensureIndex('farast_feedback_evidence', ['project_id', 'status'], 'farast_feedback_project_status_idx');
        $this->ensureIndex('farast_feedback_evidence', ['tool_id', 'tool_version_id', 'status'], 'farast_feedback_tool_status_idx');
        $this->ensureIndex('farast_review_cases', ['evidence_id', 'status'], 'farast_review_evidence_status_idx');
        $this->ensureIndex('farast_approved_knowledge', ['capability_code', 'status'], 'farast_approved_cap_status_idx');
        $this->ensureIndex('farast_evaluation_cases', ['dataset_id', 'status'], 'farast_eval_dataset_status_idx');
        $this->ensureIndex('farast_evaluation_cases', ['tool_version_id'], 'farast_eval_version_idx');
    }

    private function ensureFeedbackEvidenceColumns(): void
    {
        if (! Schema::hasTable('farast_feedback_evidence')) {
            throw new RuntimeException('Required table farast_feedback_evidence is missing.');
        }

        Schema::table('farast_feedback_evidence', function (Blueprint $table): void {
            if (! Schema::hasColumn('farast_feedback_evidence', 'feedback_id')) {
                $table->foreignId('feedback_id')->nullable()->constrained('ai_feedback')->nullOnDelete();
            }
            if (! Schema::hasColumn('farast_feedback_evidence', 'project_id')) {
                $table->foreignId('project_id')->nullable()->constrained('farast_projects')->nullOnDelete();
            }
            if (! Schema::hasColumn('farast_feedback_evidence', 'source')) {
                $table->string('source', 40)->default('user');
            }
            if (! Schema::hasColumn('farast_feedback_evidence', 'tool_id')) {
                $table->foreignId('tool_id')->nullable()->constrained('farast_tools')->nullOnDelete();
            }
            if (! Schema::hasColumn('farast_feedback_evidence', 'tool_version_id')) {
                $table->foreignId('tool_version_id')->nullable()->constrained('farast_tool_versions')->nullOnDelete();
            }
            if (! Schema::hasColumn('farast_feedback_evidence', 'evidence_type')) {
                $table->string('evidence_type', 80)->default('user_feedback');
            }
            if (! Schema::hasColumn('farast_feedback_evidence', 'input_meta')) {
                $table->json('input_meta')->nullable();
            }
            if (! Schema::hasColumn('farast_feedback_evidence', 'output_meta')) {
                $table->json('output_meta')->nullable();
            }
            if (! Schema::hasColumn('farast_feedback_evidence', 'confidence')) {
                $table->decimal('confidence', 5, 4)->nullable();
            }
            if (! Schema::hasColumn('farast_feedback_evidence', 'frequency')) {
                $table->unsignedInteger('frequency')->default(1);
            }
            if (! Schema::hasColumn('farast_feedback_evidence', 'independent_confirmations')) {
                $table->unsignedInteger('independent_confirmations')->default(0);
            }
            if (! Schema::hasColumn('farast_feedback_evidence', 'status')) {
                $table->string('status', 30)->default('raw');
            }
            if (! Schema::hasColumn('farast_feedback_evidence', 'reviewer_notes')) {
                $table->text('reviewer_notes')->nullable();
            }
            if (! Schema::hasColumn('farast_feedback_evidence', 'checksum')) {
                $table->string('checksum', 64)->nullable();
            }
        });
    }

    private function ensureReviewCaseColumns(): void
    {
        if (! Schema::hasTable('farast_review_cases')) {
            throw new RuntimeException('Required table farast_review_cases is missing.');
        }

        Schema::table('farast_review_cases', function (Blueprint $table): void {
            if (! Schema::hasColumn('farast_review_cases', 'evidence_id')) {
                $table->foreignId('evidence_id')->nullable()->constrained('farast_feedback_evidence')->nullOnDelete();
            }
            if (! Schema::hasColumn('farast_review_cases', 'reviewer_id')) {
                $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('farast_review_cases', 'decision')) {
                $table->string('decision', 30)->nullable();
            }
            if (! Schema::hasColumn('farast_review_cases', 'notes')) {
                $table->text('notes')->nullable();
            }
            if (! Schema::hasColumn('farast_review_cases', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable();
            }
        });
    }

    private function ensureApprovedKnowledgeColumns(): void
    {
        if (! Schema::hasTable('farast_approved_knowledge')) {
            throw new RuntimeException('Required table farast_approved_knowledge is missing.');
        }

        Schema::table('farast_approved_knowledge', function (Blueprint $table): void {
            if (! Schema::hasColumn('farast_approved_knowledge', 'review_case_id')) {
                $table->foreignId('review_case_id')->nullable()->constrained('farast_review_cases')->nullOnDelete();
            }
            if (! Schema::hasColumn('farast_approved_knowledge', 'capability_code')) {
                $table->string('capability_code', 100)->nullable();
            }
            if (! Schema::hasColumn('farast_approved_knowledge', 'knowledge_version')) {
                $table->string('knowledge_version', 40)->default('1.0.0');
            }
            if (! Schema::hasColumn('farast_approved_knowledge', 'content')) {
                $table->json('content')->nullable();
            }
            if (! Schema::hasColumn('farast_approved_knowledge', 'status')) {
                $table->string('status', 30)->default('draft');
            }
            if (! Schema::hasColumn('farast_approved_knowledge', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('farast_approved_knowledge', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (! Schema::hasColumn('farast_approved_knowledge', 'checksum')) {
                $table->string('checksum', 64)->nullable();
            }
        });
    }

    private function ensureEvaluationDatasetColumns(): void
    {
        if (! Schema::hasTable('farast_evaluation_datasets')) {
            throw new RuntimeException('Required table farast_evaluation_datasets is missing.');
        }

        Schema::table('farast_evaluation_datasets', function (Blueprint $table): void {
            if (! Schema::hasColumn('farast_evaluation_datasets', 'version')) {
                $table->string('version', 40)->default('1.0.0');
            }
            if (! Schema::hasColumn('farast_evaluation_datasets', 'status')) {
                $table->string('status', 30)->default('draft');
            }
            if (! Schema::hasColumn('farast_evaluation_datasets', 'metadata')) {
                $table->json('metadata')->nullable();
            }
        });
    }

    private function ensureEvaluationCaseColumns(): void
    {
        if (! Schema::hasTable('farast_evaluation_cases')) {
            throw new RuntimeException('Required table farast_evaluation_cases is missing.');
        }

        Schema::table('farast_evaluation_cases', function (Blueprint $table): void {
            if (! Schema::hasColumn('farast_evaluation_cases', 'tool_id')) {
                $table->foreignId('tool_id')->nullable()->constrained('farast_tools')->nullOnDelete();
            }
            if (! Schema::hasColumn('farast_evaluation_cases', 'tool_version_id')) {
                $table->foreignId('tool_version_id')->nullable()->constrained('farast_tool_versions')->nullOnDelete();
            }
            if (! Schema::hasColumn('farast_evaluation_cases', 'input_payload')) {
                $table->json('input_payload')->nullable();
            }
            if (! Schema::hasColumn('farast_evaluation_cases', 'expected_payload')) {
                $table->json('expected_payload')->nullable();
            }
        });
    }

    private function ensureIndex(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $indexes = Schema::getIndexes($tableName);
        $exists = collect($indexes)->contains(
            fn (array $index): bool => ($index['name'] ?? null) === $indexName
                || ($index['columns'] ?? null) === $columns
        );

        if (! $exists) {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
                $table->index($columns, $indexName);
            });
        }
    }

    public function down(): void
    {
        // Trust evidence, reviews and approved knowledge are retained on rollback.
    }
};
