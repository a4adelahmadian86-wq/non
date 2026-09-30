<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('farast_projects')) {
            Schema::create('farast_projects', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name',255);
                $table->string('project_type',60);
                $table->string('workflow',60)->nullable();
                $table->string('template_code',100)->nullable();
                $table->string('status',30)->default('active');
                $table->json('context')->nullable();
                $table->json('billing_state')->nullable();
                $table->json('output_state')->nullable();
                $table->unsignedInteger('estimated_pages')->default(1);
                $table->unsignedInteger('used_pages')->default(0);
                $table->unsignedBigInteger('estimated_price_rials')->default(0);
                $table->unsignedBigInteger('paid_rials')->default(0);
                $table->json('entitlement_snapshot')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['user_id','project_type','status']);
            });
        }

        if (Schema::hasTable('farast_documents') && !Schema::hasColumn('farast_documents','project_id')) {
            Schema::table('farast_documents', function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->after('user_id')->constrained('farast_projects')->nullOnDelete();
                $table->index(['project_id','user_id']);
            });
        }

        if (Schema::hasTable('typing_documents') && !Schema::hasColumn('typing_documents','project_id')) {
            Schema::table('typing_documents', function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->after('user_id')->constrained('farast_projects')->nullOnDelete();
                $table->index(['project_id','user_id']);
            });
        }

        if (!Schema::hasTable('farast_user_knowledge')) {
            Schema::create('farast_user_knowledge', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('key',120);
                $table->json('value')->nullable();
                $table->string('source',30)->default('explicit');
                $table->string('status',30)->default('confirmed');
                $table->decimal('confidence',4,3)->default(1);
                $table->string('scope',30)->default('global');
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id','key','scope']);
                $table->index(['user_id','status','expires_at']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('farast_documents') && Schema::hasColumn('farast_documents','project_id')) {
            Schema::table('farast_documents', function (Blueprint $table) {
                $table->dropForeign(['project_id']);
                $table->dropIndex(['project_id','user_id']);
                $table->dropColumn('project_id');
            });
        }
        if (Schema::hasTable('typing_documents') && Schema::hasColumn('typing_documents','project_id')) {
            Schema::table('typing_documents', function (Blueprint $table) {
                $table->dropForeign(['project_id']);
                $table->dropIndex(['project_id','user_id']);
                $table->dropColumn('project_id');
            });
        }
        Schema::dropIfExists('farast_user_knowledge');
        Schema::dropIfExists('farast_projects');
    }
};
