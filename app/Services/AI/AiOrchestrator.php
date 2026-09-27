<?php

namespace App\Services\AI;

use App\Models\AiInteraction;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class AiOrchestrator
{
    public function __construct(
        private AiOperationRegistry $operations,
        private AiContextEngine $contextEngine,
        private AiPrivacyPolicy $privacy,
        private AiQuotaService $quota,
        private AiProviderRegistry $providers,
    ) {}

    public function run(User $user, string $operationName, string $text, array $context = []): array
    {
        $operation = $this->operations->resolve($operationName);
        $this->quota->assertAllowed($user, 'can_ai');
        $mode = $this->privacy->resolve($context['processing_mode'] ?? null, $operation['processing_modes']);
        $payload = $this->contextEngine->build($operation, $text, $context);

        if ($mode !== 'external') {
            throw new RuntimeException('ai_provider_unavailable');
        }

        $requestId = (string) Str::uuid();
        $providerName = 'unresolved';
        $interaction = AiInteraction::create([
            'user_id' => $user->id,
            'document_id' => $context['document_id'] ?? null,
            'provider' => $providerName,
            'model' => 'pending',
            'operation' => $operation['name'],
            'request_id' => $requestId,
            'source_hash' => hash('sha256', $text),
            'input_bytes' => $payload['transmitted_bytes'],
            'status' => 'started',
            'input_meta' => [
                'scope' => $payload['scope'],
                'processing_mode' => $mode,
                'cost_class' => $operation['cost'],
                'legacy_operation' => $operation['requested_name'] !== $operation['name'] ? $operation['requested_name'] : null,
            ],
        ]);
        $started = hrtime(true);

        try {
            $provider = $this->providers->forOperation($operation, $context['provider'] ?? null);
            $providerName = $provider->name();
            $interaction->update(['provider' => $providerName]);

            $model = $provider->model();
            if ($model === null || $model === '') {
                throw new RuntimeException('ai_provider_model_unavailable');
            }
            $interaction->update(['model' => $model]);

            $normalized = $provider->execute(['operation' => $operation, 'payload' => $payload, 'request_id' => $requestId]);
            $latency = (int) ((hrtime(true) - $started) / 1_000_000);
            $result = $normalized->result;
            $resolvedModel = $normalized->model ?: $model;
            $interaction->update([
                'provider_interaction_id' => $normalized->providerRequestId,
                'model' => $resolvedModel,
                'prompt_hash' => $normalized->metadata['prompt_hash'] ?? null,
                'latency_ms' => $latency,
                'output_bytes' => (int) ($normalized->metadata['output_bytes'] ?? 0),
                'status' => 'completed',
                'output_meta' => [
                    'http_status' => $normalized->metadata['http_status'] ?? null,
                    'processing_mode' => $mode,
                    'result_type' => $operation['result'],
                ],
            ]);
            Log::info('farast.ai.completed', ['request_id' => $requestId, 'interaction_id' => $interaction->id, 'operation' => $operation['name'], 'provider' => $providerName, 'model' => $resolvedModel, 'latency_ms' => $latency]);
            $contract = [
                'request_id' => $requestId,
                'operation' => $operation['name'],
                'provider' => $providerName,
                'model' => $resolvedModel,
                'processing_mode' => $mode,
                'status' => 'completed',
                'result' => $result,
                'suggestions' => $result['suggestions'] ?? [],
                'warnings' => [],
                'metadata' => [
                    'interaction_id' => $interaction->id,
                    'context_scope' => $payload['scope'],
                    'transmitted_bytes' => $payload['transmitted_bytes'],
                    'cost_class' => $operation['cost'],
                ],
                'usage' => $normalized->usage,
                'error' => null,
            ];

            return array_merge($result, ['interaction_id' => $interaction->id, 'request_id' => $requestId, 'ai' => $contract]);
        } catch (\Throwable $e) {
            $latency = (int) ((hrtime(true) - $started) / 1_000_000);
            $code = preg_match('/^ai_[a-z0-9_]+$/', $e->getMessage()) ? $e->getMessage() : 'ai_provider_failure';
            $interaction->update([
                'provider' => $providerName,
                'latency_ms' => $latency,
                'status' => 'failed',
                'error_message' => $code,
                'output_meta' => ['processing_mode' => $mode, 'error_code' => $code],
            ]);
            Log::warning('farast.ai.failed', ['request_id' => $requestId, 'interaction_id' => $interaction->id, 'operation' => $operation['name'], 'provider' => $providerName, 'error_code' => $code, 'latency_ms' => $latency]);
            if ($e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new RuntimeException($code, 0, $e);
        }
    }
}
