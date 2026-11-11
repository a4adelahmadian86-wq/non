<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('farast_feedback_evidence')) {
            Schema::table('farast_feedback_evidence', function (Blueprint $table) {
                if (!Schema::hasColumn('farast_feedback_evidence', 'user_id')) $table->foreignId('user_id')->nullable()->after('feedback_id')->constrained()->nullOnDelete();
                if (!Schema::hasColumn('farast_feedback_evidence', 'project_id')) $table->foreignId('project_id')->nullable()->after('user_id')->constrained('farast_projects')->nullOnDelete();
                if (!Schema::hasColumn('farast_feedback_evidence', 'source')) $table->string('source', 40)->default('user')->after('project_id');
                if (!Schema::hasColumn('farast_feedback_evidence', 'frequency')) $table->unsignedInteger('frequency')->default(1)->after('confidence');
                if (!Schema::hasColumn('farast_feedback_evidence', 'independent_confirmations')) $table->unsignedInteger('independent_confirmations')->default(0)->after('frequency');
                if (!Schema::hasColumn('farast_feedback_evidence', 'reviewer_notes')) $table->text('reviewer_notes')->nullable()->after('status');
                $table->index(['project_id','status']);
                $table->index(['tool_id','tool_version_id','status']);
            });
        }

        if (!Schema::hasTable('farast_audit_events')) {
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
                $table->index(['event_type','created_at']);
                $table->index(['aggregate_type','aggregate_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('farast_audit_events');

        if (Schema::hasTable('farast_feedback_evidence')) {
            Schema::table('farast_feedback_evidence', function (Blueprint $table) {
                foreach (['user_id','project_id'] as $column) {
                    if (Schema::hasColumn('farast_feedback_evidence', $column)) {
                        $table->dropForeign([$column]);
                    }
                }
                foreach (['user_id','project_id','source','frequency','independent_confirmations','reviewer_notes'] as $column) {
                    if (Schema::hasColumn('farast_feedback_evidence', $column)) $table->dropColumn($column);
                }
            });
        }
    }
};
