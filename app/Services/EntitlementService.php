<?php
namespace App\Services;
use App\Models\FarastEntitlement;
use App\Models\FarastProject;
use App\Models\User;
class EntitlementService {
 public function __construct(private CapabilityService $capabilities){}
 public function allows(User $user,string $capability,?FarastProject $project=null):bool{
  $q=FarastEntitlement::query()->where('capability_code',$capability)->where('status','active')->where(fn($x)=>$x->where('user_id',$user->id)->orWhereNull('user_id'))->where(fn($x)=>$project?$x->where('project_id',$project->id)->orWhereNull('project_id'):$x->whereNull('project_id'))->where(fn($x)=>$x->whereNull('starts_at')->orWhere('starts_at','<=',now()))->where(fn($x)=>$x->whereNull('ends_at')->orWhere('ends_at','>',now()));
  if($q->exists())return true;
  return match($capability){'document.editing'=>$this->capabilities->allowed($user,'can_type'),'ai.assistance','ai.generation','ai.rewriting','ai.correction','document.intelligence','ocr','handwriting.ocr'=>$this->capabilities->allowed($user,'can_ai'),'speech.transcription'=>$this->capabilities->allowed($user,'can_voice'),'feedback.submit'=>$this->capabilities->allowed($user,'can_feedback'),'export.docx'=>$this->capabilities->allowed($user,'can_export_docx'),'export.pdf'=>$this->capabilities->allowed($user,'can_export_pdf'),default=>false};
 }
 public function grant(string $capability,?int $userId=null,?int $projectId=null,string $mode='temporary_purchase',?int $quantity=null,?array $metadata=null):FarastEntitlement{return FarastEntitlement::create(['user_id'=>$userId,'project_id'=>$projectId,'capability_code'=>$capability,'mode'=>$mode,'status'=>'active','quantity'=>$quantity,'used_quantity'=>0,'starts_at'=>now(),'metadata'=>$metadata]);}
}