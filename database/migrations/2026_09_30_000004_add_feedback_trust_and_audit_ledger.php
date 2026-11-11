<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

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
                if (! Schema::hasColumn('farast_feedback_evidence', 'source')) {
                    $table->string('source', 40)->default('user');
                }
                // confidence and status are introduced by the later phase-5 migration.
                // Keep this migration independent of columns that do not exist yet.
                if (! Schema::hasColumn('farast_feedback_evidence', 'frequency')) {
                    $table->unsignedInteger('frequency')->default(1);
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'independent_confirmations')) {
                    $table->unsignedInteger('independent_confirmations')->default(0);
                }
                if (! Schema::hasColumn('farast_feedback_evidence', 'reviewer_notes')) {
                    $table->text('reviewer_notes')->nullable();
                }
            });

            // Create composite indexes only when their complete column sets exist.
            // On a fresh install, phase-5 introduces status/tool identifiers later.
            $indexes = Schema::getIndexes('farast_feedback_evidence');
            $hasIndex = static function (array $columns) use ($indexes): bool {
                foreach ($indexes as $index) {
                    if (($index['columns'] ?? []) === $columns) {
                        return true;
                    }
                }

                return false;
            };

            if (
                Schema::hasColumn('farast_feedback_evidence', 'project_id')
                && Schema::hasColumn('farast_feedback_evidence', 'status')
                && ! $hasIndex(['project_id', 'status'])
            ) {
                Schema::table('farast_feedback_evidence', fn (Blueprint $table) => $table->index(['project_id', 'status']));
            }

            if (
                Schema::hasColumn('farast_feedback_evidence', 'tool_id')
                && Schema::hasColumn('farast_feedback_evidence', 'tool_version_id')
                && Schema::hasColumn('farast_feedback_evidence', 'status')
                && ! $hasIndex(['tool_id', 'tool_version_id', 'status'])
            ) {
                Schema::table('farast_feedback_evidence', fn (Blueprint $table) => $table->index(['tool_id', 'tool_version_id', 'status']));
            }
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
        Schema::dropIfExists('farast_audit_events');

        if (Schema::hasTable('farast_feedback_evidence')) {
            $foreignKeys = Schema::getForeignKeys('farast_feedback_evidence');
            foreach ($foreignKeys as $foreignKey) {
                $columns = $foreignKey['columns'] ?? [];
                if (count($columns) === 1 && in_array($columns[0], ['project_id'], true)) {
                    Schema::table('farast_feedback_evidence', function (Blueprint $table) use ($foreignKey): void {
                        $table->dropForeign($foreignKey['name']);
                    });
                }
            }

            // user_id belongs to the original registry table and must not be dropped.
            foreach (['project_id', 'source', 'frequency', 'independent_confirmations', 'reviewer_notes'] as $column) {
                if (Schema::hasColumn('farast_feedback_evidence', $column)) {
                    Schema::table('farast_feedback_evidence', fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }
};
