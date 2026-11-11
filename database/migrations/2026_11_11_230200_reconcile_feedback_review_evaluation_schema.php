<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->reconcileReviewCases();
        $this->reconcileApprovedKnowledge();
        $this->reconcileEvaluationDatasets();
        $this->reconcileEvaluationCases();

        $this->ensureIndex('farast_review_cases', ['evidence_id', 'status'], 'farast_review_cases_evidence_status_idx');
        $this->ensureIndex('farast_approved_knowledge', ['capability_code', 'status'], 'farast_approved_knowledge_capability_status_idx');
        $this->ensureIndex('farast_evaluation_cases', ['dataset_id', 'status'], 'farast_evaluation_cases_dataset_status_idx');
    }

    private function reconcileReviewCases(): void
    {
        $tableName = 'farast_review_cases';

        if (! Schema::hasTable($tableName)) {
            throw new RuntimeException('farast_review_cases must exist before its schema can be reconciled.');
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
            throw new RuntimeException('farast_approved_knowledge must exist before its schema can be reconciled.');
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
            throw new RuntimeException('farast_evaluation_datasets must exist before its schema can be reconciled.');
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
            throw new RuntimeException('farast_evaluation_cases must exist before its schema can be reconciled.');
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
        // Forward-only reconciliation: review decisions and approved knowledge
        // are durable operational records and must not be removed on rollback.
    }
};
