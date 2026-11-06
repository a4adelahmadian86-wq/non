<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkflowEngine
{
    private const SUPPORTED_ACTIONS = ['notify', 'create_follow_up'];

    public function run(int $workflowId, array $context = []): int
    {
        $workflow = DB::table('farast_workflows')->where('id', $workflowId)->first();
        if (! $workflow || ! $workflow->enabled) {
            throw new \RuntimeException('Workflow is disabled or missing.');
        }

        $steps = json_decode($workflow->steps, true);
        if (! is_array($steps)) {
            throw new \RuntimeException('Workflow steps are invalid.');
        }

        $runId = DB::table('farast_workflow_runs')->insertGetId([
            'workflow_id' => $workflowId,
            'status' => 'running',
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::transaction(function () use ($steps, $context, $workflow): void {
                foreach ($steps as $step) {
                    if (! is_array($step)) {
                        throw new \InvalidArgumentException('Workflow step must be an object.');
                    }
                    $this->step($step, $context, (int) $workflow->user_id);
                }
            });

            DB::table('farast_workflow_runs')->where('id', $runId)->update([
                'status' => 'completed', 'finished_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            DB::table('farast_workflow_runs')->where('id', $runId)->update([
                'status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => now(), 'updated_at' => now(),
            ]);
            throw $exception;
        }

        return $runId;
    }

    /** Dispatch a domain event once; a user's workflows can only run for that user. */
    public function dispatch(string $eventType, array $context, string $idempotencyKey): int
    {
        if (! in_array($eventType, ['document.saved', 'ticket.created', 'order.paid', 'order.deposit_paid'], true)) {
            throw new \InvalidArgumentException('Unsupported workflow event.');
        }
        if (empty($context['user_id']) || ! is_numeric($context['user_id']) || ! Schema::hasTable('farast_workflow_events')) {
            return 0;
        }

        try {
            $eventId = DB::table('farast_workflow_events')->insertGetId([
                'event_type' => $eventType,
                'idempotency_key' => $idempotencyKey,
                'user_id' => (int) $context['user_id'],
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            if (DB::table('farast_workflow_events')->where('idempotency_key', $idempotencyKey)->exists()) {
                return 0;
            }
            throw $exception;
        }

        $workflows = DB::table('farast_workflows')
            ->where('user_id', (int) $context['user_id'])
            ->where('trigger_type', $eventType)
            ->where('enabled', true)
            ->orderBy('id')->get();

        $completed = 0;
        foreach ($workflows as $workflow) {
            try {
                $this->run((int) $workflow->id, $context + ['event_type' => $eventType, 'event_id' => $eventId]);
                $completed++;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        DB::table('farast_workflow_events')->where('id', $eventId)->update([
            'matched_workflows' => $workflows->count(), 'processed_at' => now(), 'updated_at' => now(),
        ]);

        return $completed;
    }

    private function step(array $step, array $context, int $ownerId): void
    {
        $action = (string) ($step['action'] ?? '');
        if (! in_array($action, self::SUPPORTED_ACTIONS, true)) {
            throw new \InvalidArgumentException('Unsupported workflow action: '.$action);
        }

        if ($action === 'notify') {
            $targetUserId = (int) ($context['user_id'] ?? $ownerId);
            if ($targetUserId !== $ownerId) {
                throw new \RuntimeException('Workflow notification target must be its owner.');
            }
            DB::table('farast_notifications')->insert([
                'user_id' => $targetUserId, 'type' => 'workflow',
                'title' => mb_substr((string) ($step['title'] ?? 'اجرای گردش‌کار'), 0, 255),
                'body' => mb_substr((string) ($step['message'] ?? 'گردش‌کار اجرا شد.'), 0, 5000),
                'data' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return;
        }

        if ($action === 'create_follow_up') {
            if (! Schema::hasTable('crm_follow_up_tasks') || empty($context['customer_id'])) {
                throw new \RuntimeException('CRM follow-up target is missing.');
            }
            $customerId = (int) $context['customer_id'];
            if ($customerId !== $ownerId) {
                throw new \RuntimeException('Workflow cannot create a follow-up for another user.');
            }
            DB::table('crm_follow_up_tasks')->insert([
                'customer_id' => $customerId, 'assigned_to' => $ownerId, 'created_by' => $ownerId,
                'title' => mb_substr((string) ($step['title'] ?? 'پیگیری خودکار'), 0, 180),
                'description' => mb_substr((string) ($step['description'] ?? ''), 0, 4000),
                'status' => 'open',
                'priority' => in_array($step['priority'] ?? 'normal', ['low', 'normal', 'high'], true) ? $step['priority'] : 'normal',
                'due_at' => ! empty($step['due_at']) ? \Illuminate\Support\Carbon::parse($step['due_at']) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
