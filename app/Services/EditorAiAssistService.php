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
    ) {}

    public function assist(string $operation, string $text, array $context = []): array
    {
        $user = $context['user'] ?? null;
        if (! $user instanceof User) throw new RuntimeException('ai_authenticated_user_required');

        $project = !empty($context['project_id'])
            ? FarastProject::whereKey((int)$context['project_id'])->where('user_id',$user->id)->first()
            : null;

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
