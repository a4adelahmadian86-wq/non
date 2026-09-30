<?php
namespace App\Services;
use App\Models\FarastApplication;
use App\Models\FarastCapability;
use App\Models\FarastTool;
class ToolRegistry {
 public function application(string $code):?FarastApplication{return FarastApplication::where('code',$code)->where('status','active')->first();}
 public function capability(string $code):?FarastCapability{return FarastCapability::where('code',$code)->where('status','active')->first();}
 public function tool(string $code):?FarastTool{return FarastTool::with(['capability','versions'])->where('code',$code)->where('status','active')->first();}
 public function toolsForApplication(string $application):array{$app=$this->application($application);return $app?$app->tools()->where('farast_tools.status','active')->get()->all():[];}
 public function isAvailable(string $tool,?string $application=null):bool{$item=$this->tool($tool);if(!$item)return false;if($application&&!$item->applications()->where('code',$application)->exists())return false;return $item->versions()->where('status','production')->exists();}
}