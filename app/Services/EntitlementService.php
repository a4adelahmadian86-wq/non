<?php
namespace App\Services;

use App\Models\FarastEntitlement;
use App\Models\FarastProject;
use App\Models\User;

class EntitlementService
{
    public function __construct(private CapabilityService $capabilities) {}

    public function authorize(User $user, string $capability, ?FarastProject $project = null): array
    {
        if ($user->isAdmin()) {
            return ['allowed' => true, 'mode' => 'administrator', 'remaining' => null, 'reason' => null];
        }

        $rows = FarastEntitlement::query()
            ->where('capability_code', $capability)
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereNull('user_id'))
            ->where(function ($q) use ($project) {
                if ($project) {
                    $q->where('project_id', $project->id)->orWhereNull('project_id');
                } else {
                    $q->whereNull('project_id');
                }
            })
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->get()
            ->sortByDesc(function (FarastEntitlement $row) use ($project) {
                return (($project && (int) $row->project_id === (int) $project->id) ? 4 : 0)
                    + (((int) $row->user_id === (int) $user->id) ? 2 : 0)
                    + ($row->mode === 'subscription' ? 1 : 0);
            });

        if ($rows->isNotEmpty()) {
            $row = $rows->first();
            $remaining = $row->quantity === null
                ? null
                : max(0, (int) $row->quantity - (int) $row->used_quantity);

            return [
                'allowed' => $remaining === null || $remaining > 0,
                'mode' => (string) $row->mode,
                'remaining' => $remaining,
                'entitlement_id' => $row->id,
                'reason' => ($remaining !== null && $remaining <= 0) ? 'quantity_exhausted' : null,
            ];
        }

        if ($this->legacyCapabilityFlag($user, $capability)) {
            return ['allowed' => true, 'mode' => 'legacy_capability', 'remaining' => null, 'reason' => null];
        }

        return ['allowed' => false, 'mode' => 'none', 'remaining' => 0, 'reason' => 'not_entitled'];
    }

    public function allows(User $user, string $capability, ?FarastProject $project = null): bool
    {
        return (bool) $this->authorize($user, $capability, $project)['allowed'];
    }

    public function consume(User $user, string $capability, int $quantity = 1, ?FarastProject $project = null): array
    {
        $quantity = max(1, $quantity);

        if ($user->isAdmin()) {
            return ['ok' => true, 'mode' => 'administrator', 'remaining' => null];
        }

        return \DB::transaction(function () use ($user, $capability, $quantity, $project) {
            $row = FarastEntitlement::query()
                ->where('capability_code', $capability)
                ->where('status', 'active')
                ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereNull('user_id'))
                ->where(function ($q) use ($project) {
                    if ($project) {
                        $q->where('project_id', $project->id)->orWhereNull('project_id');
                    } else {
                        $q->whereNull('project_id');
                    }
                })
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->orderByRaw('CASE WHEN project_id IS NULL THEN 0 ELSE 1 END DESC')
                ->orderByRaw('CASE WHEN user_id IS NULL THEN 0 ELSE 1 END DESC')
                ->lockForUpdate()
                ->first();

            if (!$row) {
                return $this->legacyCapabilityFlag($user, $capability)
                    ? ['ok' => true, 'mode' => 'legacy_capability', 'remaining' => null]
                    : ['ok' => false, 'mode' => 'none', 'remaining' => 0, 'reason' => 'not_entitled'];
            }

            if ($row->quantity !== null && ((int) $row->used_quantity + $quantity) > (int) $row->quantity) {
                return [
                    'ok' => false,
                    'mode' => $row->mode,
                    'remaining' => max(0, (int) $row->quantity - (int) $row->used_quantity),
                    'reason' => 'quantity_exhausted',
                ];
            }

            if ($row->quantity !== null) {
                $row->increment('used_quantity', $quantity);
                $remaining = max(0, (int) $row->quantity - (int) $row->used_quantity - $quantity);
            } else {
                $remaining = null;
            }

            return ['ok' => true, 'mode' => $row->mode, 'remaining' => $remaining, 'entitlement_id' => $row->id];
        });
    }

    public function grant(string $capability, ?int $userId = null, ?int $projectId = null, string $mode = 'temporary_purchase', ?int $quantity = null, ?array $metadata = null): FarastEntitlement
    {
        return FarastEntitlement::create([
            'user_id' => $userId,
            'project_id' => $projectId,
            'capability_code' => $capability,
            'mode' => $mode,
            'status' => 'active',
            'quantity' => $quantity,
            'used_quantity' => 0,
            'starts_at' => now(),
            'metadata' => $metadata,
        ]);
    }

    private function legacyCapabilityFlag(User $user, string $capability): bool
    {
        return match ($capability) {
            'document.editing' => $this->capabilities->allowed($user, 'can_type'),
            'ai.assistance', 'ai.generation', 'ai.rewriting', 'ai.correction' => $this->capabilities->allowed($user, 'can_ai'),
            'ocr', 'handwriting.ocr', 'document.intelligence' => $this->capabilities->allowed($user, 'can_ai'),
            'speech.transcription' => $this->capabilities->allowed($user, 'can_voice'),
            'feedback.submit' => $this->capabilities->allowed($user, 'can_feedback'),
            'export.docx' => $this->capabilities->allowed($user, 'can_export_docx'),
            'export.pdf' => $this->capabilities->allowed($user, 'can_export_pdf'),
            default => false,
        };
    }
}
