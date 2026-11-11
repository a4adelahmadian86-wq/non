<?php

namespace App\Services;

use App\Jobs\ProcessWorkflowEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkflowEngine
{
    private const SUPPORTED_ACTIONS = ['notify', 'create_follow_up'];

    /**
     * Trusted internal event contracts. Required identifiers and business values
     * are validated before the immutable payload enters the transactional outbox.
     */
    private const EVENT_CONTRACTS = [
        'document.saved' => [
            'positive_integers' => ['document_id', 'revision'],
        ],
        'ticket.created' => [
            'positive_integers' => ['ticket_id'],
            'non_empty_strings' => ['ticket_code'],
        ],
        'order.paid' => [
            'positive_integers' => ['order_id'],
            'non_negative_integers' => ['amount_rials'],
            'non_empty_strings' => ['order_kind'],
            'status' => 'paid',
        ],
        'order.deposit_paid' => [
            'positive_integers' => ['order_id'],
            'non_negative_integers' => ['amount_rials'],
            'non_empty_strings' => ['order_kind'],
            'status' => 'deposit_paid',
        ],
    ];

    private function validateEventContract(string $eventType, array $context): void
    {
        $contract = self::EVENT_CONTRACTS[$eventType] ?? null;
        if ($contract === null) {
            throw new \InvalidArgumentException('Unsupported workflow event.');
        }

        foreach ($contract['positive_integers'] ?? [] as $field) {
            $value = filter_var($context[$field] ?? null, FILTER_VALIDATE_INT);
            if ($value === false || $value < 1) {
                throw new \InvalidArgumentException('Workflow event requires a positive integer '.$field.'.');
            }
        }

        foreach ($contract['non_negative_integers'] ?? [] as $field) {
            $value = filter_var($context[$field] ?? null, FILTER_VALIDATE_INT);
            if ($value === false || $value < 0) {
                throw new \InvalidArgumentException('Workflow event requires a non-negative integer '.$field.'.');
            }
        }

        foreach ($contract['non_empty_strings'] ?? [] as $field) {
            $value = $context[$field] ?? null;
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 190) {
                throw new \InvalidArgumentException('Workflow event requires a valid '.$field.'.');
            }
        }

        if (isset($contract['status']) && ($context['status'] ?? null) !== $contract['status']) {
            throw new \InvalidArgumentException('Workflow event status does not match its contract.');
        }
    }

    public function run(int $workflowId, array $context = [], ?int $eventWorkflowId = null, bool $manual = false, ?string $eventWorkflowLeaseToken = null): int
    {
        // Register the run and bind it to the current event lease atomically.
        // A hard worker exit can then be recovered without leaving an untraceable run.
        $runId = DB::transaction(function () use ($workflowId, $context, $eventWorkflowId, $eventWorkflowLeaseToken): int {
            $runId = (int) DB::table('farast_workflow_runs')->insertGetId([
                'workflow_id' => $workflowId,
                'status' => 'running',
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($eventWorkflowId !== null) {
                if (! $eventWorkflowLeaseToken) {
                    throw new \RuntimeException('Workflow event lease token is missing.');
                }

                $linked = DB::table('farast_workflow_event_workflows')
                    ->where('id', $eventWorkflowId)
                    ->where('workflow_id', $workflowId)
                    ->where('status', 'running')
                    ->where('lease_token', $eventWorkflowLeaseToken)
                    ->update(['run_id' => $runId, 'updated_at' => now()]);

                if ($linked !== 1) {
                    throw new \RuntimeException('Workflow event lease was lost before run registration.');
                }
            }

            return $runId;
        });

        try {
            DB::transaction(function () use ($workflowId, $context, $runId, $eventWorkflowId, $manual, $eventWorkflowLeaseToken): void {
                // Serialize execution against workflow edits, disabling and deletion.
                $workflow = DB::table('farast_workflows')
                    ->where('id', $workflowId)
                    ->lockForUpdate()
                    ->first();
                if (! $workflow || ! $workflow->enabled) {
                    throw new \RuntimeException('Workflow is disabled or missing.');
                }

                $steps = json_decode((string) $workflow->steps, true);
                if (! is_array($steps) || count($steps) < 1 || count($steps) > 20) {
                    throw new \RuntimeException('Workflow steps are invalid.');
                }

                if ($manual && ! app(WorkflowConditionEvaluator::class)->matches($workflow->conditions ?? null, $context)) {
                    throw new \RuntimeException('Manual execution cannot satisfy this workflow condition without a trusted domain event.');
                }

                foreach ($steps as $stepIndex => $step) {
                    if (! is_array($step)) {
                        throw new \InvalidArgumentException('Workflow step must be an object.');
                    }
                    $this->step($step, $context, (int) $workflow->user_id, $runId, (int) $stepIndex);
                }

                // Commit side effects and the event-workflow completion marker together.
                if ($eventWorkflowId !== null) {
                    if (! $eventWorkflowLeaseToken) {
                        throw new \RuntimeException('Workflow event lease token is missing.');
                    }
                    $updated = DB::table('farast_workflow_event_workflows')
                        ->where('id', $eventWorkflowId)
                        ->where('workflow_id', $workflowId)
                        ->where('status', 'running')
                        ->where('lease_token', $eventWorkflowLeaseToken)
                        ->update([
                            'status' => 'completed',
                            'last_error' => null,
                            'finished_at' => now(),
                            'lease_token' => null,
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
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            throw $exception;
        }

        return $runId;
    }

    /** Dispatch a domain event once and retain failed workflow runs for safe retry. */
    /**
     * Persist a trusted domain event in the caller's transaction.
     *
     * This is the transactional outbox boundary: domain state and the pending
     * workflow event commit or roll back together. Processing is deliberately
     * deferred to dispatch() after commit or to the scheduled retry worker.
     */
    public function enqueue(string $eventType, array $context, string $idempotencyKey): int
    {
        if (! array_key_exists($eventType, self::EVENT_CONTRACTS)) {
            throw new \InvalidArgumentException('Unsupported workflow event.');
        }

        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 190) {
            throw new \InvalidArgumentException('Workflow event idempotency key must contain 1 to 190 characters.');
        }

        $userId = filter_var($context['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            throw new \InvalidArgumentException('Workflow event requires a valid user_id.');
        }
        if (! Schema::hasTable('farast_workflow_events')) {
            throw new \RuntimeException('Workflow event ledger is not installed; refusing to lose a domain event.');
        }

        $allowedContextFields = WorkflowConditionEvaluator::FIELDS;
        $unknownContextFields = array_diff(array_keys($context), $allowedContextFields);
        if ($unknownContextFields !== []) {
            throw new \InvalidArgumentException('Workflow event context contains unsupported fields.');
        }

        foreach ($context as $field => $value) {
            if ($field === 'user_id') {
                continue;
            }
            if ($value !== null && ! is_scalar($value) && ! is_array($value)) {
                throw new \InvalidArgumentException('Workflow event context values must be scalar or flat arrays.');
            }
            if (is_array($value) && (count($value) > 20 || count(array_filter($value, fn ($item) => $item === null || is_scalar($item))) !== count($value))) {
                throw new \InvalidArgumentException('Workflow event context arrays must contain at most 20 scalar values.');
            }
            if (is_scalar($value) && mb_strlen((string) $value) > 5000) {
                throw new \InvalidArgumentException('Workflow event context values cannot exceed 5000 characters.');
            }
        }

        $this->validateEventContract($eventType, $context);

        $context['user_id'] = (int) $userId;
        // The event type is authoritative server data, never caller-controlled.
        $context['event_type'] = $eventType;
        try {
            $encodedContext = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Workflow event context cannot be encoded.', 0, $exception);
        }
        if (strlen($encodedContext) > 32768) {
            throw new \InvalidArgumentException('Workflow event context cannot exceed 32 KB.');
        }

        try {
            $eventId = (int) DB::table('farast_workflow_events')->insertGetId([
                'event_type' => $eventType,
                'idempotency_key' => $idempotencyKey,
                'user_id' => (int) $userId,
                'context' => $encodedContext,
                'status' => 'pending',
                'attempts' => 0,
                'matched_workflows' => 0,
                'failed_workflows' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->queueEvent($eventId);
            return $eventId;
        } catch (\Illuminate\Database\QueryException $exception) {
            $existing = DB::table('farast_workflow_events')->where('idempotency_key', $idempotencyKey)->first();
            if (! $existing) {
                throw $exception;
            }
            if ((int) $existing->user_id !== $userId || $existing->event_type !== $eventType) {
                throw new \RuntimeException('Workflow event idempotency key is already bound to another event.');
            }
            if (($existing->status ?? null) === 'completed') {
                return 0;
            }

            $existingId = (int) $existing->id;
            $this->queueEvent($existingId);
            return $existingId;
        }
    }

    /** Persist or find an event, then process its immutable outbox payload. */
    public function dispatch(string $eventType, array $context, string $idempotencyKey): int
    {
        $eventId = $this->enqueue($eventType, $context, $idempotencyKey);
        if ($eventId < 1) {
            return 0;
        }

        $event = DB::table('farast_workflow_events')->where('id', $eventId)->first();
        if (! $event || ($event->status ?? null) === 'completed') {
            return 0;
        }

        // Always process the original immutable payload, never a later caller's payload.
        $storedContext = json_decode((string) $event->context, true);
        if (! is_array($storedContext)) {
            throw new \RuntimeException('Stored workflow event context is invalid.');
        }

        return $this->processEvent($event, $storedContext);
    }

    /** Mark abandoned manual or worker runs as failed after their execution lease expires. */
    public function recoverStaleRuns(int $staleMinutes = 30): int
    {
        if (! Schema::hasTable('farast_workflow_runs')) {
            return 0;
        }

        $staleMinutes = max(5, min(120, $staleMinutes));

        return DB::table('farast_workflow_runs')
            ->where('status', 'running')
            ->where('started_at', '<=', now()->subMinutes($staleMinutes))
            ->update([
                'status' => 'failed',
                'error' => 'Execution lease expired before workflow completion.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** Whether a failed event should consume another queue attempt. */
    public function shouldRetryEvent(int $eventId, int $eventAttempts): bool
    {
        if (! Schema::hasTable('farast_workflow_event_workflows')) {
            return $eventAttempts < 5;
        }

        $mappings = DB::table('farast_workflow_event_workflows')
            ->where('event_id', $eventId);

        if (! $mappings->exists()) {
            return $eventAttempts < 5;
        }

        $hasPending = DB::table('farast_workflow_event_workflows')
            ->where('event_id', $eventId)
            ->where('status', 'pending')
            ->exists();

        return $this->hasRetryableWorkflowSteps($eventId)
            || ($hasPending && $eventAttempts < 5);
    }

    /** Whether an event still has workflow mappings below their retry limit. */
    public function hasRetryableWorkflowSteps(int $eventId): bool
    {
        if (! Schema::hasTable('farast_workflow_event_workflows')) {
            return false;
        }

        return DB::table('farast_workflow_event_workflows')
            ->where('event_id', $eventId)
            ->where('attempts', '<', 5)
            ->where('status', 'failed')
            ->exists();
    }

    /** Process a committed outbox event from the dedicated automation queue. */
    public function processQueuedEvent(int $eventId): int
    {
        $event = DB::table('farast_workflow_events')->where('id', $eventId)->first();
        if (! $event || in_array($event->status, ['completed', 'paused'], true)) {
            return 0;
        }
        $context = json_decode((string) $event->context, true);
        if (! is_array($context)) {
            throw new \RuntimeException('Stored workflow event context is invalid.');
        }
        return $this->processEvent($event, $context);
    }

    /** Queue pending and recoverable outbox events in bounded batches. */
    public function dispatchPendingEvents(int $limit = 50): int
    {
        if (! Schema::hasTable('farast_workflow_events') || config('queue.default') === 'sync') {
            return 0;
        }

        $events = DB::table('farast_workflow_events')
            ->whereNotIn('status', ['completed', 'paused'])
            ->where(function ($eligible) {
                $eligible->where(function ($withoutMappings) {
                    $withoutMappings->whereNotExists(function ($empty) {
                        $empty->select(DB::raw(1))->from('farast_workflow_event_workflows')
                            ->whereColumn('farast_workflow_event_workflows.event_id', 'farast_workflow_events.id');
                    })->where(function ($attempts) {
                        $attempts->where('attempts', '<', 5)->orWhereNotNull('matched_at');
                    });
                })->orWhereExists(function ($retryable) {
                    $retryable->select(DB::raw(1))->from('farast_workflow_event_workflows')
                        ->whereColumn('farast_workflow_event_workflows.event_id', 'farast_workflow_events.id')
                        ->where(function ($states) {
                            $states->where(function ($pending) {
                                $pending->where('status', 'pending')
                                    ->where('farast_workflow_events.attempts', '<', 5);
                            })->orWhere(function ($failed) {
                                $failed->where('status', 'failed')
                                    ->where('farast_workflow_event_workflows.attempts', '<', 5);
                            })->orWhere(function ($stale) {
                                $stale->where('status', 'running')
                                    ->where('started_at', '<=', now()->subMinutes(30));
                            });
                        });
                })->orWhere(function ($allCompleted) {
                    $allCompleted->whereExists(function ($anyMapping) {
                        $anyMapping->select(DB::raw(1))->from('farast_workflow_event_workflows')
                            ->whereColumn('farast_workflow_event_workflows.event_id', 'farast_workflow_events.id');
                    })->whereNotExists(function ($unfinished) {
                        $unfinished->select(DB::raw(1))->from('farast_workflow_event_workflows')
                            ->whereColumn('farast_workflow_event_workflows.event_id', 'farast_workflow_events.id')
                            ->where('status', '!=', 'completed');
                    });
                });
            })
            ->where(function ($states) {
                $states->whereIn('status', ['pending', 'failed'])
                    ->orWhere(function ($staleEvent) {
                        $staleEvent->where('status', 'processing')
                            ->where('updated_at', '<=', now()->subMinutes(30));
                    });
            })
            ->orderBy('id')
            ->limit(max(1, min($limit, 200)))
            ->get(['id']);

        foreach ($events as $event) {
            $this->queueEvent((int) $event->id, false);
        }

        return $events->count();
    }

    /** Pause an event at a safe step boundary while preserving its history. */
    public function pauseEvent(int $eventId): bool
    {
        return DB::transaction(function () use ($eventId): bool {
            $event = DB::table('farast_workflow_events')->where('id', $eventId)->lockForUpdate()->first();
            if (! $event || $event->status === 'completed') return false;
            DB::table('farast_workflow_event_workflows')->where('event_id', $eventId)
                ->whereIn('status', ['pending', 'failed'])->update(['status' => 'paused', 'updated_at' => now()]);
            DB::table('farast_workflow_events')->where('id', $eventId)->update(['status' => 'paused', 'updated_at' => now()]);
            return true;
        });
    }

    /** Start a new, explicitly requested recovery cycle for a terminally failed event. */
    public function retryFailedEvent(int $eventId): bool
    {
        $retried = DB::transaction(function () use ($eventId): bool {
            $event = DB::table('farast_workflow_events')->where('id', $eventId)->lockForUpdate()->first();
            if (! $event || $event->status !== 'failed') return false;

            DB::table('farast_workflow_event_workflows')
                ->where('event_id', $eventId)
                ->whereNotIn('status', ['completed'])
                ->update([
                    'status' => 'pending',
                    'attempts' => 0,
                    'last_error' => null,
                    'started_at' => null,
                    'finished_at' => null,
                    'lease_token' => null,
                    'run_id' => null,
                    'updated_at' => now(),
                ]);
            DB::table('farast_workflow_events')->where('id', $eventId)->update([
                'status' => 'pending',
                'attempts' => 0,
                'failed_at' => null,
                'error_summary' => null,
                'processed_at' => null,
                'updated_at' => now(),
            ]);

            return true;
        });

        if ($retried) $this->queueEvent($eventId);
        return $retried;
    }

    /** Resume paused mappings and enqueue the original immutable event. */
    public function resumeEvent(int $eventId): bool
    {
        $resumed = DB::transaction(function () use ($eventId): bool {
            $event = DB::table('farast_workflow_events')->where('id', $eventId)->lockForUpdate()->first();
            if (! $event || $event->status !== 'paused') return false;
            DB::table('farast_workflow_event_workflows')->where('event_id', $eventId)
                ->where('status', 'paused')->update([
                    'status' => 'pending',
                    'attempts' => 0,
                    'last_error' => null,
                    'started_at' => null,
                    'finished_at' => null,
                    'lease_token' => null,
                    'run_id' => null,
                    'updated_at' => now(),
                ]);
            DB::table('farast_workflow_events')->where('id', $eventId)->update([
                'status' => 'pending',
                'attempts' => 0,
                'failed_at' => null,
                'error_summary' => null,
                'processed_at' => null,
                'updated_at' => now(),
            ]);
            return true;
        });
        if ($resumed) $this->queueEvent($eventId);
        return $resumed;
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
        DB::transaction(function () use ($event, $context): void {
            $lockedEvent = DB::table('farast_workflow_events')->where('id', $event->id)->lockForUpdate()->first();
            if (! $lockedEvent || in_array($lockedEvent->status, ['completed', 'paused'], true)) {
                return;
            }

            $hasMappings = DB::table('farast_workflow_event_workflows')
                ->where('event_id', $event->id)->exists();

            // Freeze the matching set once. An event with no matching workflow must
            // not unexpectedly start running a workflow created hours later.
            if (! $hasMappings && empty($lockedEvent->matched_at)) {
                $workflows = DB::table('farast_workflows')
                    ->where('user_id', (int) $lockedEvent->user_id)
                    ->where('trigger_type', $lockedEvent->event_type)
                    ->where('enabled', true)
                    ->orderBy('id')
                    ->get();

                $eventContext = array_merge($context, ['event_type' => $lockedEvent->event_type]);
                foreach ($workflows as $workflow) {
                    // Lock the workflow row so concurrent events cannot both pass
                    // its cooldown window and enqueue duplicate executions.
                    $current = DB::table('farast_workflows')
                        ->where('id', $workflow->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $current || ! $current->enabled) {
                        continue;
                    }

                    if (! app(WorkflowConditionEvaluator::class)->matches($current->conditions ?? null, $eventContext)) {
                        continue;
                    }

                    $cooldown = max(0, min(86400, (int) ($current->cooldown_seconds ?? 0)));
                    if ($cooldown > 0 && ! empty($current->last_triggered_at)
                        && \Illuminate\Support\Carbon::parse($current->last_triggered_at)->gt(now()->subSeconds($cooldown))) {
                        continue;
                    }

                    $inserted = DB::table('farast_workflow_event_workflows')->insertOrIgnore([
                        'event_id' => $event->id,
                        'workflow_id' => $current->id,
                        'status' => 'pending',
                        'attempts' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($inserted > 0) {
                        DB::table('farast_workflows')->where('id', $current->id)->update([
                            'last_triggered_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            DB::table('farast_workflow_events')->where('id', $event->id)->update([
                'status' => 'processing',
                'started_at' => now(),
                'matched_at' => $lockedEvent->matched_at ?: now(),
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
            if (DB::table('farast_workflow_events')->where('id', $event->id)->value('status') === 'paused') break;
            $leaseToken = bin2hex(random_bytes(24));
            $claimed = DB::transaction(function () use ($mapping, $leaseToken, $event): bool {
                $eventState = DB::table('farast_workflow_events')->where('id', $event->id)->lockForUpdate()->value('status');
                if ($eventState === 'paused') return false;
                $locked = DB::table('farast_workflow_event_workflows')
                    ->where('id', $mapping->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked || in_array($locked->status, ['completed', 'paused'], true)) {
                    return false;
                }

                $staleLease = $locked->status === 'running' && $locked->started_at
                    && \Illuminate\Support\Carbon::parse($locked->started_at)->lte(now()->subMinutes(30));

                if ($locked->status === 'running' && $locked->started_at && ! $staleLease) {
                    return false;
                }

                if ($staleLease && ! empty($locked->run_id)) {
                    DB::table('farast_workflow_runs')
                        ->where('id', $locked->run_id)
                        ->where('status', 'running')
                        ->update([
                            'status' => 'failed',
                            'error' => 'Worker lease expired before workflow completion.',
                            'finished_at' => now(),
                            'updated_at' => now(),
                        ]);
                }

                if ((int) $locked->attempts >= 5) {
                    if ($staleLease) {
                        DB::table('farast_workflow_event_workflows')
                            ->where('id', $locked->id)
                            ->where('status', 'running')
                            ->update([
                                'status' => 'failed',
                                'last_error' => 'Workflow mapping exhausted its retry limit after lease expiry.',
                                'finished_at' => now(),
                                'lease_token' => null,
                                'run_id' => null,
                                'updated_at' => now(),
                            ]);
                    }

                    return false;
                }

                DB::table('farast_workflow_events')->where('id', $event->id)->update([
                    'updated_at' => now(),
                ]);

                DB::table('farast_workflow_event_workflows')->where('id', $locked->id)->update([
                    'status' => 'running',
                    'run_id' => null,
                    'attempts' => (int) $locked->attempts + 1,
                    'last_error' => null,
                    'started_at' => now(),
                    'finished_at' => null,
                    'lease_token' => $leaseToken,
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
                ]), (int) $mapping->id, false, $leaseToken);
                $completedNow++;
            } catch (\Throwable $exception) {
                DB::table('farast_workflow_event_workflows')->where('id', $mapping->id)
                    ->where('status', 'running')->where('lease_token', $leaseToken)->update([
                    'status' => 'failed',
                    'last_error' => mb_substr($exception->getMessage(), 0, 4000),
                    'finished_at' => now(),
                    'lease_token' => null,
                    'run_id' => null,
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
        $currentEventStatus = DB::table('farast_workflow_events')->where('id', $event->id)->value('status');
        $nextEventStatus = $currentEventStatus === 'paused' ? 'paused' : ($complete ? 'completed' : ($active > 0 ? 'processing' : 'failed'));
        DB::table('farast_workflow_events')->where('id', $event->id)->update([
            'status' => $nextEventStatus,
            'failed_at' => $nextEventStatus === 'failed' ? now() : null,
            'matched_workflows' => $total,
            'failed_workflows' => $failed,
            'error_summary' => $errors ? mb_substr(implode("\n", $errors), 0, 4000) : null,
            'processed_at' => $complete ? now() : null,
            'updated_at' => now(),
        ]);

        return $completedNow;
    }

    private function queueEvent(int $eventId, bool $afterCommit = true): void
    {
        // A sync driver is useful for unit tests but must never masquerade as background processing.
        if (config('queue.default') === 'sync') {
            return;
        }

        $eventType = DB::table('farast_workflow_events')->where('id', $eventId)->value('event_type');
        $queue = in_array($eventType, ['order.paid', 'order.deposit_paid'], true)
            ? 'automation-critical'
            : 'automation';

        $pending = ProcessWorkflowEvent::dispatch($eventId)->onQueue($queue);
        if ($afterCommit) {
            $pending->afterCommit();
        }
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
