<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('farast_subscriptions')) return;

        $legacy='farast_subscriptions_legacy_'.date('YmdHis');
        Schema::rename('farast_subscriptions',$legacy);

        Schema::create('farast_subscriptions',function(Blueprint $table){
            $table->id();
            $table->uuid('subscription_id')->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('plan_id')->index();
            $table->string('status',30)->default('active')->index();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $columns=Schema::getColumnListing($legacy);
        $rows=DB::table($legacy)->get();
        foreach($rows as $row){
            $planId=$row->plan_id??null;
            if(!$planId)continue;
            // Prefer an already migrated commercial plan with the same legacy id;
            // otherwise resolve by legacy code when available.
            if(!DB::table('farast_commercial_plans')->where('id',$planId)->exists()){
                $code=isset($row->plan_code)?(string)$row->plan_code:null;
                if($code)$planId=DB::table('farast_commercial_plans')->where('code',$code)->value('id');
            }
            if(!$planId)continue;

            $subscriptionId=in_array('subscription_id',$columns,true)&&$row->subscription_id
                ?(string)$row->subscription_id:(string)Str::uuid();

            DB::table('farast_subscriptions')->insert([
                'subscription_id'=>$subscriptionId,
                'user_id'=>$row->user_id??null,
                'organization_id'=>$row->organization_id??null,
                'plan_id'=>$planId,
                'status'=>(string)($row->status??'active'),
                'starts_at'=>$row->starts_at??now(),
                'ends_at'=>$row->ends_at??null,
                'cancelled_at'=>$row->cancelled_at??null,
                'metadata'=>$row->metadata??null,
                'created_at'=>$row->created_at??now(),
                'updated_at'=>$row->updated_at??now(),
            ]);
        }
    }

    public function down(): void
    {
        // Keep canonical subscription history intact on rollback.
    }
};
