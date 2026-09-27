<?php

namespace App\Services;

use App\Models\VoiceProviderAccount;
use App\Models\VoiceProviderUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class VoiceQuotaManager
{
    public const SAFETY_RESERVE_SECONDS=5;

    public function availableSeconds(VoiceProviderAccount $account):?int
    {
        $this->resetMonthlyPeriodIfNeeded($account);
        if($account->billing_mode==='trial'){
            if($account->credit_expires_at&&$account->credit_expires_at->isPast())return 0;
            if($account->credit_remaining!==null)return max(0,(int)floor((float)$account->credit_remaining));
        }
        return $account->quota_limit_seconds===null?null:max(0,(int)$account->quota_limit_seconds-(int)$account->quota_used_seconds);
    }

    public function canStart(VoiceProviderAccount $account,int $minimumSeconds=1):bool
    {
        $available=$this->availableSeconds($account);
        return $available===null||$available>=($minimumSeconds+self::SAFETY_RESERVE_SECONDS);
    }

    public function consume(VoiceProviderAccount $account,float $audioSeconds,int $inputBytes=0,int $outputBytes=0,bool $success=true):void
    {
        $seconds=max(0,$audioSeconds);
        DB::transaction(function()use($account,$seconds,$inputBytes,$outputBytes,$success){
            $row=VoiceProviderAccount::query()->lockForUpdate()->find($account->id);if(!$row)return;
            $this->resetMonthlyPeriodIfNeeded($row);
            if($seconds>0){
                $row->quota_used_seconds=(float)$row->quota_used_seconds+$seconds;
                if($row->billing_mode==='trial'&&$row->credit_remaining!==null)$row->credit_remaining=max(0,(float)$row->credit_remaining-$seconds);
                $row->save();
            }
            $usage=VoiceProviderUsage::query()->firstOrCreate(['voice_provider_account_id'=>$row->id,'usage_date'=>now()->toDateString()],['audio_seconds'=>0,'requests'=>0,'successful_requests'=>0,'failed_requests'=>0,'input_bytes'=>0,'output_bytes'=>0]);
            if($seconds>0)$usage->increment('audio_seconds',$seconds);
            if($inputBytes>0)$usage->increment('input_bytes',$inputBytes);
            if($outputBytes>0)$usage->increment('output_bytes',$outputBytes);
            $usage->increment('requests');
            $usage->increment($success?'successful_requests':'failed_requests');
        });
    }

    public function recordRequest(VoiceProviderAccount $account,bool $success,?int $latencyMs=null):void
    {
        $failures=$success?0:((int)$account->consecutive_failures+1);
        $updates=['last_success_at'=>$success?now():$account->last_success_at,'last_failure_at'=>$success?$account->last_failure_at:now(),'last_latency_ms'=>$latencyMs,'consecutive_failures'=>$failures];
        if(!$success){$updates['cooldown_until']=now()->addSeconds(min(300,5*(2**min(6,max(0,$failures-1)))));if($failures>=8)$updates['healthy']=false;}else{$updates['cooldown_until']=null;$updates['healthy']=true;}
        $account->update($updates);
    }

    public function resetMonthlyPeriodIfNeeded(VoiceProviderAccount $account):void
    {
        if($account->billing_mode!=='monthly_free')return;
        $ends=$account->quota_period_ends_at;
        if($ends&&$ends->isFuture())return;
        $start=$ends&&$ends->isPast()?$ends->copy():now();
        $account->quota_used_seconds=0;$account->quota_period_started_at=$start;$account->quota_period_ends_at=$start->copy()->addMonth();$account->save();
    }
}
