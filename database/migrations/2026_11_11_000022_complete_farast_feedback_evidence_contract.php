<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $tableName = 'farast_feedback_evidence';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (! Schema::hasColumn($tableName, 'feedback_id')) {
                $table->unsignedBigInteger('feedback_id')->nullable();
            }

            if (! Schema::hasColumn($tableName, 'tool_id')) {
                $table->unsignedBigInteger('tool_id')->nullable();
            }

            if (! Schema::hasColumn($tableName, 'tool_version_id')) {
                $table->unsignedBigInteger('tool_version_id')->nullable();
            }

            if (! Schema::hasColumn($tableName, 'evidence_type')) {
                $table->string('evidence_type', 40)->default('user_feedback');
            }

            if (! Schema::hasColumn($tableName, 'input_meta')) {
                $table->json('input_meta')->nullable();
            }

            if (! Schema::hasColumn($tableName, 'output_meta')) {
                $table->json('output_meta')->nullable();
            }

            if (! Schema::hasColumn($tableName, 'confidence')) {
                $table->decimal('confidence', 4, 3)->nullable();
            }

            if (! Schema::hasColumn($tableName, 'status')) {
                $table->string('status', 30)->default('raw');
            }

            if (! Schema::hasColumn($tableName, 'checksum')) {
                $table->string('checksum', 64)->nullable();
            }
        });

        if (Schema::hasColumn($tableName, 'feedback_id') && ! Schema::hasIndex($tableName, ['feedback_id'])) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->index('feedback_id');
            });
        }

        if (
            Schema::hasColumn($tableName, 'project_id')
            && Schema::hasColumn($tableName, 'status')
            && ! Schema::hasIndex($tableName, ['project_id', 'status'])
        ) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->index(['project_id', 'status']);
            });
        }

        if (
            Schema::hasColumn($tableName, 'tool_id')
            && Schema::hasColumn($tableName, 'tool_version_id')
            && Schema::hasColumn($tableName, 'status')
            && ! Schema::hasIndex($tableName, ['tool_id', 'tool_version_id', 'status'])
        ) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->index(['tool_id', 'tool_version_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Deliberately irreversible: these columns complete the canonical evidence
        // record and may already contain durable audit/feedback data on upgraded databases.
    }
};
