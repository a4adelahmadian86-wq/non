<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if(!Schema::hasTable('farast_plans'))return;
        Schema::table('farast_plans',function(Blueprint $t){
            if(!Schema::hasColumn('farast_plans','product_code'))$t->string('product_code',120)->nullable()->index();
            if(!Schema::hasColumn('farast_plans','billing_interval'))$t->string('billing_interval',30)->nullable();
            if(!Schema::hasColumn('farast_plans','allow_payg'))$t->boolean('allow_payg')->default(true);
            if(!Schema::hasColumn('farast_plans','allow_overage'))$t->boolean('allow_overage')->default(false);
            if(!Schema::hasColumn('farast_plans','postpaid'))$t->boolean('postpaid')->default(false);
            if(!Schema::hasColumn('farast_plans','metadata'))$t->json('metadata')->nullable();
        });
    }
    public function down(): void
    {
        if(!Schema::hasTable('farast_plans'))return;
        Schema::table('farast_plans',function(Blueprint $t){
            foreach(['product_code','billing_interval','allow_payg','allow_overage','postpaid','metadata'] as $c)
                if(Schema::hasColumn('farast_plans',$c))$t->dropColumn($c);
        });
    }
};