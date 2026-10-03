<?php

namespace App\Services;

use App\Contracts\FarastToolHandler;
use RuntimeException;

class FarastToolHandlerRegistry
{
    public function handler(string $code):FarastToolHandler
    {
        return match($code){
            'ai.editor'=>app(AiEditorToolHandler::class),
            'ocr.document','ocr.handwriting'=>app(OcrToolHandler::class),
            'speech.transcription'=>app(SpeechToolHandler::class),
            default=>throw new RuntimeException('tool_handler_not_registered'),
        };
    }
}