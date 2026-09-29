<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('permissions', function(Blueprint $t){$t->id();$t->string('key')->unique();$t->string('label');$t->string('group')->nullable();$t->timestamps();});
  Schema::create('role_permissions', function(Blueprint $t){$t->id();$t->string('role');$t->foreignId('permission_id')->constrained()->cascadeOnDelete();$t->timestamps();$t->unique(['role','permission_id']);$t->index('role');});
 }
 public function down(): void {Schema::dropIfExists('role_permissions');Schema::dropIfExists('permissions');}
};