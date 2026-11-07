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

    /** Dispatch a domain event once and retain failed workflow runs for safe retry. */
    public function dispatch(string $eventType, array $context, string $idempotencyKey): int
    {
        if (! in_array($eventType, ['document.saved', 'ticket.created', 'order.paid', 'order.deposit_paid'], true)) {
            throw new \\InvalidArgumentException('Unsupported workflow event.');
        }
        if (empty($context['user_id']) || ! is_numeric($context['user_id']) || ! Schema::hasTable('farast_workflow_events')) {
            return 0;
        }

        $userId = (int) $context['user_id'];

        try {
            $eventId = DB::table('farast_workflow_events')->insertGetId([
                'event_type' => $eventType,
                'idempotency_key' => $idempotencyKey,
                'user_id' => $userId,
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'pending',
                'attempts' => 0,
                'matched_workflows' => 0,
                'failed_workflows' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\\Illuminate\\Database\\QueryException $exception) {
            $existing = DB::table('farast_workflow_events')->where('idempotency_key', $idempotencyKey)->first();
            if (! $existing) {
                throw $exception;
            }
            if ((int) $existing->user_id !== $userId || $existing->event_type !== $eventType) {
                throw new \\RuntimeException('Workflow event idempotency key is already bound to another event.');
            }
            if (($existing->status ?? null) === 'completed') {
                return 0;
            }
            $eventId = (int) $existing->id;
        }

        $event = DB::table('farast_workflow_events')->where('id', $eventId)->first();
        if (! $event || ($event->status ?? null) === 'completed') {
            return 0;
        }

        // Always retry using the original immutable event payload, not a later caller's payload.
        $storedContext = json_decode((string) $event->context, true);
        if (! is_array($storedContext)) {
            throw new RuntimeException('Stored workflow event context is invalid.');
        }

        return $this->processEvent($event, $storedContext);
    }

    /**
     * Retry incomplete events from the scheduler. Completed workflow steps are
     * skipped, failed steps are retried up to five times, and active leases are
     * not claimed by another worker.
     */
    public function retryFailedEvents(int $limit = 50): int
    {
        if (! Schema::hasTable('farast_workflow_events') || ! Schema::hasTable('farast_workflow_event_workflows')) {
            return 0;
        }

        $events = DB::table('farast_workflow_events')
            ->where('status', '!=', 'completed')
            ->where('attempts', '<', 10)
            ->where(function ($query) {
                $query->whereIn('status', ['pending', 'failed'])
                    ->orWhere(function ($stale) {
                        $stale->where('status', 'processing')
                            ->where('updated_at', '<=', now()->subMinutes(30));
                    });
            })
            ->orderBy('id')
            ->limit(max(1, min($limit, 200)))
            ->get();

        $completed = 0;
        foreach ($events as $event) {
            $context = json_decode((string) $event->context, true);
            if (! is_array($context)) {
                continue;
            }
            $completed += $this->processEvent($event, $context);
        }

        return $completed;
    }

    private function processEvent(object $event, array $context): int
    {
        DB::transaction(function () use ($event): void {
            $lockedEvent = DB::table('farast_workflow_events')->where('id', $event->id)->lockForUpdate()->first();
            if (! $lockedEvent || $lockedEvent->status === 'completed') {
                return;
            }

            $hasMappings = DB::table('farast_workflow_event_workflows')
                ->where('event_id', $event->id)->exists();

            if (! $hasMappings) {
                $workflows = DB::table('farast_workflows')
                    ->where('user_id', (int) $lockedEvent->user_id)
                    ->where('trigger_type', $lockedEvent->event_type)
                    ->where('enabled', true)
                    ->orderBy('id')
                    ->get();

                foreach ($workflows as $workflow) {
                    DB::table('farast_workflow_event_workflows')->insertOrIgnore([
                        'event_id' => $event->id,
                        'workflow_id' => $workflow->id,
                        'status' => 'pending',
                        'attempts' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('farast_workflow_events')->where('id', $event->id)->update([
                'status' => 'processing',
                'attempts' => DB::raw('attempts + 1'),
                'matched_workflows' => DB::table('farast_workflow_event_workflows')->where('event_id', $event->id)->count(),
                'updated_at' => now(),
            ]);
        });

        $mappings = DB::table('farast_workflow_event_workflows')
            ->where('event_id', $event->id)
            ->orderBy('id')
            ->get();

        $completedNow = 0;
        foreach ($mappings as $mapping) {
            $claimed = DB::transaction(function () use ($mapping): bool {
                $locked = DB::table('farast_workflow_event_workflows')
                    ->where('id', $mapping->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked || $locked->status === 'completed' || (int) $locked->attempts >= 5) {
                    return false;
                }

                if ($locked->status === 'running' && $locked->started_at
                    && \\Illuminate\\Support\\Carbon::parse($locked->started_at)->gt(now()->subMinutes(30))) {
                    return false;
                }

                DB::table('farast_workflow_event_workflows')->where('id', $locked->id)->update([
                    'status' => 'running',
                    'attempts' => (int) $locked->attempts + 1,
                    'last_error' => null,
                    'started_at' => now(),
                    'finished_at' => null,
                    'updated_at' => now(),
                ]);

                return true;
            });

            if (! $claimed) {
                continue;
            }

            try {
                $this->run((int) $mapping->workflow_id, $context + [
                    'event_type' => $event->event_type,
                    'event_id' => (int) $event->id,
                ]);
                DB::table('farast_workflow_event_workflows')->where('id', $mapping->id)->update([
                    'status' => 'completed',
                    'last_error' => null,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
                $completedNow++;
            } catch (\\Throwable $exception) {
                DB::table('farast_workflow_event_workflows')->where('id', $mapping->id)->update([
                    'status' => 'failed',
                    'last_error' => mb_substr($exception->getMessage(), 0, 4000),
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
                report($exception);
            }
        }

        $total = $mappings->count();
        $done = DB::table('farast_workflow_event_workflows')->where('event_id', $event->id)->where('status', 'completed')->count();
        $failed = DB::table('farast_workflow_event_workflows')->where('event_id', $event->id)->where('status', 'failed')->count();
        $active = DB::table('farast_workflow_event_workflows')->where('event_id', $event->id)->whereIn('status', ['pending', 'running'])->count();
        $errors = DB::table('farast_workflow_event_workflows')
            ->where('event_id', $event->id)->where('status', 'failed')
            ->whereNotNull('last_error')->orderBy('id')->limit(3)->pluck('last_error')->all();

        $complete = $done === $total;
        DB::table('farast_workflow_events')->where('id', $event->id)->update([
            'status' => $complete ? 'completed' : ($active > 0 ? 'processing' : 'failed'),
            'matched_workflows' => $total,
            'failed_workflows' => $failed,
            'error_summary' => $errors ? mb_substr(implode("\\n", $errors), 0, 4000) : null,
            'processed_at' => $complete ? now() : null,
            'updated_at' => now(),
        ]);

        return $completedNow;
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
