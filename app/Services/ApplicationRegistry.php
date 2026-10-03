<?php

namespace App\Services;

use App\Models\FarastApplication;
use InvalidArgumentException;

class ApplicationRegistry
{
    public const PROJECT_TYPES=['typing'=>['application'=>'word_processor','available'=>true],'editing'=>['application'=>'word_processor','available'=>true],'article'=>['application'=>'word_processor','available'=>true],'book'=>['application'=>'word_processor','available'=>true],'resume'=>['application'=>'word_processor','available'=>true],'exam'=>['application'=>'word_processor','available'=>true],'contract'=>['application'=>'word_processor','available'=>true],'forms'=>['application'=>'word_processor','available'=>true],'report'=>['application'=>'word_processor','available'=>true],'research_document'=>['application'=>'word_processor','available'=>true],'presentation'=>['application'=>'presentation','available'=>false],'design'=>['application'=>'design','available'=>false],'poster_card'=>['application'=>'design','available'=>false],'programming'=>['application'=>'programming','available'=>false],'other'=>['application'=>'word_processor','available'=>false]];

    public function definition(string $code):?array
    {
        $app=FarastApplication::where('code',$code)->where('status','active')->first();if(!$app)return null;
        return ['application_id'=>$app->id,'code'=>$app->code,'name'=>$app->name,'workflow'=>$app->workflow,'project_types'=>$app->project_types,'tools'=>$app->tools,'capabilities'=>$app->capabilities,'templates'=>$app->templates,'agent_profile'=>$app->agent_profile,'export_formats'=>$app->export_formats,'entitlement_requirements'=>$app->entitlement_requirements];
    }
    public function classify(string $projectType):array
    {
        $type=trim($projectType);if(!isset(self::PROJECT_TYPES[$type]))throw new InvalidArgumentException('نوع پروژه پشتیبانی نمی‌شود.');
        $definition=self::PROJECT_TYPES[$type];return ['project_type'=>$type,'application'=>$definition['application'],'available'=>(bool)$definition['available']];
    }
    public function canStart(string $projectType):bool{return $this->classify($projectType)['available'];}
    public function applicationFor(string $projectType):string{return $this->classify($projectType)['application'];}
}