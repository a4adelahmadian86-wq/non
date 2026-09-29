<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void{Schema::table('ai_interactions',function(Blueprint $t){$t->unsignedBigInteger('input_tokens')->nullable()->after('input_bytes');$t->unsignedBigInteger('output_tokens')->nullable()->after('input_tokens');$t->unsignedBigInteger('total_tokens')->nullable()->after('output_tokens');$t->decimal('estimated_cost',14,6)->nullable()->after('total_tokens');$t->index(['provider','created_at']);});}public function down():void{Schema::table('ai_interactions',function(Blueprint $t){$t->dropIndex(['provider','created_at']);$t->dropColumn(['input_tokens','output_tokens','total_tokens','estimated_cost']);});}};