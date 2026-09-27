<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up(): void {Schema::create('otp_challenges',function(Blueprint $t){$t->id();$t->string('mobile',20)->index();$t->string('code_hash');$t->unsignedTinyInteger('attempts')->default(0);$t->timestamp('expires_at')->index();$t->timestamp('consumed_at')->nullable();$t->timestamps();});}public function down(): void {Schema::dropIfExists('otp_challenges');}};
