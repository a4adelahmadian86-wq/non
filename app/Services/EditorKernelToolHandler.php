<?php

namespace App\Services;

use App\Contracts\FarastToolHandler;
use App\Models\FarastDocument;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class EditorKernelToolHandler implements FarastToolHandler
{
    public function __construct(private EditorDocumentService $documents) {}

    public function handle(User $actor, array $input, array $context = []): array
    {
        $document = FarastDocument::whereKey((int) $input['document_id'])->where('user_id', $actor->id)->firstOrFail();
        $expected = (int) ($input['base_revision'] ?? -1);
        $command = $input['command'] ?? [];
        $source = (string) ($context['source'] ?? 'ai-agent');
        $model = is_array($document->content_json) ? $document->content_json : [];
        if ((int) $document->revision !== $expected) {
            if ($source !== 'voice' || !is_array($command['input']['anchor'] ?? null)) {
                throw new RuntimeException('document_revision_conflict');
            }
            $command['input']['range'] = $this->rebaseVoiceRange($model, (array) $command['input']['anchor'], (array) ($command['input']['range'] ?? []));
            $expected = (int) $document->revision;
        }

        $model = is_array($document->content_json) ? $document->content_json : [];
        $name = (string) ($command['name'] ?? '');
        $value = is_array($command['input'] ?? null) ? $command['input'] : [];
        $model = is_array($document->content_json) ? $document->content_json : [];

        $effects = match ($name) {
            'InsertText' => $this->replaceRange($model, $value['range'] ?? null, (string) ($value['text'] ?? '')),
            'DeleteRange' => $this->replaceRange($model, $value['range'] ?? null, ''),
            'NormalizeText' => $this->replaceRange($model, $value['range'] ?? null, $this->normalizePersian((string) ($value['text'] ?? ''))),
            'FormatText' => $this->formatRange($model, $value['range'] ?? null, (array) ($value['patch'] ?? [])),
            'SetParagraphAlignment' => $this->setParagraphAlignment($model, $value),
            'SetDirection' => $this->setParagraphDirection($model, $value),
            default => throw new RuntimeException('editor_command_not_supported'),
        };

        $saved = $this->documents->saveCanonicalModel($document, $model, $source, $expected);

        return [
            'document_id' => $saved['document_id'],
            'revision' => $saved['revision'],
            'transaction_id' => (string) ($context['transaction_id'] ?? Str::uuid()),
            'command' => $name,
            'effects' => $effects,
            'document_model' => $saved['document_model'] ?? null,
        ];
    }

    private function rebaseVoiceRange(array $model, array $anchor, array $fallback): array
    {
        $blockId = (string) ($anchor['blockId'] ?? $fallback['start']['blockId'] ?? '');
        $block = $this->findBlockRef($model, $blockId);
        if (!$block) throw new RuntimeException('voice_anchor_not_found');

        $runs = $block['runs'] ?? [];
        if (isset($anchor['itemId'])) {
            foreach (($block['items'] ?? []) as $item) {
                if ((string) ($item['id'] ?? '') === (string) $anchor['itemId']) {
                    $runs = $item['runs'] ?? [];
                    break;
                }
            }
        } elseif (isset($anchor['cellId'])) {
            foreach (($block['rows'] ?? []) as $row) foreach (($row['cells'] ?? []) as $cell) {
                if ((string) ($cell['id'] ?? '') === (string) $anchor['cellId']) {
                    $runs = $cell['runs'] ?? [];
                    break 2;
                }
            }
        }

        $text = $this->runsText($runs);
        $offset = max(0, min(mb_strlen($text), (int) ($anchor['offset'] ?? $fallback['start']['offset'] ?? 0)));
        $before = mb_substr((string) ($anchor['before'] ?? ''), -48);
        $after = mb_substr((string) ($anchor['after'] ?? ''), 0, 48);

        if ($before !== '' && $after !== '') {
            $beforePos = mb_strpos($text, $before);
            $afterPos = mb_strpos($text, $after, $beforePos === false ? 0 : $beforePos + mb_strlen($before));
            if ($beforePos !== false && $afterPos !== false) {
                $candidate = $beforePos + mb_strlen($before);
                if ($afterPos >= $candidate) $offset = $afterPos;
                else throw new RuntimeException('voice_anchor_conflict');
            } else {
                throw new RuntimeException('voice_anchor_conflict');
            }
        } elseif ($after !== '') {
            $candidate = mb_strpos($text, $after);
            if ($candidate === false) throw new RuntimeException('voice_anchor_conflict');
            $offset = $candidate;
        } elseif ($before !== '') {
            $candidate = mb_strpos($text, $before);
            if ($candidate === false) throw new RuntimeException('voice_anchor_conflict');
            $offset = $candidate + mb_strlen($before);
        }

        $point = ['blockId' => $blockId, 'offset' => $offset];
        foreach (['itemId','cellId'] as $key) if (isset($anchor[$key])) $point[$key] = (string) $anchor[$key];
        return ['start' => $point, 'end' => $point];
    }

    private function replaceRange(array &$model, ?array $range, string $text): array
    {
        $startPoint = $range['start'] ?? [];
        $endPoint = $range['end'] ?? $startPoint;
        $blockId = (string) ($startPoint['blockId'] ?? '');
        if ($blockId === '') throw new RuntimeException('agent_target_not_found');

        $runs =& $this->runsRefForPoint($model, $startPoint);
        if (!is_array($runs)) throw new RuntimeException('agent_target_not_found');

        $start = max(0, (int) ($startPoint['offset'] ?? 0));
        $end = max($start, (int) ($endPoint['offset'] ?? $start));
        $current = $this->runsText($runs);
        $start = min($start, mb_strlen($current));
        $end = min($end, mb_strlen($current));

        $before = $this->sliceRuns($runs, 0, $start);
        $after = $this->sliceRuns($runs, $end, mb_strlen($current));
        $normalizedText = $this->normalizePersian($text);
        $insert = $normalizedText !== '' ? [['id' => (string) Str::uuid(), 'text' => $normalizedText]] : [];
        $runs = array_values(array_filter(array_merge($before, $insert, $after), fn ($run) => ($run['text'] ?? '') !== ''));
        if (!$runs) $runs = [['id' => (string) Str::uuid(), 'text' => '']];

        return ['changed_blocks' => 1, 'changed_characters' => abs(mb_strlen($normalizedText) - ($end - $start)), 'before_characters' => mb_strlen($current), 'after_characters' => mb_strlen($this->runsText($runs)), 'block_id' => $blockId];
    }

    private function &runsRefForPoint(array &$model, array $point): mixed
    {
        $blockId = (string) ($point['blockId'] ?? '');
        foreach (($model['sections'] ?? []) as &$section) {
            foreach (($section['blocks'] ?? []) as &$block) {
                if ((string) ($block['id'] ?? '') !== $blockId) continue;
                if (isset($point['cellId'])) {
                    foreach (($block['rows'] ?? []) as &$row) foreach (($row['cells'] ?? []) as &$cell) {
                        if ((string) ($cell['id'] ?? '') === (string) $point['cellId']) return $cell['runs'];
                    }
                    $null = null; return $null;
                }
                if (isset($point['itemId'])) {
                    foreach (($block['items'] ?? []) as &$item) if ((string) ($item['id'] ?? '') === (string) $point['itemId']) return $item['runs'];
                    $null = null; return $null;
                }
                return $block['runs'];
            }
        }
        $null = null; return $null;
    }

    private function formatRange(array &$model, ?array $range, array $patch): array
    {
        $blockId=(string)($range['start']['blockId']??'');
        if($blockId==='')throw new RuntimeException('agent_target_not_found');

        foreach(($model['sections']??[]) as $sectionIndex=>$section){
            foreach(($section['blocks']??[]) as $blockIndex=>$block){
                if((string)($block['id']??'')!==$blockId)continue;

                $runs=$block['runs']??[];
                if(isset($range['start']['cellId'])){
                    $cellId=(string)$range['start']['cellId']; $found=false;
                    foreach(($block['rows']??[]) as $rowIndex=>$row){
                        foreach(($row['cells']??[]) as $cellIndex=>$cell){
                            if((string)($cell['id']??'')===$cellId){
                                $runs=$cell['runs']??[];
                                $start=max(0,(int)($range['start']['offset']??0)); $end=max($start,(int)($range['end']['offset']??$start));
                                $runs=$this->formatRuns($runs,$start,$end,$patch);
                                $model['sections'][$sectionIndex]['blocks'][$blockIndex]['rows'][$rowIndex]['cells'][$cellIndex]['runs']=$runs;
                                return ['changed_blocks'=>1,'changed_characters'=>0,'block_id'=>$blockId];
                            }
                        }
                    }
                    throw new RuntimeException('agent_target_not_found');
                }

                if(isset($range['start']['itemId'])){
                    $itemId=(string)$range['start']['itemId'];
                    foreach(($block['items']??[]) as $itemIndex=>$item){
                        if((string)($item['id']??'')===$itemId){
                            $runs=$item['runs']??[];
                            $start=max(0,(int)($range['start']['offset']??0)); $end=max($start,(int)($range['end']['offset']??$start));
                            $runs=$this->formatRuns($runs,$start,$end,$patch);
                            $model['sections'][$sectionIndex]['blocks'][$blockIndex]['items'][$itemIndex]['runs']=$runs;
                            return ['changed_blocks'=>1,'changed_characters'=>0,'block_id'=>$blockId];
                        }
                    }
                    throw new RuntimeException('agent_target_not_found');
                }

                $start=max(0,(int)($range['start']['offset']??0)); $end=max($start,(int)($range['end']['offset']??$start));
                $model['sections'][$sectionIndex]['blocks'][$blockIndex]['runs']=$this->formatRuns($runs,$start,$end,$patch);
                return ['changed_blocks'=>1,'changed_characters'=>0,'block_id'=>$blockId];
            }
        }
        throw new RuntimeException('agent_target_not_found');
    }

    private function setParagraphAlignment(array &$model,array $input):array
    {
        $id=(string)($input['position']['blockId']??''); $block=&$this->findBlockRef($model,$id);
        if(!$block)throw new RuntimeException('agent_target_not_found');
        $alignment=(string)($input['alignment']??'right');
        if(!in_array($alignment,['left','center','right','justify'],true))throw new RuntimeException('invalid_alignment');
        $block['alignment']=$alignment;
        return ['changed_blocks'=>1,'changed_characters'=>0,'block_id'=>$id];
    }

    private function setParagraphDirection(array &$model,array $input):array
    {
        $id=(string)($input['position']['blockId']??''); $block=&$this->findBlockRef($model,$id);
        if(!$block)throw new RuntimeException('agent_target_not_found');
        $block['direction']=($input['direction']??'rtl')==='ltr'?'ltr':'rtl';
        return ['changed_blocks'=>1,'changed_characters'=>0,'block_id'=>$id];
    }

    private function &findBlockRef(array &$model,string $id):mixed
    {
        foreach(($model['sections']??[]) as &$section)foreach(($section['blocks']??[]) as &$block)if((string)($block['id']??'')===$id)return $block;
        $null=null; return $null;
    }

    private function runsText(array $runs):string{return implode('',array_map(fn($run)=>(string)($run['text']??''),$runs));}

    private function sliceRuns(array $runs,int $start,int $end):array
    {
        $out=[];$pos=0;
        foreach($runs as $run){$text=(string)($run['text']??'');$next=$pos+mb_strlen($text);$a=max(0,$start-$pos);$b=min(mb_strlen($text),$end-$pos);
            if($b>$a){$copy=$run;$copy['id']=(string)Str::uuid();$copy['text']=mb_substr($text,$a,$b-$a);$out[]=$copy;}
            $pos=$next;if($pos>=$end)break;
        } return $out;
    }

    private function formatRuns(array $runs,int $start,int $end,array $patch):array
    {
        $out=[];$pos=0;$allowed=['bold','italic','underline','strike','fontFamily','fontSize','color','highlight','direction','language','href'];
        foreach($runs as $run){$text=(string)($run['text']??'');$next=$pos+mb_strlen($text);
            if($next<=$start||$pos>=$end)$out[]=$run;
            else{$a=max(0,$start-$pos);$b=min(mb_strlen($text),$end-$pos);
                if($a>0){$left=$run;$left['id']=(string)Str::uuid();$left['text']=mb_substr($text,0,$a);$out[]=$left;}
                $hit=$run;$hit['id']=(string)Str::uuid();$hit['text']=mb_substr($text,$a,$b-$a);
                foreach($patch as $key=>$value)if(in_array($key,$allowed,true))$hit[$key]=$value;$out[]=$hit;
                if($b<mb_strlen($text)){$right=$run;$right['id']=(string)Str::uuid();$right['text']=mb_substr($text,$b);$out[]=$right;}
            }$pos=$next;
        } return $out?:[['id'=>(string)Str::uuid(),'text'=>'']];
    }

    private function normalizePersian(string $text):string
    {
        return str_replace(['ي','ى','ك','ۀ','ـ'],['ی','ی','ک','هٔ',''],preg_replace('/\x{200c}{2,}/u','\x{200c}',$text)??$text);
    }
}
