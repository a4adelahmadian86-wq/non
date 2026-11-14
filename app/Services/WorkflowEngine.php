<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkflowEngine
{
    private const SUPPORTED_ACTIONS = ['notify', 'create_follow_up'];

    public function run(int $workflowId, array $context = [], ?int $eventWorkflowId = null): int
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
            DB::transaction(function () use ($steps, $context, $workflow, $runId, $eventWorkflowId): void {
                foreach ($steps as $stepIndex => $step) {
                    if (! is_array($step)) {
                        throw new \InvalidArgumentException('Workflow step must be an object.');
                    }
                    $this->step($step, $context, (int) $workflow->user_id, $runId, (int) $stepIndex);
                }

                // Commit side effects and the event-workflow completion marker together.
                if ($eventWorkflowId !== null) {
                    $updated = DB::table('farast_workflow_event_workflows')
                        ->where('id', $eventWorkflowId)
                        ->where('status', 'running')
                        ->update([
                            'status' => 'completed',
                            'last_error' => null,
                            'finished_at' => now(),
                            'updated_at' => now(),
                        ]);

                    if ($updated !== 1) {
                        throw new \RuntimeException('Workflow event lease was lost before commit.');
                    }
                }

                DB::table('farast_workflow_runs')->where('id', $runId)->update([
                    'status' => 'completed', 'finished_at' => now(), 'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            DB::table('farast_workflow_runs')->where('id', $runId)->update([
                'status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => now(), 'updated_at' => now(),
            ]);
            throw $exception;
        }

        return $runId;
    }

    /**
     * Persist a domain event inside the caller's business transaction.
     * The scheduled retry worker processes this durable outbox after commit.
     */
    public function enqueue(string $eventType, array $context, string $idempotencyKey): int
    {
        if (! in_array($eventType, ['document.saved', 'ticket.created', 'order.paid', 'order.deposit_paid'], true)) {
            throw new \InvalidArgumentException('Unsupported workflow event.');
        }
        if (blank($idempotencyKey)) {
            throw new \InvalidArgumentException('Workflow event idempotency key is required.');
        }
        if (empty($context['user_id']) || ! is_numeric($context['user_id'])) {
            return 0;
        }
        if (! Schema::hasTable('farast_workflow_events') || ! Schema::hasTable('farast_workflow_event_workflows')) {
            throw new \RuntimeException('Workflow outbox storage is not installed.');
        }

        $userId = (int) $context['user_id'];
        $canonicalContext = $this->canonicalPayload($context);

        DB::table('farast_workflow_events')->insertOrIgnore([
            'event_type' => $eventType,
            'idempotency_key' => $idempotencyKey,
            'user_id' => $userId,
            'context' => $canonicalContext,
            'status' => 'pending',
            'attempts' => 0,
            'matched_workflows' => 0,
            'failed_workflows' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = DB::table('farast_workflow_events')
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if (! $event) {
            throw new \RuntimeException('Workflow event could not be persisted to the outbox.');
        }

        $storedContext = json_decode((string) $event->context, true);
        if (
            (int) $event->user_id !== $userId
            || $event->event_type !== $eventType
            || ! is_array($storedContext)
            || ! hash_equals($this->canonicalPayload($storedContext), $canonicalContext)
        ) {
            throw new \RuntimeException('Workflow event idempotency key is already bound to another event payload.');
        }

        return $event->status === 'completed' ? 0 : (int) $event->id;
    }

    /**
     * Canonical payload and idempotency identity for every paid-order transition.
     * Wallet and gateway paths must use this method so they cannot drift apart.
     */
    public function enqueuePaidOrder(Order $order): int
    {
        if ($order->status === 'deposit_paid' && $order->paid_at !== null) {
            $eventType = 'order.deposit_paid';
        } elseif ($order->isPaid()) {
            $eventType = 'order.paid';
        } else {
            throw new \InvalidArgumentException('Only a settled order can emit a paid-order workflow event.');
        }

        return $this->enqueue(
            $eventType,
            [
                'user_id' => (int) $order->user_id,
                'customer_id' => (int) $order->user_id,
                'order_id' => (int) $order->id,
                'amount_rials' => (int) $order->total_rials,
                'order_kind' => (string) ($order->pricing_snapshot['kind'] ?? 'unknown'),
            ],
            'order:'.$order->id.':'.$eventType,
        );
    }

    /** Canonical creation event for a customer-owned support ticket. */
    public function enqueueTicketCreated(Ticket $ticket): int
    {
        if (! $ticket->user_id) {
            return 0;
        }

        return $this->enqueue(
            'ticket.created',
            [
                'user_id' => (int) $ticket->user_id,
                'customer_id' => (int) $ticket->user_id,
                'ticket_id' => (int) $ticket->id,
                'ticket_code' => (string) $ticket->ticket_code,
                'status' => (string) $ticket->status,
            ],
            'ticket:'.$ticket->id.':created',
        );
    }

    /** Dispatch synchronously for explicit callers; business transactions should use enqueue(). */
    public function dispatch(string $eventType, array $context, string $idempotencyKey): int
    {
        $eventId = $this->enqueue($eventType, $context, $idempotencyKey);
        if ($eventId === 0) {
            return 0;
        }

        $event = DB::table('farast_workflow_events')->where('id', $eventId)->first();
        if (! $event || $event->status === 'completed') {
            return 0;
        }

        $storedContext = json_decode((string) $event->context, true);
        if (! is_array($storedContext)) {
            throw new \RuntimeException('Stored workflow event context is invalid.');
        }

        return $this->processEvent($event, $storedContext);
    }

    private function canonicalPayload(array $payload): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (! array_is_list($value)) {
                ksort($value);
            }

            foreach ($value as $key => $item) {
                $value[$key] = $normalize($item);
            }

            return $value;
        };

        return json_encode(
            $normalize($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
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
            ->where(function ($query) {
                $query->whereNotExists(function ($empty) {
                    $empty->select(DB::raw(1))->from('farast_workflow_event_workflows')
                        ->whereColumn('farast_workflow_event_workflows.event_id', 'farast_workflow_events.id');
                })->orWhereExists(function ($retryable) {
                    $retryable->select(DB::raw(1))->from('farast_workflow_event_workflows')
                        ->whereColumn('farast_workflow_event_workflows.event_id', 'farast_workflow_events.id')
                        ->where('attempts', '<', 5)
                        ->where(function ($states) {
                            $states->whereIn('status', ['pending', 'failed'])
                                ->orWhere(function ($stale) {
                                    $stale->where('status', 'running')
                                        ->where('started_at', '<=', now()->subMinutes(30));
                                });
                        });
                })->orWhereNotExists(function ($unfinished) {
                    $unfinished->select(DB::raw(1))->from('farast_workflow_event_workflows')
                        ->whereColumn('farast_workflow_event_workflows.event_id', 'farast_workflow_events.id')
                        ->where('status', '!=', 'completed');
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
                    && \Illuminate\Support\Carbon::parse($locked->started_at)->gt(now()->subMinutes(30))) {
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
                $this->run((int) $mapping->workflow_id, array_merge($context, [
                    'event_type' => $event->event_type,
                    'event_id' => (int) $event->id,
                ]), (int) $mapping->id);
                $completedNow++;
            } catch (\Throwable $exception) {
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
            'error_summary' => $errors ? mb_substr(implode("\n", $errors), 0, 4000) : null,
            'processed_at' => $complete ? now() : null,
            'updated_at' => now(),
        ]);

        return $completedNow;
    }

    private function step(array $step, array $context, int $ownerId, int $runId, int $stepIndex): void
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
            $notification = app(NotificationService::class)->push(
                $targetUserId,
                'workflow',
                mb_substr((string) ($step['title'] ?? 'اجرای گردش‌کار'), 0, 180),
                mb_substr((string) ($step['message'] ?? 'گردش‌کار اجرا شد.'), 0, 5000),
                ['url' => route('platform.workflows'), 'workflow_run_id' => $runId, 'step_index' => $stepIndex],
                true,
                'workflow:run:'.$runId.':step:'.$stepIndex.':user:'.$targetUserId,
            );
            if (! $notification) {
                throw new \RuntimeException('ثبت اعلان گردش‌کار انجام نشد.');
            }
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
