<?php

namespace App\Services;

use App\Models\FarastEntitlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SubscriptionEntitlementService
{
    public function sync(User $user, int $planId): array
    {
        $plan=DB::table('farast_plans')->where('id',$planId)->where('active',1)->first();
        if(!$plan)return [];
        $quotas=is_string($plan->quotas??null)?json_decode($plan->quotas,true):($plan->quotas??[]);
        $quotas=is_array($quotas)?$quotas:[];
        $map=[
            'typing_pages'=>['capability'=>'document.editing','unit'=>'page'],
            'pages'=>['capability'=>'document.editing','unit'=>'page'],
            'ai_requests'=>['capability'=>'ai.assistance','unit'=>'request'],
            'ocr_pages'=>['capability'=>'ocr','unit'=>'page'],
            'voice_minutes'=>['capability'=>'speech.transcription','unit'=>'minute'],
            'export_count'=>['capability'=>'export.final','unit'=>'export'],
        ];
        $created=[];
        foreach($map as $key=>$spec){
            if(!array_key_exists($key,$quotas))continue;
            $quantity=max(0,(int)$quotas[$key]);
            $created[]=FarastEntitlement::create([
                'user_id'=>$user->id,'organization_id'=>$user->organization_id,'capability_code'=>$spec['capability'],
                'mode'=>'subscription','status'=>'active','quantity'=>$quantity,'used_quantity'=>0,'unit'=>$spec['unit'],
                'priority'=>100,'source_type'=>'subscription','source_id'=>(string)$planId,
                'starts_at'=>now(),'ends_at'=>now()->addMonth(),'metadata'=>['plan_code'=>$plan->code,'plan_id'=>$planId,'quota_key'=>$key],
            ]);
        }
        return $created;
    }
}