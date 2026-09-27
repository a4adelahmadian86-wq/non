<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up(): void {Schema::create('weekly_free_pages',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->date('week_start');$t->unsignedInteger('pages')->default(1);$t->timestamps();$t->unique(['user_id','week_start']);});}public function down(): void {Schema::dropIfExists('weekly_free_pages');}};
