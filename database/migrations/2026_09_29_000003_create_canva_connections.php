<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('canva_connections', function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->text('access_token')->nullable();$t->text('refresh_token')->nullable();$t->timestamp('expires_at')->nullable();$t->json('scopes')->nullable();$t->string('canva_user_id')->nullable();$t->string('status')->default('connected');$t->timestamp('last_checked_at')->nullable();$t->json('metadata')->nullable();$t->timestamps();$t->unique('user_id');});
 }
 public function down(): void {Schema::dropIfExists('canva_connections');}
};