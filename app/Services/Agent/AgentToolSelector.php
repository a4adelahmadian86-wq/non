<?php
namespace App\Services\Agent;
use App\Services\ToolRegistry;use RuntimeException;
class AgentToolSelector{
 public function __construct(private ToolRegistry $registry){}
 public function select(array $intent):array{
  $code=match($intent['operation']){'format_bold','format_italic','format_underline','format_align','normalize_spelling','delete'=>'editor.kernel','rewrite'=>'ai.editor',default=>throw new RuntimeException('agent_tool_not_available')};
  $tool=$this->registry->tool($code);if(!$tool||!$this->registry->isAvailable($code,'word_processor'))throw new RuntimeException('agent_tool_not_available');$version=$tool->versions()->where('status','production')->orderByDesc('id')->first();if(!$version)throw new RuntimeException('agent_tool_version_unavailable');
  return ['code'=>$tool->code,'name'=>$tool->name,'capability'=>$tool->capability?->code,'tool_id'=>$tool->id,'version_id'=>$version->id,'version'=>$version->version,'risk_level'=>$tool->risk_level];
 }
}