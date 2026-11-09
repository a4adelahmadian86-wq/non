<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if(Schema::hasTable('discussion_reports')) return;
  Schema::create('discussion_reports',function(Blueprint $table){
   $table->id();
   $table->foreignId('discussion_id')->constrained('content_discussions')->cascadeOnDelete();
   $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
   $table->string('reason',30);
   $table->string('details',1000)->nullable();
   $table->string('status',20)->default('pending');
   $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
   $table->timestamp('reviewed_at')->nullable();
   $table->timestamps();
   $table->unique(['discussion_id','reporter_id'],'discussion_reports_unique_user');
   $table->index(['status','created_at'],'discussion_reports_status_idx');
  });
 }
 public function down(): void { Schema::dropIfExists('discussion_reports'); }
};
