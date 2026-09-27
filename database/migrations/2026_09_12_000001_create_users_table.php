<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('users',function(Blueprint $t){$t->id();$t->string('name')->nullable();$t->string('mobile',20)->unique();$t->string('password')->nullable();$t->string('role')->default('user');$t->boolean('is_verified')->default(false);$t->boolean('is_blocked')->default(false);$t->rememberToken();$t->timestamps();}); } public function down(): void {Schema::dropIfExists('users');} };
