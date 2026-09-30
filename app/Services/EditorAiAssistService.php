<?php

namespace App\Services;

use App\Models\User;
use App\Models\FarastProject;
use App\Services\AI\AiOrchestrator;
use RuntimeException;

class EditorAiAssistService
{
    public function __construct(private AiOrchestrator $orchestrator, private EntitlementService $entitlements) {}
    public function assist(string $operation, string $text, array $context = []): array
    {
        $user = $context['user'] ?? null;
        if (! $user instanceof User) throw new RuntimeException('ai_authenticated_user_required');
        $project = !empty($context['project_id']) ? FarastProject::whereKey((int) $context['project_id'])->where('user_id',$user->id)->first() : null;
        if (! $this->entitlements->allows($user, 'ai.assistance', $project)) throw new RuntimeException('ai_capability_not_entitled');
        return $this->orchestrator->run($user, $operation, $text, $context);
    }
}
