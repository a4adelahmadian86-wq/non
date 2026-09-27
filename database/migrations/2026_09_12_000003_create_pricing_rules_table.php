<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up(): void {Schema::create('pricing_rules',function(Blueprint $t){$t->id();$t->string('key')->unique();$t->unsignedBigInteger('value');$t->string('label');$t->boolean('active')->default(true);$t->timestamps();});}public function down(): void {Schema::dropIfExists('pricing_rules');}};
