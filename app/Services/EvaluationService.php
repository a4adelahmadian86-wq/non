<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class EvaluationService
{
    public function createDataset(string $code,string $version,array $metadata=[]):int
    {
        return (int)DB::table('farast_evaluation_datasets')->insertGetId(['code'=>$code,'version'=>$version,'status'=>'draft','metadata'=>json_encode($metadata,JSON_UNESCAPED_UNICODE),'created_at'=>now(),'updated_at'=>now()]);
    }

    public function addCase(int $datasetId,array $input,array $expected,?int $toolId=null,?int $toolVersionId=null):int
    {
        return (int)DB::table('farast_evaluation_cases')->insertGetId(['dataset_id'=>$datasetId,'tool_id'=>$toolId,'tool_version_id'=>$toolVersionId,'input_payload'=>json_encode($input,JSON_UNESCAPED_UNICODE),'expected_payload'=>json_encode($expected,JSON_UNESCAPED_UNICODE),'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
    }

    public function recordResult(int $caseId,array $actual,float $score,array $metadata=[]):void
    {
        $score=max(0,min(1,$score));
        DB::table('farast_evaluation_cases')->where('id',$caseId)->update(['status'=>$score>=0.8?'passed':'failed','score'=>$score,'metadata'=>json_encode(array_merge($metadata,['actual'=>$actual]),JSON_UNESCAPED_UNICODE),'updated_at'=>now()]);
    }

    public function regression(int $toolVersionId):array
    {
        $current=DB::table('farast_evaluation_cases')->where('tool_version_id',$toolVersionId)->whereNotNull('score')->avg('score');
        $tool=DB::table('farast_tool_versions')->where('id',$toolVersionId)->first();
        if(!$tool)return ['regression'=>true,'current'=>null,'baseline'=>null,'delta'=>null];
        $baseline=DB::table('farast_tool_versions')->where('tool_id',$tool->tool_id)->where('id','<>',$toolVersionId)->where('status','production')->value('quality_score');
        $delta=$current===null||$baseline===null?null:(float)$current-(float)$baseline;
        return ['regression'=>$delta!==null&&$delta<-(float)config('farast.evaluation.max_regression',0.05),'current'=>$current===null?null:(float)$current,'baseline'=>$baseline===null?null:(float)$baseline,'delta'=>$delta];
    }

    public function promote(int $toolVersionId,int $reviewerId):void
    {
        $tool=DB::table('farast_tool_versions')->where('id',$toolVersionId)->first();
        if(!$tool||$tool->evaluation_status!=='approved'||$tool->review_status!=='approved')throw new RuntimeException('tool_version_not_validated');
        $regression=$this->regression($toolVersionId);
        if($regression['regression'])throw new RuntimeException('evaluation_regression_detected');
        DB::transaction(function()use($toolVersionId,$tool,$reviewerId){
            DB::table('farast_tool_versions')->where('tool_id',$tool->tool_id)->where('status','production')->update(['status'=>'retired','updated_at'=>now()]);
            DB::table('farast_tool_versions')->where('id',$toolVersionId)->update(['status'=>'production','review_status'=>'approved','last_verified_at'=>now(),'updated_at'=>now()]);
            app(AuditEventService::class)->record('tool.version.promoted',$reviewerId,null,'tool_version',$toolVersionId,['reason'=>'evaluation_gate']);
        });
    }
}