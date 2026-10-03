<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HumanTaskService
{
    public function request(User $requester,string $capability,array $input,array $context=[]):array
    {
        $taskId=(string)Str::uuid();
        $id=DB::table('farast_human_tasks')->insertGetId([
            'task_id'=>$taskId,'capability'=>$capability,'project_id'=>$context['project_id']??null,'requester_id'=>$requester->id,
            'sla'=>json_encode($context['sla']??[]),'input'=>json_encode($input,JSON_UNESCAPED_UNICODE),
            'qa'=>json_encode(['status'=>'pending']),'acceptance'=>json_encode(['status'=>'pending']),
            'billing'=>json_encode(['capability'=>$capability,'status'=>'pending']),
            'provenance'=>json_encode(['source'=>'farast_tool','version'=>'1.0.0']),
            'status'=>'queued','correlation_id'=>$context['correlation_id']??null,'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(AuditEventService::class)->record('human_task.created',$requester->id,$context['project_id']??null,'human_task',$id,['task_id'=>$taskId,'capability'=>$capability]);
        return ['task_id'=>$taskId,'status'=>'queued'];
    }

    public function assign(string $taskId,int $specialistId):void
    {
        DB::table('farast_human_tasks')->where('task_id',$taskId)->update(['specialist_id'=>$specialistId,'status'=>'assigned','updated_at'=>now()]);
    }
    public function submitOutput(string $taskId,array $output,array $provenance=[]):void
    {
        DB::table('farast_human_tasks')->where('task_id',$taskId)->update(['output'=>json_encode($output,JSON_UNESCAPED_UNICODE),'provenance'=>json_encode($provenance),'status'=>'qa','updated_at'=>now()]);
    }
    public function qa(string $taskId,bool $passed,array $qa=[]):void
    {
        DB::table('farast_human_tasks')->where('task_id',$taskId)->update(['qa'=>json_encode(array_merge($qa,['passed'=>$passed])),'status'=>$passed?'acceptance':'qa_failed','updated_at'=>now()]);
    }
    public function accept(string $taskId,bool $accepted,array $acceptance=[]):void
    {
        DB::table('farast_human_tasks')->where('task_id',$taskId)->update(['acceptance'=>json_encode(array_merge($acceptance,['accepted'=>$accepted])),'status'=>$accepted?'completed':'rework','updated_at'=>now()]);
    }
}