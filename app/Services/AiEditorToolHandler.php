<?php

namespace App\Services;

use App\Contracts\FarastToolHandler;
use App\Models\User;

class AiEditorToolHandler implements FarastToolHandler
{
    public function __construct(private EditorAiAssistService $ai){}
    public function handle(User $actor,array $input,array $context=[]):array
    {
        return $this->ai->assist((string)$input['operation'],(string)$input['text'],array_merge($context,['user'=>$actor]));
    }
}