<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if(Schema::hasTable('content_discussions') && !Schema::hasColumn('content_discussions','moderation_note')) {
   Schema::table('content_discussions',function(Blueprint $table){$table->text('moderation_note')->nullable()->after('moderated_at');});
  }
  if(!Schema::hasTable('discussion_moderation_events')) {
   Schema::create('discussion_moderation_events',function(Blueprint $table){
    $table->id();
    $table->foreignId('discussion_id')->constrained('content_discussions')->cascadeOnDelete();
    $table->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('from_status',20)->nullable();
    $table->string('to_status',20);
    $table->text('note')->nullable();
    $table->timestamp('created_at')->useCurrent();
    $table->index(['discussion_id','created_at'],'discussion_moderation_discussion_idx');
    $table->index(['moderator_id','created_at'],'discussion_moderation_admin_idx');
   });
  }
 }
 public function down(): void {
  Schema::dropIfExists('discussion_moderation_events');
  if(Schema::hasTable('content_discussions') && Schema::hasColumn('content_discussions','moderation_note')) Schema::table('content_discussions',fn(Blueprint $table)=>$table->dropColumn('moderation_note'));
 }
};