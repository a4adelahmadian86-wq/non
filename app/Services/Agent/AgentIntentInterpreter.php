<?php
namespace App\Services\Agent;
use RuntimeException;
class AgentIntentInterpreter{
 public function interpret(string $prompt,array $context):array{
  $p=trim($prompt);if($p==='')throw new RuntimeException('agent_empty_intent');$risk='low';$op=null;$command=null;$tone=null;
  if(preg_match('/\b(حذف|پاک|بردار|delete|remove|erase)\b/iu',$p)){$op='delete';$risk='high';$command='DeleteRange';}
  elseif(preg_match('/(رسمی|رسمی‌تر|اداری|formal)/iu',$p)||preg_match('/(بازنویسی|rewrite|rephrase)/iu',$p)){$op='rewrite';$risk='medium';$command='InsertText';$tone=preg_match('/رسمی|formal/iu',$p)?'formal':null;}
  elseif(preg_match('/(املاء|املا|نگارشی|غلط|spell|proofread)/iu',$p)){$op='normalize_spelling';$command='NormalizeText';}
  elseif(preg_match('/(پررنگ|بولد|bold)/iu',$p)){$op='format_bold';$command='FormatText';}
  elseif(preg_match('/(کج|ایتالیک|italic)/iu',$p)){$op='format_italic';$command='FormatText';}
  elseif(preg_match('/(زیرخط|underline)/iu',$p)){$op='format_underline';$command='FormatText';}
  elseif(preg_match('/(راست.?چین|right align)/iu',$p)){$op='format_align';$command='right';}
  else throw new RuntimeException('agent_intent_not_supported');
  if(($context['selection']['text']??'')===''&&in_array($op,['rewrite','normalize_spelling','format_bold','format_italic','format_underline','format_align','delete'],true))throw new RuntimeException('agent_selection_required');
  if((($context['selection']['end']??0)-($context['selection']['start']??0))>20000)$risk='high';
  if(preg_match('/(کل سند|تمام سند|سراسر سند|whole document|entire document)/iu',$p))$risk='high';
  return ['operation'=>$op,'target'=>'selection','tone'=>$tone,'risk'=>$risk,'reversible'=>true,'requires_preview'=>true,'requires_approval'=>$risk==='high','prompt'=>$p,'command'=>$command];
 }
}