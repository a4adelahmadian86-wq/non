<?php

namespace App\Services;

use App\Contracts\FarastToolHandler;
use App\Models\User;
use RuntimeException;

class SpeechToolHandler implements FarastToolHandler
{
    public function __construct(private VoiceTranscriptionService $voice){}
    public function handle(User $actor,array $input,array $context=[]):array
    {
        $raw=base64_decode((string)$input['bytes_base64'],true);
        if($raw===false)throw new RuntimeException('invalid_base64');
        if(strlen($raw)>(int)config('farast.security.max_tool_input_bytes',50*1024*1024))throw new RuntimeException('tool_input_too_large');
        return $this->voice->transcribe((string)$input['mime'],$raw,(string)$input['locale'],[
            'user_id'=>$actor->id,'processing_mode'=>'shared_tool','input_bytes'=>strlen($raw)
        ]);
    }
}