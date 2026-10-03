<?php

namespace App\Services;

use App\Contracts\FarastToolHandler;
use App\Models\User;
use RuntimeException;

class OcrToolHandler implements FarastToolHandler
{
    public function __construct(private GeminiService $gemini){}
    public function handle(User $actor,array $input,array $context=[]):array
    {
        $raw=base64_decode((string)$input['base64'],true);
        if($raw===false)throw new RuntimeException('invalid_base64');
        if(strlen($raw)>(int)config('farast.security.max_tool_input_bytes',50*1024*1024))throw new RuntimeException('tool_input_too_large');
        return $this->gemini->transcribe((string)$input['mime'],(string)$input['base64'],[
            'user_id'=>$actor->id,'project_id'=>$context['project_id']??null,
            'source_hash'=>$input['source_hash']??hash('sha256',$raw),'input_bytes'=>strlen($raw),
            'source_name'=>$input['source_name']??null,'processing_mode'=>'shared_tool'
        ]);
    }
}