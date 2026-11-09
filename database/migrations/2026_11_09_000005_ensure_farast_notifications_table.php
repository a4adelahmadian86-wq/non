<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if(!Schema::hasTable('farast_notifications')) Schema::create('farast_notifications',function(Blueprint $table){
   $table->id();
   $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
   $table->string('type',100)->index();
   $table->string('title',180);
   $table->text('body')->nullable();
   $table->json('data')->nullable();
   $table->timestamp('read_at')->nullable();
   $table->timestamps();
   $table->index(['user_id','read_at','created_at'],'farast_notifications_user_read_idx');
  });
 }
 public function down(): void {
  // The notification ledger is shared by platform modules; never drop user notifications on rollback.
 }
};
