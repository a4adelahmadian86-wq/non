<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('farast_agent_tasks', function(Blueprint $t) {
   $t->id(); $t->uuid('task_id')->unique(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
   $t->foreignId('project_id')->nullable()->constrained('farast_projects')->nullOnDelete();
   $t->foreignId('document_id')->nullable()->constrained('farast_documents')->nullOnDelete();
   $t->string('status',40)->default('planned'); $t->text('prompt');
   $t->json('intent')->nullable(); $t->json('plan')->nullable(); $t->json('selected_tools')->nullable();
   $t->json('approval')->nullable(); $t->json('preview')->nullable(); $t->json('execution')->nullable();
   $t->unsignedBigInteger('base_revision')->nullable(); $t->unsignedBigInteger('result_revision')->nullable();
   $t->string('idempotency_key',160)->nullable()->unique(); $t->string('error_code',120)->nullable();
   $t->json('metadata')->nullable(); $t->timestamps(); $t->index(['user_id','document_id','status']);
  });
 }
 public function down(): void { Schema::dropIfExists('farast_agent_tasks'); }
};