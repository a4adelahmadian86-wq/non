<?php

namespace App\Services;

use App\Models\FarastCommercialPlan;
use App\Models\FarastEntitlement;
use App\Models\User;

class SubscriptionEntitlementService
{
    public function sync(User $user,int $planId):array
    {
        $plan=FarastCommercialPlan::query()->whereKey($planId)->where('active',true)->first();
        if(!$plan)return [];
        $quotas=is_array($plan->quotas)?$plan->quotas:[];
        $map=[
            'typing_pages'=>['capability'=>'document.editing','unit'=>'page'],
            'pages'=>['capability'=>'document.editing','unit'=>'page'],
            'ai_requests'=>['capability'=>'ai.assistance','unit'=>'request'],
            'ocr_pages'=>['capability'=>'ocr','unit'=>'page'],
            'voice_minutes'=>['capability'=>'speech.transcription','unit'=>'minute'],
            'export_count'=>['capability'=>'export.final','unit'=>'export'],
        ];
        $starts=now();
        $ends=$plan->billing_interval==='monthly'?$starts->copy()->addMonth():($plan->billing_interval==='yearly'?$starts->copy()->addYear():null);
        $created=[];
        foreach($map as $key=>$spec){
            if(!array_key_exists($key,$quotas))continue;
            $quantity=max(0,(int)$quotas[$key]);
            FarastEntitlement::query()->where('user_id',$user->id)->where('source_type','subscription')
                ->where('source_id',(string)$plan->id)->where('capability_code',$spec['capability'])->where('status','active')
                ->update(['status'=>'replaced','ends_at'=>$starts]);
            $created[]=FarastEntitlement::create([
                'user_id'=>$user->id,'organization_id'=>$user->organization_id,'capability_code'=>$spec['capability'],
                'mode'=>'subscription','status'=>'active','quantity'=>$quantity,'used_quantity'=>0,'unit'=>$spec['unit'],
                'priority'=>100,'source_type'=>'subscription','source_id'=>(string)$plan->id,'starts_at'=>$starts,'ends_at'=>$ends,
                'metadata'=>['plan_code'=>$plan->code,'plan_id'=>$plan->id,'quota_key'=>$key],
            ]);
        }
        return $created;
    }
}
