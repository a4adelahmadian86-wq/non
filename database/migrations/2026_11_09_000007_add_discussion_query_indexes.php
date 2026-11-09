<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if(!Schema::hasTable('content_discussions')) return;
  Schema::table('content_discussions',function(Blueprint $table){
   $table->index(['user_id','status','created_at'],'content_discussions_user_status_created_idx');
   $table->index(['target_type','target_key','kind','status'],'content_discussions_target_kind_status_idx');
  });
 }
 public function down(): void {
  if(!Schema::hasTable('content_discussions')) return;
  Schema::table('content_discussions',function(Blueprint $table){
   $table->dropIndex('content_discussions_user_status_created_idx');
   $table->dropIndex('content_discussions_target_kind_status_idx');
  });
 }
};