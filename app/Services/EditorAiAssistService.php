<?php

namespace App\Services;

use App\Models\User;
use App\Services\AI\AiOrchestrator;
use RuntimeException;

class EditorAiAssistService
{
    public function __construct(private AiOrchestrator $orchestrator) {}
    public function assist(string $operation, string $text, array $context = []): array
    {
        $user = $context['user'] ?? null;
        if (! $user instanceof User) throw new RuntimeException('ai_authenticated_user_required');
        return $this->orchestrator->run($user, $operation, $text, $context);
    }
}
