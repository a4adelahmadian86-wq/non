<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserKnowledge;

class UserKnowledgeService
{
    public function confirmed(User $user): array
    {
        return UserKnowledge::where('user_id',$user->id)->where('scope','global')->where('status','confirmed')
            ->where(fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))
            ->get()->mapWithKeys(fn($x)=>[$x->key=>$x->value])->all();
    }

    public function rememberConfirmed(User $user, string $key, mixed $value): UserKnowledge
    {
        return UserKnowledge::updateOrCreate(
            ['user_id'=>$user->id,'key'=>$key,'scope'=>'global'],
            ['value'=>is_array($value)?$value:['value'=>$value],'source'=>'explicit','status'=>'confirmed','confidence'=>1.0,'expires_at'=>null]
        );
    }

    public function rememberInferred(User $user, string $key, mixed $value, float $confidence = 0.6): UserKnowledge
    {
        return UserKnowledge::updateOrCreate(
            ['user_id'=>$user->id,'key'=>$key,'scope'=>'global'],
            ['value'=>is_array($value)?$value:['value'=>$value],'source'=>'inferred','status'=>'inferred','confidence'=>max(0,min(1,$confidence)),'expires_at'=>now()->addDays(90)]
        );
    }

    public function snapshotForProject(User $user): array
    {
        return $this->confirmed($user);
    }
}
