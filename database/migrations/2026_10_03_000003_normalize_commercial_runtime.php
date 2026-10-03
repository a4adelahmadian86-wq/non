<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Consolidate any pre-professional plan table into the canonical commercial catalog.
        if (Schema::hasTable('farast_plans') && Schema::hasTable('farast_commercial_plans')) {
            $rows = DB::table('farast_plans')->get();
            foreach ($rows as $row) {
                $code = (string)($row->code ?? 'legacy-plan-'.$row->id);
                if (DB::table('farast_commercial_plans')->where('code',$code)->exists()) continue;
                DB::table('farast_commercial_plans')->insert([
                    'code'=>$code,
                    'name'=>(string)($row->name ?? $code),
                    'product_code'=>$row->product_code ?? null,
                    'billing_interval'=>$row->billing_interval ?? null,
                    'price'=>(int)($row->price ?? 0),
                    'currency'=>(string)($row->currency ?? 'IRR'),
                    'quotas'=>is_string($row->quotas ?? null)?$row->quotas:json_encode($row->quotas ?? [],JSON_UNESCAPED_UNICODE),
                    'allow_payg'=>(bool)($row->allow_payg ?? true),
                    'allow_overage'=>(bool)($row->allow_overage ?? false),
                    'postpaid'=>(bool)($row->postpaid ?? false),
                    'active'=>(bool)($row->active ?? true),
                    'metadata'=>is_string($row->metadata ?? null)?$row->metadata:json_encode($row->metadata ?? [],JSON_UNESCAPED_UNICODE),
                    'created_at'=>$row->created_at ?? now(),
                    'updated_at'=>$row->updated_at ?? now(),
                ]);
            }
        }

        if (Schema::hasTable('farast_subscriptions')) {
            Schema::table('farast_subscriptions',function(Blueprint $t){
                if(!Schema::hasColumn('farast_subscriptions','subscription_id'))$t->uuid('subscription_id')->nullable()->unique();
                if(!Schema::hasColumn('farast_subscriptions','organization_id'))$t->unsignedBigInteger('organization_id')->nullable()->index();
                if(!Schema::hasColumn('farast_subscriptions','cancelled_at'))$t->timestamp('cancelled_at')->nullable();
                if(!Schema::hasColumn('farast_subscriptions','metadata'))$t->json('metadata')->nullable();
            });
            DB::table('farast_subscriptions')->whereNull('subscription_id')->orderBy('id')->get()->each(function($row){
                DB::table('farast_subscriptions')->where('id',$row->id)->update(['subscription_id'=>(string)\Illuminate\Support\Str::uuid()]);
            });
        }

        // Upgrade legacy counters without deleting their historical usage.
        if (Schema::hasTable('farast_usage_counters')) {
            Schema::table('farast_usage_counters',function(Blueprint $t){
                if(!Schema::hasColumn('farast_usage_counters','scope_key'))$t->string('scope_key',220)->nullable()->index();
                if(!Schema::hasColumn('farast_usage_counters','capability'))$t->string('capability',120)->nullable()->index();
                if(!Schema::hasColumn('farast_usage_counters','unit'))$t->string('unit',40)->nullable();
                if(!Schema::hasColumn('farast_usage_counters','quantity'))$t->decimal('quantity',20,6)->default(0);
            });
            if(Schema::hasColumn('farast_usage_counters','metric')){
                DB::table('farast_usage_counters')->orderBy('id')->get()->each(function($row){
                    $parts=explode('|',(string)($row->metric??''),3);
                    DB::table('farast_usage_counters')->where('id',$row->id)->update([
                        'scope_key'=>$parts[0] ?: ('user:'.($row->user_id ?? 'unknown')),
                        'capability'=>$parts[1] ?? (string)($row->metric ?? 'unknown'),
                        'unit'=>$parts[2] ?? 'unit',
                        'quantity'=>(float)($row->used ?? 0),
                    ]);
                });
            }
        }
    }

    public function down(): void
    {
        // Forward compatibility migration: do not delete or reinterpret commercial data on rollback.
    }
};
