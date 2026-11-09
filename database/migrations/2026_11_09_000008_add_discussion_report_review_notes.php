<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if(Schema::hasTable('discussion_reports') && !Schema::hasColumn('discussion_reports','review_note')) {
   Schema::table('discussion_reports',function(Blueprint $table){$table->text('review_note')->nullable()->after('reviewed_at');});
  }
 }
 public function down(): void {
  if(Schema::hasTable('discussion_reports') && Schema::hasColumn('discussion_reports','review_note')) {
   Schema::table('discussion_reports',fn(Blueprint $table)=>$table->dropColumn('review_note'));
  }
 }
};