<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('organizations', function(Blueprint $t){$t->id();$t->string('name');$t->string('slug')->unique();$t->string('status')->default('active');$t->json('settings')->nullable();$t->timestamps();});
  Schema::table('users', function(Blueprint $t){$t->foreignId('organization_id')->nullable()->after('id')->constrained('organizations')->nullOnDelete();});
  Schema::create('teams', function(Blueprint $t){$t->id();$t->foreignId('organization_id')->constrained()->cascadeOnDelete();$t->string('name');$t->string('slug');$t->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();$t->json('settings')->nullable();$t->timestamps();$t->unique(['organization_id','slug']);});
  Schema::create('team_user', function(Blueprint $t){$t->id();$t->foreignId('team_id')->constrained()->cascadeOnDelete();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('team_role')->default('employee');$t->timestamps();$t->unique(['team_id','user_id']);});
 }
 public function down(): void {Schema::dropIfExists('team_user');Schema::dropIfExists('teams');Schema::table('users',fn(Blueprint $t)=>$t->dropConstrainedForeignId('organization_id'));Schema::dropIfExists('organizations');}
};