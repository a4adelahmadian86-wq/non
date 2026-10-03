<?php

namespace App\Services;

use App\Models\FarastApplication;
use App\Models\FarastCapability;
use App\Models\FarastTool;
use App\Support\FarastToolDefinition;

class ToolRegistry
{
    public function application(string $code):?FarastApplication{return FarastApplication::where('code',$code)->where('status','active')->first();}
    public function capability(string $code):?FarastCapability{return FarastCapability::where('code',$code)->where('status','active')->first();}
    public function tool(string $code):?FarastTool{return FarastTool::with(['capability','versions'])->where('code',$code)->where('status','active')->first();}
    public function definition(string $code,?string $application=null):?FarastToolDefinition
    {
        $tool=$this->tool($code);if(!$tool)return null;
        if($application&&!$tool->applications()->where('code',$application)->exists())return null;
        $version=$tool->versions()->where('status','production')->latest('id')->first();if(!$version)return null;
        return FarastToolDefinition::from($tool,$version);
    }
    public function toolsForApplication(string $application):array{$app=$this->application($application);return $app?$app->tools()->where('farast_tools.status','active')->get()->all():[];}
    public function isAvailable(string $tool,?string $application=null):bool{return $this->definition($tool,$application)!==null;}
}