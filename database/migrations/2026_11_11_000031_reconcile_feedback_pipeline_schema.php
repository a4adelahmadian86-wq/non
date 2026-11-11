<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->reconcileFeedbackEvidence();
        $this->reconcileReviewCases();
        $this->reconcileApprovedKnowledge();
        $this->reconcileEvaluationDatasets();
        $this->reconcileEvaluationCases();

        $this->ensureIndex('farast_feedback_evidence', ['project_id', 'status'], 'farast_feedback_evidence_project_status_idx');
        $this->ensureIndex('farast_feedback_evidence', ['tool_id', 'tool_version_id', 'status'], 'farast_feedback_evidence_tool_version_status_idx');
        $this->ensureIndex('farast_review_cases', ['evidence_id', 'status'], 'farast_review_cases_evidence_status_idx');
        $this->ensureIndex('farast_approved_knowledge', ['capability_code', 'status'], 'farast_approved_knowledge_capability_status_idx');
        $this->ensureIndex('farast_evaluation_cases', ['dataset_id', 'status'], 'farast_evaluation_cases_dataset_status_idx');
    }

    private function reconcileFeedbackEvidence(): void
    {
        $tableName = 'farast_feedback_evidence';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        $columns = Schema::getColumnListing($tableName);

        Schema::table($tableName, function (Blueprint $table) use ($columns): void {
            if (! in_array('feedback_id', $columns, true)) {
                $table->foreignId('feedback_id')->nullable()->constrained('ai_feedback')->nullOnDelete();
            }
            if (! in_array('user_id', $columns, true)) {
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            }
            if (! in_array('project_id', $columns, true)) {
                $table->foreignId('project_id')->nullable()->constrained('farast_projects')->nullOnDelete();
            }
            if (! in_array('source', $columns, true)) {
                $table->string('source', 40)->default('user');
            }
            if (! in_array('tool_id', $columns, true)) {
                $table->foreignId('tool_id')->nullable()->constrained('farast_tools')->nullOnDelete();
            }
            if (! in_array('tool_version_id', $columns, true)) {
                $table->foreignId('tool_version_id')->nullable()->constrained('farast_tool_versions')->nullOnDelete();
            }
            if (! in_array('evidence_type', $columns, true)) {
                $table->string('evidence_type', 80)->default('user_feedback');
            }
            if (! in_array('input_meta', $columns, true)) {
                $table->json('input_meta')->nullable();
            }
            if (! in_array('output_meta', $columns, true)) {
                $table->json('output_meta')->nullable();
            }
            if (! in_array('confidence', $columns, true)) {
                $table->decimal('confidence', 5, 4)->nullable();
            }
            if (! in_array('frequency', $columns, true)) {
                $table->unsignedInteger('frequency')->default(1);
            }
            if (! in_array('independent_confirmations', $columns, true)) {
                $table->unsignedInteger('independent_confirmations')->default(0);
            }
            if (! in_array('status', $columns, true)) {
                $table->string('status', 30)->default('raw');
            }
            if (! in_array('reviewer_notes', $columns, true)) {
                $table->text('reviewer_notes')->nullable();
            }
            if (! in_array('checksum', $columns, true)) {
                $table->string('checksum', 64)->nullable();
            }
        });
    }

    private function reconcileReviewCases(): void
    {
        $tableName = 'farast_review_cases';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        $columns = Schema::getColumnListing($tableName);

        Schema::table($tableName, function (Blueprint $table) use ($columns): void {
            if (! in_array('evidence_id', $columns, true)) {
                $table->foreignId('evidence_id')->nullable()->constrained('farast_feedback_evidence')->nullOnDelete();
            }
            if (! in_array('reviewer_id', $columns, true)) {
                $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! in_array('decision', $columns, true)) {
                $table->string('decision', 30)->nullable();
            }
            if (! in_array('reviewed_at', $columns, true)) {
                $table->timestamp('reviewed_at')->nullable();
            }
            if (! in_array('notes', $columns, true)) {
                $table->text('notes')->nullable();
            }
        });
    }

    private function reconcileApprovedKnowledge(): void
    {
        $tableName = 'farast_approved_knowledge';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        $columns = Schema::getColumnListing($tableName);

        Schema::table($tableName, function (Blueprint $table) use ($columns): void {
            if (! in_array('review_case_id', $columns, true)) {
                $table->foreignId('review_case_id')->nullable()->constrained('farast_review_cases')->nullOnDelete();
            }
            if (! in_array('capability_code', $columns, true)) {
                $table->string('capability_code', 100)->nullable();
            }
            if (! in_array('knowledge_version', $columns, true)) {
                $table->string('knowledge_version', 80)->default('1.0.0');
            }
            if (! in_array('content', $columns, true)) {
                $table->longText('content')->nullable();
            }
            if (! in_array('status', $columns, true)) {
                $table->string('status', 30)->default('pending');
            }
            if (! in_array('approved_by', $columns, true)) {
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! in_array('approved_at', $columns, true)) {
                $table->timestamp('approved_at')->nullable();
            }
            if (! in_array('checksum', $columns, true)) {
                $table->string('checksum', 64)->nullable();
            }
        });
    }

    private function reconcileEvaluationDatasets(): void
    {
        $tableName = 'farast_evaluation_datasets';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        $columns = Schema::getColumnListing($tableName);

        Schema::table($tableName, function (Blueprint $table) use ($columns): void {
            if (! in_array('version', $columns, true)) {
                $table->string('version', 80)->default('1.0.0');
            }
            if (! in_array('status', $columns, true)) {
                $table->string('status', 30)->default('draft');
            }
            if (! in_array('metadata', $columns, true)) {
                $table->json('metadata')->nullable();
            }
        });
    }

    private function reconcileEvaluationCases(): void
    {
        $tableName = 'farast_evaluation_cases';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        $columns = Schema::getColumnListing($tableName);

        Schema::table($tableName, function (Blueprint $table) use ($columns): void {
            if (! in_array('tool_id', $columns, true)) {
                $table->foreignId('tool_id')->nullable()->constrained('farast_tools')->nullOnDelete();
            }
            if (! in_array('tool_version_id', $columns, true)) {
                $table->foreignId('tool_version_id')->nullable()->constrained('farast_tool_versions')->nullOnDelete();
            }
            if (! in_array('input_payload', $columns, true)) {
                $table->json('input_payload')->nullable();
            }
            if (! in_array('expected_payload', $columns, true)) {
                $table->json('expected_payload')->nullable();
            }
        });
    }

    private function ensureIndex(string $tableName, array $columns, string $name): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return;
            }
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $name): void {
            $table->index($columns, $name);
        });
    }

    public function down(): void
    {
        // This is a forward-only schema reconciliation. The new columns may
        // contain production feedback and review history; rollback must not
        // erase that data. Deploy a deliberate data migration to reverse it.
    }
};
