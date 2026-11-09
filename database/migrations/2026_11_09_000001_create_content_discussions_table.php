<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if(Schema::hasTable('content_discussions')) return;
  Schema::create('content_discussions',function(Blueprint $table){
   $table->id();$table->string('target_type',20);$table->string('target_key',240);$table->string('kind',20);
   $table->foreignId('parent_id')->nullable()->constrained('content_discussions')->cascadeOnDelete();
   $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
   $table->string('title',180)->nullable();$table->text('body');$table->unsignedTinyInteger('rating')->nullable();
   $table->string('status',20)->default('pending');
   $table->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
   $table->timestamp('moderated_at')->nullable();$table->timestamps();
   $table->index(['target_type','target_key','status','created_at'],'content_discussions_target_status_idx');
   $table->index(['status','kind','created_at'],'content_discussions_moderation_idx');
   $table->index(['parent_id','status'],'content_discussions_replies_idx');
   $table->index(['user_id','target_type','target_key','kind'],'content_discussions_author_idx');
  });
 }
 public function down(): void { Schema::dropIfExists('content_discussions'); }
};