<?php

namespace App\Services;

use App\Models\FarastProject;
use App\Models\User;
use App\Services\AI\AiOrchestrator;
use RuntimeException;

class EditorAiAssistService
{
    public function __construct(
        private AiOrchestrator $orchestrator,
        private EntitlementService $entitlements,
        private CommerceAuthorizationService $commerce,
        private ProductKnowledgeService $productKnowledge,
    ) {}

    public function assist(string $operation, string $text, array $context = []): array
    {
        $user = $context['user'] ?? null;
        if (! $user instanceof User) throw new RuntimeException('ai_authenticated_user_required');

        $project = !empty($context['project_id'])
            ? FarastProject::whereKey((int)$context['project_id'])->where('user_id',$user->id)->first()
            : null;

        if (! empty($context['product_id'])) {
            $product = StoreProduct::query()
                ->whereKey((int) $context['product_id'])
                ->where('seller_id', $user->id)
                ->with(['category', 'tags'])
                ->first();

            if (! $product) {
                throw new RuntimeException('ai_product_context_forbidden');
            }

            $context['product_reference'] = [
                'id' => (int) $product->id,
                'type' => (string) $product->type,
                'title' => (string) $product->title,
                'short_description' => mb_substr((string) $product->short_description, 0, 1200),
                'description' => mb_substr((string) $product->description, 0, 4000),
                'category' => (string) ($product->category?->name ?? ''),
                'tags' => $product->tags->pluck('name')->take(20)->values()->all(),
            ];
            $context['document_knowledge'] = $this->productKnowledge->contextForProduct($product, 12000, false);
        }

        $idempotency = (string)($context['idempotency_key'] ?? ('ai-'.$user->id.'-'.hash('sha256',$operation.'|'.$text)));
        $reservation = $this->commerce->reserve(
            $user,
            'ai.assistance',
            1,
            ['project_id'=>$project?->id],
            [
                'policy_code'=>'ai.assistance',
                'unit'=>'request',
                'allow_payg'=>(bool)($context['allow_payg'] ?? true),
                'idempotency_key'=>$idempotency,
            ]
        );

        try {
            $result = $this->orchestrator->run($user, $operation, $text, $context);
            $this->commerce->commit($reservation, [
                'metadata'=>[
                    'operation'=>$operation,
                    'provider'=>$result['provider'] ?? null,
                    'model'=>$result['model'] ?? null,
                    'request_id'=>$result['request_id'] ?? null,
                ],
            ]);
            return $result;
        } catch (\Throwable $e) {
            try { $this->commerce->release($reservation); } catch (\Throwable) {}
            throw $e;
        }
    }
}
