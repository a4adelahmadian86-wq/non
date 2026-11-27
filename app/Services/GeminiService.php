<?php

namespace App\\Services;

use App\\Models\\AiInteraction;
use Illuminate\\Support\\Facades\\Log;
use Illuminate\\Support\\Str;
use RuntimeException;
use Throwable;

/**
 * Legacy service name retained for existing OCR callers.
 * All OCR now runs through the authenticated Product Supervisor and FARAST Worker;
 * this class does not call Google Gemini or any model provider directly.
 */
class GeminiService
{
    private const MODEL = '@cf/openai/gpt-oss-120b';
    private const POLICY_VERSION = 'farast-ocr-worker-v1';

    public function __construct(private ProductSupervisorClient $supervisor)
    {
    }

    public function transcribe(string $mime, string $base64, array $context = []): array
    {
        $raw = base64_decode($base64, true);
        if ($raw === false) {
            throw new RuntimeException('ocr_invalid_base64');
        }
        if (strlen($raw) > 25 * 1024 * 1024) {
            throw new RuntimeException('ocr_file_too_large');
        }

        $requestId = (string) Str::uuid();
        $interaction = AiInteraction::create([
            'user_id' => $context['user_id'] ?? null,
            'document_id' => $context['document_id'] ?? null,
            'provider' => 'farast-ai-gateway',
            'model' => self::MODEL,
            'operation' => 'image.ocr',
            'request_id' => $requestId,
            'source_hash' => $context['source_hash'] ?? hash('sha256', $raw),
            'prompt_hash' => hash('sha256', self::POLICY_VERSION),
            'input_bytes' => (int) ($context['input_bytes'] ?? strlen($raw)),
            'status' => 'started',
            'input_meta' => [
                'mime' => $mime,
                'source_name' => $context['source_name'] ?? null,
                'processing_mode' => $context['processing_mode'] ?? 'editor',
                'route' => 'product_supervisor_worker',
            ],
        ]);

        $started = hrtime(true);
        try {
            $result = $this->supervisor->ocr($mime, $base64, $context);
            $latency = (int) ((hrtime(true) - $started) / 1_000_000);
            $model = (string) ($result['model'] ?? self::MODEL);

            $interaction->update([
                'provider_interaction_id' => $result['request_id'] ?? null,
                'latency_ms' => $latency,
                'output_bytes' => strlen(json_encode($result, JSON_UNESCAPED_UNICODE) ?: ''),
                'status' => 'completed',
                'output_meta' => [
                    'page_count' => (int) ($result['page_count'] ?? 0),
                    'block_count' => count($result['blocks'] ?? []),
                    'rejected' => (bool) ($result['rejected'] ?? true),
                    'provider_status' => $result['provider_status'] ?? [],
                    'engine' => 'farast-ai-gateway',
                ],
            ]);

            $result['engine'] = 'farast-ai-gateway';
            $result['model'] = $model;
            $result['_ai_interaction_id'] = $interaction->id;
            $result['_request_id'] = $requestId;

            Log::info('farast.ai.ocr.completed', [
                'request_id' => $requestId,
                'interaction_id' => $interaction->id,
                'model' => $model,
                'latency_ms' => $latency,
                'page_count' => (int) ($result['page_count'] ?? 0),
                'block_count' => count($result['blocks'] ?? []),
                'rejected' => (bool) ($result['rejected'] ?? true),
            ]);

            return $result;
        } catch (Throwable $exception) {
            $message = (string) $exception->getMessage();
            $code = preg_match('/^(?:ocr|product_supervisor)_[a-z0-9_]+$/', $message)
                ? 'ocr_' . $message
                : 'ocr_processing_failure';
            $latency = (int) ((hrtime(true) - $started) / 1_000_000);

            $interaction->update([
                'latency_ms' => $latency,
                'status' => 'failed',
                'error_message' => $code,
                'output_meta' => ['error_code' => $code],
            ]);

            Log::warning('farast.ai.ocr.failed', [
                'request_id' => $requestId,
                'interaction_id' => $interaction->id,
                'model' => self::MODEL,
                'latency_ms' => $latency,
                'error_code' => $code,
            ]);

            throw new RuntimeException($code, 0, $exception);
        }
    }
}
