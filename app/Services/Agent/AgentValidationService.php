<?php
namespace App\Services\Agent;
use RuntimeException;
class AgentValidationService{
 public function validatePlan(array $intent,array $plan,array $context):void{
  if(($plan['base_revision']??null)!==($context['document_revision']??null))throw new RuntimeException('agent_stale_revision');
  if(empty($plan['commands'])||!is_array($plan['commands']))throw new RuntimeException('agent_invalid_plan');
  if(($intent['risk']??'low')==='high'&&!$plan['requires_approval'])throw new RuntimeException('agent_approval_required');
  foreach($plan['commands'] as $c)if(!in_array($c['name']??'', ['InsertText','DeleteRange','FormatText','NormalizeText','right'],true))throw new RuntimeException('agent_invalid_command');
 }
 public function validateResult(array $result,array $context,array $budget=[]):void{
  if((int)($result['base_revision']??-1)!==(int)($context['document_revision']??-2))throw new RuntimeException('agent_stale_revision');
  if((int)($result['transactions']??0)>(int)($budget['max_transactions']??1))throw new RuntimeException('agent_budget_transactions');
  if((int)($result['changed_blocks']??0)>(int)($budget['max_changed_blocks']??100))throw new RuntimeException('agent_budget_blocks');
  if((int)($result['changed_characters']??0)>(int)($budget['max_changed_characters']??100000))throw new RuntimeException('agent_budget_characters');
 }
}