<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if(!Schema::hasTable('discussion_subscriptions')) Schema::create('discussion_subscriptions',function(Blueprint $table){
   $table->id();
   $table->foreignId('discussion_id')->constrained('content_discussions')->cascadeOnDelete();
   $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
   $table->timestamps();
   $table->unique(['discussion_id','user_id'],'discussion_subscriptions_unique');
   $table->index(['user_id','created_at'],'discussion_subscriptions_user_idx');
  });
 }
 public function down(): void { Schema::dropIfExists('discussion_subscriptions'); }
};
