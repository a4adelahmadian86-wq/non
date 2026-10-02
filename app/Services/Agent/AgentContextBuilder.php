<?php
namespace App\Services\Agent;
use App\Models\FarastDocument;use App\Models\FarastProject;use App\Models\TypingDocument;use App\Models\User;use RuntimeException;
class AgentContextBuilder{
 public function build(User $user,TypingDocument $legacy,FarastDocument $document,array $requestContext=[]):array{
  if((int)$document->user_id!==(int)$user->id)throw new RuntimeException('agent_document_forbidden');
  $model=is_array($document->content_json)?$document->content_json:[];$this->requestPrompt=(string)($requestContext['prompt']??'');$selection=$this->selection($model,$requestContext['selection']??null);
  $project=$document->project_id?FarastProject::whereKey($document->project_id)->where('user_id',$user->id)->first():null;
  return ['user_id'=>$user->id,'organization_id'=>$requestContext['organization_id']??null,'project_id'=>$project?->id,'application'=>'word_processor','document_id'=>$document->id,'document_revision'=>(int)$document->revision,'selection'=>$selection,'current_block'=>$selection['block_id']??null,'available_tools'=>[],'permissions'=>[],'entitlement'=>[],'usage_budget'=>$requestContext['budget']??[],'task_state'=>[],'document_data'=>['language'=>$model['language']??'fa','direction'=>$model['direction']??'rtl','title'=>$document->title]];
 }
 private string $requestPrompt='';
 private function selection(array $model,mixed $raw):array{
  if(!is_array($raw)||empty($raw['block_id'])||trim((string)($raw['text']??''))===''){$prompt=(string)($this->requestPrompt??'');$blocks=[];foreach(($model['sections']??[]) as $section)foreach(($section['blocks']??[]) as $b){$text=$this->text($b,[]);if($text!=='')$blocks[]=['block'=>$b,'text'=>$text];}if(preg_match('/این متن|this text/iu',$prompt)&&count($blocks)>0){$target=$blocks[0];return ['target'=>'selection','block_id'=>(string)$target['block']['id'],'item_id'=>null,'cell_id'=>null,'start'=>0,'end'=>mb_strlen($target['text']),'text'=>$target['text']];}return ['target'=>'selection','block_id'=>null,'start'=>0,'end'=>0,'text'=>''];}
  $block=$this->findBlock($model,(string)$raw['block_id']);if(!$block)throw new RuntimeException('agent_target_not_found');$text=$this->text($block,$raw);
  $start=max(0,(int)($raw['start']??0));$end=max($start,(int)($raw['end']??$start));$end=min($end,mb_strlen($text));$start=min($start,$end);
  return ['target'=>'selection','block_id'=>(string)$block['id'],'item_id'=>isset($raw['item_id'])?(string)$raw['item_id']:null,'cell_id'=>isset($raw['cell_id'])?(string)$raw['cell_id']:null,'start'=>$start,'end'=>$end,'text'=>mb_substr($text,$start,$end-$start)];
 }
 private function findBlock(array $model,string $id):?array{foreach(($model['sections']??[]) as $s)foreach(($s['blocks']??[]) as $b)if(($b['id']??null)===$id)return $b;return null;}
 private function text(array $b,array $raw):string{
  if(!empty($raw['cell_id']))foreach(($b['rows']??[]) as $row)foreach(($row['cells']??[]) as $cell)if(($cell['id']??null)===$raw['cell_id'])return collect($cell['runs']??[])->map(fn($r)=>(string)($r['text']??''))->implode('');
  if(!empty($raw['item_id']))foreach(($b['items']??[]) as $item)if(($item['id']??null)===$raw['item_id'])return collect($item['runs']??[])->map(fn($r)=>(string)($r['text']??''))->implode('');
  return collect($b['runs']??[])->map(fn($r)=>(string)($r['text']??''))->implode('');
 }
}