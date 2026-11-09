<?php
use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;
return new class extends Migration {
 public function up(): void {
  if (Schema::hasTable('discussion_reactions')) return;
  Schema::create('discussion_reactions', function (Blueprint $table) {
   $table->id();
   $table->foreignId('discussion_id')->constrained('content_discussions')->cascadeOnDelete();
   $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
   $table->string('type', 10);
   $table->timestamps();
   $table->unique(['discussion_id', 'user_id'], 'discussion_reactions_user_unique');
   $table->index(['discussion_id', 'type'], 'discussion_reactions_type_idx');
  });
 }
 public function down(): void { Schema::dropIfExists('discussion_reactions'); }
};
