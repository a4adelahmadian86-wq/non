<?php

namespace App\Services;

use App\Models\User;
use App\Support\FarastToolDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ToolExecutionService
{
    public function __construct(
        private ToolRegistry $registry,
        private ToolSchemaValidator $schemas,
        private CommerceAuthorizationService $commerce,
        private AuditEventService $audit,
        private HumanTaskService $humanTasks,
        private FarastToolHandlerRegistry $handlers,
    ) {}

    public function execute(User $actor,string $toolCode,array $input,array $context=[]):array
    {
        $d=$this->definition($toolCode,$context['application']??null);
        if(($d->permissions['authenticated']??true)&&!$actor->exists)throw new RuntimeException('authentication_required');
        $this->schemas->validate($input,$d->inputSchema);

        $idempotency=(string)($context['idempotency_key']??('tool-'.$actor->id.'-'.$toolCode.'-'.hash('sha256',json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))));
        $existing=DB::table('farast_tool_executions')->where('idempotency_key',$idempotency)->first();
        if($existing&&$existing->status==='succeeded')
            return ['ok'=>true,'execution_id'=>$existing->execution_id,'output'=>json_decode((string)$existing->output_meta,true)?:[]];

        $executionId=(string)Str::uuid();
        $correlation=(string)($context['correlation_id']??Str::uuid());
        $requestId=(string)($context['request_id']??Str::uuid());
        $transactionId=(string)Str::uuid();
        $executionRow=DB::table('farast_tool_executions')->insertGetId([
            'execution_id'=>$executionId,'tool_id'=>$d->tool->id,'tool_version_id'=>$d->version->id,'actor_id'=>$actor->id,
            'organization_id'=>$actor->organization_id,'project_id'=>$context['project_id']??null,'document_id'=>$context['document_id']??null,
            'capability'=>$d->entitlementPolicy['capability']??$d->tool->capability?->code,'status'=>'running','idempotency_key'=>$idempotency,
            'correlation_id'=>$correlation,'request_id'=>$requestId,'transaction_id'=>$transactionId,
            'input_meta'=>json_encode($this->safeMeta($input),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'provenance'=>json_encode(['tool'=>$toolCode,'version'=>$d->version->version],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'started_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);

        $quantity=$this->usageQuantity($d,$context);
        $reservation=null;
        try{
            $capability=$d->entitlementPolicy['capability']??$d->tool->capability->code;
            $reservation=$this->commerce->reserve($actor,$capability,$quantity,
                ['project_id'=>$context['project_id']??null,'document_id'=>$context['document_id']??null],
                ['policy_code'=>$capability,'unit'=>$d->entitlementPolicy['unit']??$d->usageMeter,
                 'allow_payg'=>$context['allow_payg']??true,'idempotency_key'=>'tool-res-'.$idempotency]);

            $handlerContext=array_merge($context,['transaction_id'=>$transactionId,'tool_execution_id'=>$executionId]);
            $output=$d->tool->code==='human.assistance'
                ? $this->humanTasks->request($actor,(string)$input['capability'],$input['input'],array_merge($handlerContext,['sla'=>$input['sla']??[]]))
                : $this->handlers->handler($d->tool->code)->handle($actor,$input,$handlerContext);

            $this->schemas->validate($output,$d->outputSchema);
            $usage=$this->commerce->commit($reservation,['metadata'=>['tool_execution_id'=>$executionId,'tool_version'=>$d->version->version]]);
            $checksum=hash('sha256',json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $audit=$d->audit?$this->audit->record('tool.execution.succeeded',$actor->id,$context['project_id']??null,'tool_execution',$executionRow,['tool'=>$toolCode,'version'=>$d->version->version,'checksum'=>$checksum,'usage_event_id'=>$usage->id]):null;
            DB::table('farast_tool_executions')->where('id',$executionRow)->update([
                'status'=>'succeeded','output_meta'=>json_encode($output,JSON_UNESCAPED_UNICODE),'output_checksum'=>$checksum,
                'usage_event_id'=>$usage->id,'audit_event_id'=>$audit?->id,'finished_at'=>now(),'updated_at'=>now()
            ]);
            return ['ok'=>true,'execution_id'=>$executionId,'output'=>$output];
        }catch(Throwable $e){
            if($reservation)try{$this->commerce->release($reservation);}catch(Throwable){}
            $code=$this->errorCode($e);
            $audit=$d->audit?$this->audit->record('tool.execution.failed',$actor->id,$context['project_id']??null,'tool_execution',$executionRow,['tool'=>$toolCode,'version'=>$d->version->version,'error_code'=>$code]):null;
            DB::table('farast_tool_executions')->where('id',$executionRow)->update(['status'=>'failed','error_code'=>$code,'error_message'=>$this->safeError($e),'audit_event_id'=>$audit?->id,'finished_at'=>now(),'updated_at'=>now()]);
            Log::warning('farast.tool.failed',['tool'=>$toolCode,'execution_id'=>$executionId,'error_code'=>$code]);
            throw $e;
        }
    }

    private function definition(string $code,?string $application):FarastToolDefinition
    {
        $tool=$this->registry->tool($code); if(!$tool)throw new RuntimeException('tool_not_found');
        if($application&&!$tool->applications()->where('code',$application)->exists())throw new RuntimeException('tool_application_not_allowed');
        $version=$tool->versions()->where('status','production')->latest('id')->first(); if(!$version)throw new RuntimeException('tool_version_not_production');
        return FarastToolDefinition::from($tool,$version);
    }
    private function usageQuantity(FarastToolDefinition $d,array $context):float
    {
        if(isset($context['quantity']))return max(0,(float)$context['quantity']);
        return match($d->usageMeter){'page'=>max(1,(float)($context['pages']??1)),'minute'=>max(1,(float)($context['minutes']??1)),default=>1};
    }
    private function safeMeta(array $value):array
    {
        $drop=['password','token','secret','api_key','authorization','base64','bytes_base64'];$out=[];
        foreach($value as $k=>$v){if(in_array(strtolower((string)$k),$drop,true))continue;$out[$k]=is_scalar($v)||is_null($v)?$v:(is_array($v)?['keys'=>array_keys($v)]:get_debug_type($v));}
        return $out;
    }
    private function safeError(Throwable $e):string{return mb_substr(preg_replace('/(password|token|secret|api[_-]?key)=?[^\s,;]+/i','$1=[redacted]',$e->getMessage())?:'tool_execution_failed',0,500);}
    private function errorCode(Throwable $e):string{return preg_match('/^[a-z0-9_.-]{3,100}$/',$e->getMessage())?$e->getMessage():'tool_execution_failed';}
}