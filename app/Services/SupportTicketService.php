<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Http\Middleware\ResolveAdminScope;
use App\Services\WorkflowEngine;
use App\Services\Storage\StorageManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * هسته یکپارچه سیستم پشتیبانی — ایجاد، پاسخ، وضعیت، ارجاع خودکار، مهمان، اطلاع‌رسانی، SLA، CSAT.
 */
class SupportTicketService
{
    public const STATUSES = ['open', 'pending', 'answered', 'user_replied', 'closed'];

    public const STATUS_FILTER_NEEDS_REPLY = 'needs_reply';

    public const DEPARTMENT_SUPPORT = 'support';

    public const DEPARTMENT_SALES = 'sales';

    public const DEPARTMENT_BILLING = 'billing';

    public const DEPARTMENT_COMPLAINT = 'complaint';

    public const DEPARTMENTS = [
        self::DEPARTMENT_SUPPORT,
        self::DEPARTMENT_SALES,
        self::DEPARTMENT_BILLING,
        self::DEPARTMENT_COMPLAINT,
    ];

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITIES = [self::PRIORITY_LOW, self::PRIORITY_NORMAL, self::PRIORITY_HIGH];

    public const SOURCE_DASHBOARD = 'dashboard';

    public const SOURCE_WIDGET = 'widget';

    public const SOURCES = [self::SOURCE_DASHBOARD, self::SOURCE_WIDGET];

    public const TOPIC_AREA_SERVICES = 'services';

    public const TOPIC_AREA_STORE = 'store';

    public const TOPIC_AREAS = [self::TOPIC_AREA_SERVICES, self::TOPIC_AREA_STORE];

    public const ASSIGNMENT_FILTER_ME = 'me';

    public const ASSIGNMENT_FILTER_UNASSIGNED = 'unassigned';

    public const ASSIGNMENT_FILTERS = [self::ASSIGNMENT_FILTER_ME, self::ASSIGNMENT_FILTER_UNASSIGNED];

    public const SLA_FILTER_AT_RISK = 'at_risk';

    public const SLA_FILTER_BREACHED = 'breached';

    public const SLA_FILTERS = [self::SLA_FILTER_AT_RISK, self::SLA_FILTER_BREACHED];

    public function __construct(
        private readonly SupportNotificationService $notifier,
        private readonly SupportSlaService $sla,
        private readonly ?SupportAgentDirectory $agentDirectory = null,
    ) {
    }

    public function generateCode(): string
    {
        do {
            $code = 'TK-'.now()->format('ymd').'-'.strtoupper(Str::random(4));
        } while (Schema::hasColumn('tickets', 'ticket_code') && Ticket::where('ticket_code', $code)->exists());

        return $code;
    }

    public function createTicket(?User $user, array $data, ?UploadedFile $attachment = null): Ticket
    {
        $initialMessage = null;
        $created = false;
        $submission = $this->submissionIdentity(
            $data['_submission_scope'] ?? null,
            $data['_submission_key'] ?? null,
            collect($data)->except(['_submission_scope', '_submission_key'])->all(),
            $attachment,
        );

        try {
            $ticket = app(SupportAssignmentMutex::class)->synchronized(function () use ($user, $data, $attachment, &$initialMessage, &$created, $submission) {
                return DB::transaction(function () use ($user, $data, $attachment, &$initialMessage, &$created, $submission) {
                if ($submission) {
                    $existing = Ticket::query()
                        ->where('submission_scope', $submission['scope'])
                        ->where('submission_key', $submission['key'])
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        if (! hash_equals((string) $existing->submission_fingerprint, $submission['fingerprint'])) {
                            throw ValidationException::withMessages(['submission_key' => 'شناسه ارسال قبلاً برای محتوای دیگری استفاده شده است.']);
                        }
                        return $existing->fresh(['messages', 'user', 'relatedProduct', 'assignee']);
                    }
                }

                $payload = [
                    'user_id' => $user?->id,
                    'subject' => $data['subject'],
                    'status' => 'open',
                    'priority' => $data['priority'] ?? self::PRIORITY_NORMAL,
                    'department' => $data['department'] ?? self::DEPARTMENT_SUPPORT,
                ];
    
                if (Schema::hasColumn('tickets', 'ticket_code')) {
                    $payload['ticket_code'] = $this->generateCode();
                }
                if (Schema::hasColumn('tickets', 'guest_name')) {
                    $payload['guest_name'] = $data['guest_name'] ?? null;
                }
                if (Schema::hasColumn('tickets', 'guest_email')) {
                    $payload['guest_email'] = isset($data['guest_email']) ? mb_strtolower(trim($data['guest_email'])) : null;
                }
                if (Schema::hasColumn('tickets', 'topic_area')) {
                    $payload['topic_area'] = $data['topic_area'] ?? null;
                }
                if (Schema::hasColumn('tickets', 'related_service')) {
                    $payload['related_service'] = $data['related_service'] ?? null;
                }
                if (Schema::hasColumn('tickets', 'related_product_id')) {
                    $payload['related_product_id'] = $data['related_product_id'] ?? null;
                }
                if (Schema::hasColumn('tickets', 'source')) {
                    $payload['source'] = $data['source'] ?? ($user ? self::SOURCE_DASHBOARD : self::SOURCE_WIDGET);
                }
                if (Schema::hasColumn('tickets', 'last_reply_at')) {
                    $payload['last_reply_at'] = now();
                }
    
                if ($submission) {
                    $payload['submission_scope'] = $submission['scope'];
                    $payload['submission_key'] = $submission['key'];
                    $payload['submission_fingerprint'] = $submission['fingerprint'];
                }

                $ticket = Ticket::create($payload);
                $created = true;

                // Ticket creation is the first durable lifecycle event. Keep the
                // audit entry in this transaction and exclude subject, message,
                // guest contact details and attachment metadata from its payload.
                $this->recordSupportAuditEvent($ticket, $user, 'support.ticket.created', [
                    'status' => 'open',
                    'source' => $payload['source'] ?? ($user ? self::SOURCE_DASHBOARD : self::SOURCE_WIDGET),
                    'department' => $payload['department'],
                    'priority' => $payload['priority'],
                    'topic_area' => $payload['topic_area'] ?? null,
                    'related_service' => $payload['related_service'] ?? null,
                    'related_product_id' => isset($payload['related_product_id'])
                        ? (int) $payload['related_product_id']
                        : null,
                    'initiator_type' => $user ? 'customer' : 'guest',
                ]);

                // Assignment is resolved against the persisted ticket so RBAC scope
                // checks always evaluate the actual record, not an incomplete payload.
                if (Schema::hasColumn('tickets', 'assigned_to')) {
                    $agent = ! empty($data['assigned_to'])
                        ? User::query()->find((int) $data['assigned_to'])
                        : $this->pickLeastLoadedAgent($ticket);
    
                    if ($agent) {
                        abort_unless($this->isEligibleSupportAgent($agent, $ticket), 403);
                        $ticket->forceFill(['assigned_to' => $agent->id])->save();

                        $this->recordSupportAuditEvent($ticket, null, 'support.ticket.assignment_changed', [
                            'from_assigned_to' => null,
                            'to_assigned_to' => (int) $agent->id,
                        ]);
                    }
                }
    
                $initialMessage = $this->addMessage($ticket, $user, $data['body'], [
                    'is_staff' => false,
                    'is_internal' => false,
                    'attachment' => $attachment,
                    'touch_status' => false,
                    'notify' => false,
                ]);
    
                $ticket = $ticket->fresh(['messages', 'user', 'relatedProduct', 'assignee']);
                $this->sla->applyTargets($ticket);

                app(WorkflowEngine::class)->enqueueTicketCreated($ticket);

                return $ticket->fresh(['messages', 'user', 'relatedProduct', 'assignee']);
                });
            });
        } catch (\Throwable $exception) {
            // addMessage owns cleanup for failures inside its own transaction.
            // This outer catch covers failures after it returned but before the
            // ticket transaction committed (for example SLA target calculation).
            foreach ((array) ($initialMessage?->attachments ?? []) as $attachmentData) {
                $disk = (string) ($attachmentData['disk'] ?? 'private');
                $path = (string) ($attachmentData['path'] ?? '');
                if ($path === '') {
                    continue;
                }

                try {
                    app(StorageManager::class)->delete($disk, $path);
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            if ($submission && $exception instanceof QueryException) {
                $concurrentReplay = Ticket::query()
                    ->where('submission_scope', $submission['scope'])
                    ->where('submission_key', $submission['key'])
                    ->first();

                if ($concurrentReplay) {
                    if (! hash_equals((string) $concurrentReplay->submission_fingerprint, $submission['fingerprint'])) {
                        throw ValidationException::withMessages(['submission_key' => 'شناسه ارسال قبلاً برای محتوای دیگری استفاده شده است.']);
                    }

                    return $concurrentReplay->fresh(['messages', 'user', 'relatedProduct', 'assignee']);
                }
            }

            throw $exception;
        }

        if (! $created) {
            return $ticket;
        }

        $this->safeNotify(fn () => $this->notifier->ticketCreated($ticket), 'ticket_created', (int) $ticket->id);

        if ($ticket->assignee) {
            $this->safeNotify(fn () => $this->notifier->assigned($ticket, $ticket->assignee), 'ticket_created_assignment', (int) $ticket->id);
        }

        return $ticket;
    }

    /**
     * Active users who can manage this exact ticket under the central RBAC scope.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function eligibleAgentsFor(Ticket $ticket)
    {
        if (! Schema::hasColumn('tickets', 'assigned_to')) {
            return collect();
        }

        return ($this->agentDirectory ?? app(SupportAgentDirectory::class))->eligibleFor($ticket);
    }

    public function isEligibleSupportAgent(User $agent, Ticket $ticket): bool
    {
        return ($this->agentDirectory ?? app(SupportAgentDirectory::class))->allows($agent, $ticket);
    }

    /**
     * Select the eligible agent with the lowest active workload visible in their
     * own effective RBAC scope. Global agents include guest tickets; scoped
     * agents are not penalized for tickets they cannot access.
     */
    public function pickLeastLoadedAgent(Ticket $ticket): ?User
    {
        app(SupportAssignmentMutex::class)->assertHeld();

        $agents = $this->eligibleAgentsFor($ticket);
        if ($agents->isEmpty()) {
            return null;
        }

        // Count only active tickets visible to each candidate under that
        // candidate's own effective scope. A team agent must not be penalized
        // for tickets belonging to other teams or global-only guest tickets.
        $loadQuery = Ticket::query()->whereNotIn('status', ['closed']);
        $loadQuery->where(function ($query) use ($agents): void {
            foreach ($agents as $agent) {
                $visibleUserIds = ResolveAdminScope::visibleUserIds(
                    $agent,
                    ResolveAdminScope::scopeWithConfiguredOverride($agent),
                );

                $query->orWhere(function ($agentQuery) use ($agent, $visibleUserIds): void {
                    $agentQuery->where('assigned_to', $agent->id);

                    if ($visibleUserIds !== null) {
                        $agentQuery->whereIn('user_id', $visibleUserIds);
                    }
                });
            }
        });

        $openCounts = $loadQuery
            ->selectRaw('assigned_to, COUNT(*) as c')
            ->groupBy('assigned_to')
            ->pluck('c', 'assigned_to');

        return $agents->sort(function (User $left, User $right) use ($openCounts): int {
            $leftLoad = (int) ($openCounts[$left->id] ?? 0);
            $rightLoad = (int) ($openCounts[$right->id] ?? 0);

            return ($leftLoad <=> $rightLoad) ?: ((int) $left->id <=> (int) $right->id);
        })->first();
    }

    public function addMessage(Ticket $ticket, ?User $actor, string $body, array $options = []): TicketMessage
    {
        $created = false;
        $submission = $this->submissionIdentity(
            $options['_submission_scope'] ?? null,
            $options['_submission_key'] ?? null,
            [
                'ticket_id' => (int) $ticket->id,
                'body' => $body,
                'is_staff' => (bool) ($options['is_staff'] ?? false),
                'is_internal' => (bool) ($options['is_internal'] ?? false),
                'status' => $options['status'] ?? null,
                'touch_status' => $options['touch_status'] ?? true,
            ],
            $options['attachment'] ?? null,
        );
        $isStaff = (bool) ($options['is_staff'] ?? false);
        $isInternal = (bool) ($options['is_internal'] ?? false);
        $touchStatus = $options['touch_status'] ?? true;
        $notify = $options['notify'] ?? true;

        $payload = [
            'user_id' => $actor?->id,
            'body' => $body,
            'is_internal' => $isInternal,
        ];

        if (Schema::hasColumn('ticket_messages', 'is_staff')) {
            $payload['is_staff'] = $isStaff;
        }

        if ($submission) {
            $payload['submission_scope'] = $submission['scope'];
            $payload['submission_key'] = $submission['key'];
            $payload['submission_fingerprint'] = $submission['fingerprint'];
            $existing = TicketMessage::query()
                ->where('submission_scope', $submission['scope'])
                ->where('submission_key', $submission['key'])
                ->first();
            if ($existing) {
                if (! hash_equals((string) $existing->submission_fingerprint, $submission['fingerprint'])) {
                    throw ValidationException::withMessages(['submission_key' => 'شناسه ارسال قبلاً برای محتوای دیگری استفاده شده است.']);
                }
                return $existing;
            }
        }

        $temporaryPath = null;
        $managed = null;

        if (! empty($options['attachment']) && $options['attachment'] instanceof UploadedFile
            && Schema::hasColumn('ticket_messages', 'attachments')) {
            $file = $options['attachment'];
            $temporaryPath = $file->store('ticket-attachments/'.$ticket->id, 'private');
            if (! is_string($temporaryPath) || $temporaryPath === '') {
                throw new \RuntimeException('ذخیره موقت پیوست تیکت ناموفق بود.');
            }

            try {
                $inspection = app(UploadSecurityService::class)->inspect($temporaryPath, (string) $file->getMimeType(), 8 * 1024 * 1024);
                if (empty($inspection['safe'])) {
                    throw new \RuntimeException('پیوست تیکت از بررسی امنیتی عبور نکرد.');
                }

                $managed = app(StorageManager::class)->adoptExistingPrivate(
                    $temporaryPath,
                    $file->getClientOriginalName(),
                    (string) ($inspection['mime'] ?? $file->getMimeType()),
                    (int) $file->getSize(),
                    'support',
                    Ticket::class,
                    (int) $ticket->id
                );
            } catch (\Throwable $exception) {
                if ($temporaryPath !== '') {
                    \Illuminate\Support\Facades\Storage::disk('private')->delete($temporaryPath);
                }
                throw $exception;
            }

            $payload['attachments'] = [[
                'path' => $managed['path'],
                'disk' => $managed['disk'],
                'managed_file_id' => $managed['managed_file_id'],
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $inspection['mime'] ?? $file->getMimeType(),
                'sha256' => $inspection['checksum'] ?? null,
            ]];
        }

        try {
            $persistMessage = fn () => DB::transaction(function () use (
                $ticket, $actor, $body, $options, $payload, $isStaff, $isInternal, $touchStatus, $submission, &$created
            ): array {
                $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
                $previousStatus = (string) $locked->status;
                $resolutionBreachDetected = false;

                if ($submission) {
                    $existing = TicketMessage::query()
                        ->where('submission_scope', $submission['scope'])
                        ->where('submission_key', $submission['key'])
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        if (! hash_equals((string) $existing->submission_fingerprint, $submission['fingerprint'])) {
                            throw ValidationException::withMessages(['submission_key' => 'شناسه ارسال قبلاً برای محتوای دیگری استفاده شده است.']);
                        }
                        return [$existing, $previousStatus, $locked->fresh(), false];
                    }
                }

                // Validate the event origin and destination against the canonical
                // status policy while holding the ticket row lock.
                if ($touchStatus && ! $isInternal) {
                    $statusPolicy = app(SupportTicketStatusPolicy::class);
                    if ($isStaff) {
                        $statusPolicy->assertWorkflowTarget((string) ($options['status'] ?? 'answered'));
                    } else {
                        $statusPolicy->assertCustomerReplyAllowed($previousStatus);
                    }
                }

                $message = $locked->messages()->create($payload);
                $created = true;

                foreach ((array) ($payload['attachments'] ?? []) as $attachment) {
                    if (! empty($attachment['managed_file_id'])) {
                        app(StorageManager::class)->bindReference(
                            (int) $attachment['managed_file_id'],
                            TicketMessage::class,
                            (int) $message->id
                        );
                    }
                }

                // Record the message lifecycle without copying its body, attachment
                // paths, names, checksums, or customer contact details into the ledger.
                $messageIsStaff = $isStaff || (bool) ($actor?->isAdmin() ?? false);
                $actorType = $actor
                    ? ($messageIsStaff ? 'staff' : 'customer')
                    : (($isInternal || $messageIsStaff) ? 'system' : 'guest');
                $messageType = $isInternal
                    ? ($actor ? 'internal_note' : 'system_note')
                    : ($messageIsStaff ? ($actor ? 'staff_reply' : 'system_reply') : ($actor ? 'customer_reply' : 'guest_reply'));
                $attachmentCount = count((array) $message->attachments);

                $this->recordSupportAuditEvent($locked, $actor, 'support.ticket.message_added', [
                    'message_id' => (int) $message->id,
                    'message_type' => $messageType,
                    'actor_type' => $actorType,
                    'is_internal' => $isInternal,
                    'is_staff' => $messageIsStaff,
                    'has_attachments' => $attachmentCount > 0,
                    'attachment_count' => $attachmentCount,
                ]);

                if ($touchStatus && ! $isInternal) {
                    $update = [];
                    if (Schema::hasColumn('tickets', 'last_reply_at')) {
                        $update['last_reply_at'] = now();
                    }

                    $targetStatus = $isStaff
                        ? (string) ($options['status'] ?? 'answered')
                        : SupportTicketStatusPolicy::CUSTOMER_REPLY_STATUS;

                    if ($isStaff && Schema::hasColumn('tickets', 'first_response_at') && empty($locked->first_response_at)) {
                        $update['first_response_at'] = now();
                    }

                    $slaBefore = $this->slaAuditSnapshot($locked);
                    $transition = $this->buildStatusTransitionUpdate($locked, $targetStatus);
                    $update = array_merge($update, $transition['attributes']);
                    $firstResponseBreachDetected = $transition['first_response_breach_detected'];
                    $resolutionBreachDetected = $transition['resolution_breach_detected'];

                    if ($update !== []) {
                        $locked->forceFill($update)->save();
                    }

                    if ($firstResponseBreachDetected) {
                        $this->enqueueFirstResponseBreachAlert($locked);
                    }
                    if ($resolutionBreachDetected) {
                        $this->enqueueResolutionBreachAlert($locked);
                    }

                    if ($previousStatus !== $targetStatus) {
                        $this->recordSupportAuditEvent(
                            $locked,
                            $actor,
                            'support.ticket.status_transitioned',
                            $this->statusTransitionAuditMetadata($previousStatus, $targetStatus, $slaBefore, $locked->fresh())
                        );
                    }
                }

                return [$message, $previousStatus, $locked->fresh(), true];
            });
            [$message, $previousStatus, $fresh, $created] = ($touchStatus && ! $isInternal)
                ? app(SupportAssignmentMutex::class)->synchronized($persistMessage)
                : $persistMessage();
        } catch (\Throwable $exception) {
            if ($temporaryPath !== null && $temporaryPath !== '') {
                \Illuminate\Support\Facades\Storage::disk('private')->delete($temporaryPath);
            }
            if (is_array($managed) && ! empty($managed['disk']) && ! empty($managed['path'])) {
                try {
                    app(StorageManager::class)->delete((string) $managed['disk'], (string) $managed['path']);
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            if ($submission && $exception instanceof QueryException) {
                $concurrentReplay = TicketMessage::query()
                    ->where('submission_scope', $submission['scope'])
                    ->where('submission_key', $submission['key'])
                    ->first();

                if ($concurrentReplay) {
                    if (! hash_equals((string) $concurrentReplay->submission_fingerprint, $submission['fingerprint'])) {
                        throw ValidationException::withMessages(['submission_key' => 'شناسه ارسال قبلاً برای محتوای دیگری استفاده شده است.']);
                    }

                    return $concurrentReplay;
                }
            }

            throw $exception;
        }

        if ($temporaryPath !== null && $temporaryPath !== '') {
            \Illuminate\Support\Facades\Storage::disk('private')->delete($temporaryPath);
        }

        if (! $created) {
            if (is_array($managed) && ! empty($managed['disk']) && ! empty($managed['path'])) {
                try {
                    app(StorageManager::class)->delete((string) $managed['disk'], (string) $managed['path']);
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            return $message;
        }

        if ($notify && ! $isInternal) {
            if ($isStaff) {
                $this->safeNotify(fn () => $this->notifier->staffReplied($fresh, $body, false, (int) $message->id), 'staff_replied', (int) $fresh->id);
                if (($options['status'] ?? null) === 'closed') {
                    $this->safeNotify(fn () => $this->notifier->statusChanged($fresh, $previousStatus, 'closed', 'message:'.$message->id), 'staff_reply_closed_ticket', (int) $fresh->id);
                }
            } else {
                $this->safeNotify(fn () => $this->notifier->customerReplied($fresh, $body, (int) $message->id), 'customer_replied', (int) $fresh->id);
            }
        }

        return $message;
    }

    /**
     * Build a stable, actor-scoped request identity. The payload fingerprint
     * prevents a client from reusing one key for different content.
     */
    private function submissionIdentity(?string $scope, ?string $key, array $payload, ?UploadedFile $attachment = null): ?array
    {
        if ($scope === null && $key === null) {
            return null;
        }

        if (! is_string($scope) || $scope === '' || ! is_string($key) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['submission_key' => 'شناسه ارسال معتبر نیست.']);
        }

        $fileFingerprint = null;
        if ($attachment instanceof UploadedFile && $attachment->isValid()) {
            $path = $attachment->getRealPath();
            $fileFingerprint = $path ? hash_file('sha256', $path) : null;
        }

        return [
            'scope' => hash('sha256', $scope),
            'key' => strtolower($key),
            'fingerprint' => hash('sha256', json_encode([
                'payload' => $payload,
                'attachment_sha256' => $fileFingerprint,
                'attachment_name' => $attachment?->getClientOriginalName(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Build the canonical lifecycle fields for every status change, whether
     * initiated explicitly or as part of a conversation reply. This keeps
     * timestamps and SLA pause/resume behavior consistent across both paths.
     *
     * @return array{attributes: array<string, mixed>, first_response_breach_detected: bool, resolution_breach_detected: bool}
     */
    private function buildStatusTransitionUpdate(Ticket $ticket, string $targetStatus): array
    {
        if (! in_array($targetStatus, self::STATUSES, true)) {
            throw new \InvalidArgumentException('وضعیت نامعتبر است.');
        }

        $attributes = [];

        // A reply that keeps the same status is not a lifecycle transition.
        // In particular, it must not rewrite the historical close/resolve time
        // or restart a paused SLA clock.
        if ((string) $ticket->status !== $targetStatus) {
            $attributes['status'] = $targetStatus;

            if ($targetStatus === 'closed') {
                if (Schema::hasColumn('tickets', 'closed_at')) {
                    $attributes['closed_at'] = now();
                }
                if (Schema::hasColumn('tickets', 'resolved_at')) {
                    $attributes['resolved_at'] = now();
                }
            } else {
                if (Schema::hasColumn('tickets', 'closed_at')) {
                    $attributes['closed_at'] = null;
                }
                if (Schema::hasColumn('tickets', 'resolved_at')) {
                    $attributes['resolved_at'] = null;
                }
            }

            $attributes = array_merge(
                $attributes,
                $this->sla->statusTransitionAttributes($ticket, $targetStatus)
            );
        }

        return [
            'attributes' => $attributes,
            'first_response_breach_detected' => ! empty($attributes['sla_first_breached_at'])
                && empty($ticket->sla_first_breached_at),
            'resolution_breach_detected' => ! empty($attributes['sla_resolve_breached_at'])
                && empty($ticket->sla_resolve_breached_at),
        ];
    }

    public function changeStatus(Ticket $ticket, string $status, ?User $actor = null, bool $systemNote = true): Ticket
    {
        app(SupportTicketStatusPolicy::class)->assertWorkflowTarget($status);

        [$fresh, $old, $transitionMessageId, $changed] = app(SupportAssignmentMutex::class)->synchronized(
            fn () => DB::transaction(function () use ($ticket, $status, $actor, $systemNote): array {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $old = (string) $locked->status;

            if ($old === $status) {
                return [$locked->fresh(), $old, null, false];
            }

            $slaBefore = $this->slaAuditSnapshot($locked);
            $transition = $this->buildStatusTransitionUpdate($locked, $status);
            $locked->forceFill($transition['attributes'])->save();
            $firstResponseBreachDetected = $transition['first_response_breach_detected'];
            $resolutionBreachDetected = $transition['resolution_breach_detected'];

            if ($firstResponseBreachDetected) {
                $this->enqueueFirstResponseBreachAlert($locked);
            }
            if ($resolutionBreachDetected) {
                $this->enqueueResolutionBreachAlert($locked);
            }

            $this->recordSupportAuditEvent(
                $locked,
                $actor,
                'support.ticket.status_transitioned',
                $this->statusTransitionAuditMetadata($old, $status, $slaBefore, $locked->fresh())
            );

            $transitionMessage = null;
            if ($systemNote && $actor) {
                $label = $locked->fresh()->statusLabel();
                $transitionMessage = $this->addMessage($locked, $actor, '[سیستم] وضعیت تیکت به «'.$label.'» تغییر کرد.', [
                    'is_staff' => true,
                    'is_internal' => true,
                    'touch_status' => false,
                    'notify' => false,
                ]);
            }

            return [$locked->fresh(), $old, $transitionMessage?->id, true];
            })
        );

        if (! $changed) {
            return $fresh;
        }

        $this->safeNotify(fn () => $this->notifier->statusChanged(
            $fresh,
            $old,
            $status,
            $transitionMessageId ? 'message:'.$transitionMessageId : 'transition:'.($fresh->updated_at?->format('U.u') ?? 'unknown')
        ), 'status_changed', (int) $fresh->id);

        return $fresh;
    }

    public function assign(Ticket $ticket, ?User $agent, ?User $actor = null): Ticket
    {
        if (! Schema::hasColumn('tickets', 'assigned_to')) {
            return $ticket;
        }

        [$fresh, $previousAgent, $assignmentMessageId, $changed] = app(SupportAssignmentMutex::class)->synchronized(
            fn () => DB::transaction(function () use ($ticket, $agent, $actor): array {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if ((int) ($locked->assigned_to ?? 0) === (int) ($agent?->id ?? 0)) {
                return [$locked->fresh(['assignee']), null, null, false];
            }

            if ($agent !== null) {
                abort_unless($this->isEligibleSupportAgent($agent, $locked), 403);
            }

            $previousAssigneeId = $locked->assigned_to ? (int) $locked->assigned_to : null;
            $previousAgent = $previousAssigneeId ? User::query()->find($previousAssigneeId) : null;
            $nextAssigneeId = $agent?->id ? (int) $agent->id : null;
            $locked->update(['assigned_to' => $nextAssigneeId]);

            $this->recordSupportAuditEvent($locked, $actor, 'support.ticket.assignment_changed', [
                'from_assigned_to' => $previousAssigneeId,
                'to_assigned_to' => $nextAssigneeId,
            ]);

            $assignmentMessage = null;
            if ($actor) {
                $messageBody = $agent
                    ? '[سیستم] تیکت به «'.($agent->name ?: $agent->email).'» ارجاع شد.'
                    : '[سیستم] ارجاع تیکت لغو شد.';
                $assignmentMessage = $this->addMessage($locked, $actor, $messageBody, [
                    'is_staff' => true,
                    'is_internal' => true,
                    'touch_status' => false,
                    'notify' => false,
                ]);
            }

            return [$locked->fresh(['assignee']), $previousAgent, $assignmentMessage?->id, true];
            })
        );

        if (! $changed) {
            return $fresh;
        }

        $eventIdentity = $assignmentMessageId
            ? 'message:'.$assignmentMessageId
            : 'assignment:'.($fresh->updated_at?->format('U.u') ?? 'unknown');

        if ($previousAgent && (int) $previousAgent->id !== (int) ($agent?->id ?? 0)) {
            $this->safeNotify(fn () => $this->notifier->assignmentRemoved($fresh, $previousAgent, $eventIdentity), 'assignment_removed', (int) $fresh->id);
        }

        $this->safeNotify(fn () => $this->notifier->assigned($fresh, $agent, $eventIdentity), 'assignment_changed', (int) $fresh->id);

        return $fresh;
    }

    /**
     * به‌روزرسانی اولویت / دپارتمان؛ در صورت تغییر اولویت، مهلت SLA از نو محاسبه می‌شود.
     */
    public function updateMeta(Ticket $ticket, array $data, ?User $actor = null): Ticket
    {
        [$fresh] = DB::transaction(function () use ($ticket, $data, $actor): array {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $wasResolutionBreached = ! empty($locked->sla_resolve_breached_at);
            $beforeMeta = [
                'priority' => $locked->priority,
                'department' => $locked->department,
            ];
            $slaBefore = $this->slaAuditSnapshot($locked);
            $update = [];
            $notes = [];

            if (array_key_exists('priority', $data) && in_array($data['priority'], self::PRIORITIES, true)
                && $data['priority'] !== $locked->priority) {
                $update['priority'] = $data['priority'];
                $notes[] = 'اولویت به «'.(match ($data['priority']) {
                    'high' => 'زیاد',
                    'low' => 'کم',
                    default => 'متوسط',
                }).'»';
            }

            if (array_key_exists('department', $data) && in_array($data['department'], self::DEPARTMENTS, true)
                && $data['department'] !== $locked->department) {
                $update['department'] = $data['department'];
                $notes[] = 'دپارتمان به «'.(match ($data['department']) {
                    'sales' => 'فروش',
                    'billing' => 'مالی',
                    'complaint' => 'شکایات',
                    default => 'پشتیبانی',
                }).'»';
            }

            if ($update === []) {
                return [$locked->fresh(['assignee', 'user'])];
            }

            $locked->update($update);
            if (isset($update['priority'])) {
                $this->sla->applyTargets($locked);
            }

            $this->recordSupportAuditEvent($locked, $actor, 'support.ticket.metadata_changed', [
                'before' => $beforeMeta,
                'after' => [
                    'priority' => $locked->priority,
                    'department' => $locked->department,
                ],
                'sla_before' => $slaBefore,
                'sla_after' => $this->slaAuditSnapshot($locked->fresh()),
            ]);

            if ($actor && $notes) {
                $this->addMessage($locked, $actor, '[سیستم] '.implode(' و ', $notes).' تغییر کرد.', [
                    'is_staff' => true,
                    'is_internal' => true,
                    'touch_status' => false,
                    'notify' => false,
                ]);
            }

            $resolutionBreachDetected = ! $wasResolutionBreached && ! empty($locked->sla_resolve_breached_at);
            if ($resolutionBreachDetected) {
                $this->enqueueResolutionBreachAlert($locked);
            }

            return [$locked->fresh(['assignee', 'user'])];
        });

        return $fresh;
    }

    /**
     * ثبت رضایت‌سنجی (CSAT) پس از بستن تیکت — امتیاز ۱ تا ۵.
     */
    public function submitCsat(Ticket $ticket, int $score, ?string $comment = null, ?User $actor = null): Ticket
    {
        if ($score < 1 || $score > 5) {
            throw new \InvalidArgumentException('امتیاز باید بین ۱ تا ۵ باشد.');
        }
        if (! Schema::hasColumn('tickets', 'csat_score')) {
            return $ticket;
        }

        return DB::transaction(function () use ($ticket, $score, $comment, $actor): Ticket {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if ($locked->status !== 'closed') {
                throw new \InvalidArgumentException('فقط تیکت بسته‌شده قابل امتیازدهی است.');
            }
            if ($locked->csat_at) {
                throw new \InvalidArgumentException('قبلاً امتیاز ثبت شده است.');
            }

            $locked->update([
                'csat_score' => $score,
                'csat_comment' => $comment ? Str::limit($comment, 500) : null,
                'csat_at' => now(),
            ]);

            $auditActor = $actor ?: $locked->user;
            $this->recordSupportAuditEvent($locked, $auditActor, 'support.ticket.csat_submitted', [
                'score' => $score,
                'initiator_type' => $auditActor
                    ? ($auditActor->isAdmin() ? 'staff' : 'customer')
                    : 'guest',
            ]);

            return $locked->fresh();
        });
    }

    public function markCustomerSeen(Ticket $ticket): void
    {
        if (Schema::hasColumn('tickets', 'customer_last_seen_at')) {
            $ticket->update(['customer_last_seen_at' => now()]);
        }
    }

    public function claimGuestTickets(User $user): int
    {
        if (! Schema::hasColumn('tickets', 'guest_email')) {
            return 0;
        }

        // Guest tickets are claimed only when this browser session created them.
        // Email equality alone is not proof of ownership and allowed impersonation.
        $ticketIds = collect(session('support_guest_ticket_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $sessionEmail = mb_strtolower(trim((string) session('support_guest_email', '')));
        $accountEmail = mb_strtolower(trim((string) ($user->email ?? '')));
        if ($ticketIds === [] || $sessionEmail === '' || $accountEmail === '' || ! hash_equals($accountEmail, $sessionEmail)) {
            return 0;
        }

        $claimed = app(SupportAssignmentMutex::class)->synchronized(fn () => DB::transaction(function () use ($ticketIds, $sessionEmail, $user): int {
            // Lock and claim each eligible row individually so the ownership
            // mutation and its append-only audit event commit or roll back together.
            $tickets = Ticket::query()
                ->whereNull('user_id')
                ->whereIn('id', $ticketIds)
                ->whereRaw('LOWER(guest_email) = ?', [$sessionEmail])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($tickets as $ticket) {
                $ticket->forceFill(['user_id' => $user->id])->save();

                $this->recordSupportAuditEvent($ticket, $user, 'support.ticket.guest_claimed', [
                    'from_user_id' => null,
                    'to_user_id' => (int) $user->id,
                ]);
            }

            return $tickets->count();
        }));

        if ($claimed > 0) {
            session()->forget(['support_guest_ticket_ids', 'support_guest_email', 'support_guest_name']);
        }

        return $claimed;
    }

    public function findOpenGuestTicket(string $email, ?int $ticketId = null): ?Ticket
    {
        $email = mb_strtolower(trim($email));
        $q = Ticket::query()
            ->whereNull('user_id')
            ->whereRaw('LOWER(guest_email) = ?', [$email])
            ->whereIn('status', ['open', 'answered', 'pending', 'user_replied']);

        if ($ticketId) {
            $q->where('id', $ticketId);
        }

        return $q->latest()->first();
    }

    public function waitingMinutes(Ticket $ticket): int
    {
        $from = $ticket->last_reply_at ?? $ticket->created_at;

        return (int) max(0, $from?->diffInMinutes(now()) ?? 0);
    }

    public function needsAgentReply(Ticket $ticket): bool
    {
        return in_array($ticket->status, ['open', 'user_replied'], true);
    }

    /**
     * Build the append-only status transition evidence shared by explicit
     * status changes and reply-driven transitions. The SLA snapshots already
     * preserve the previous closed_at/resolved_at values; reopened_at records
     * the distinct moment this closed ticket entered a new active lifecycle.
     *
     * @return array<string, mixed>
     */
    private function statusTransitionAuditMetadata(string $fromStatus, string $toStatus, array $slaBefore, Ticket $ticket): array
    {
        $metadata = [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'sla_before' => $slaBefore,
            'sla_after' => $this->slaAuditSnapshot($ticket),
        ];

        if ($fromStatus === 'closed' && $toStatus !== 'closed') {
            $metadata['reopened_at'] = now()->toIso8601String();
        }

        return $metadata;
    }

    /**
     * Append a sanitized, checksummed audit event for a state-changing support
     * operation. Message bodies, customer contact details and attachment data
     * are deliberately excluded from this ledger metadata.
     */
    private function recordSupportAuditEvent(Ticket $ticket, ?User $actor, string $eventType, array $metadata): void
    {
        app(AuditEventService::class)->record(
            $eventType,
            $actor?->id,
            null,
            'ticket',
            (int) $ticket->id,
            $metadata,
            'support'
        );
    }

    /**
     * Snapshot only SLA state needed to explain a transition later.
     *
     * @return array<string, mixed>
     */
    private function slaAuditSnapshot(Ticket $ticket): array
    {
        return [
            'first_response_at' => $ticket->first_response_at?->toIso8601String(),
            'first_response_due_at' => $ticket->sla_first_due_at?->toIso8601String(),
            'first_response_warned_at' => $ticket->sla_first_warned_at?->toIso8601String(),
            'first_response_breached_at' => $ticket->sla_first_breached_at?->toIso8601String(),
            'resolution_due_at' => $ticket->sla_resolve_due_at?->toIso8601String(),
            'resolution_warned_at' => $ticket->sla_resolve_warned_at?->toIso8601String(),
            'resolution_paused_at' => $ticket->sla_paused_at?->toIso8601String(),
            'resolution_remaining_minutes' => $ticket->sla_resolve_remaining_minutes,
            'resolution_breached_at' => $ticket->sla_resolve_breached_at?->toIso8601String(),
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
        ];
    }

    /**
     * Persist a newly detected first-response breach in the durable SLA outbox.
     */
    private function enqueueFirstResponseBreachAlert(Ticket $ticket): void
    {
        if (empty($ticket->sla_first_breached_at) || ! $ticket->sla_first_due_at) {
            throw new \RuntimeException('A first-response breach alert requires its canonical SLA marker and deadline.');
        }

        $minutesLeft = $this->sla->minutesUntilDeadline($ticket->sla_first_due_at);

        app(SupportSlaAlertOutboxService::class)->enqueue($ticket, 'first_response', [
            'event' => 'breached',
            'minutes' => $minutesLeft,
            'column' => 'sla_first_breached_at',
        ]);
    }

    /**
     * Persist a newly detected resolution breach in the durable SLA outbox.
     */
    private function enqueueResolutionBreachAlert(Ticket $ticket): void
    {
        if (empty($ticket->sla_resolve_breached_at) || ! $ticket->sla_resolve_due_at) {
            throw new \RuntimeException('A resolution breach alert requires its canonical SLA marker and deadline.');
        }

        $breachAt = $ticket->sla_paused_at ?: now();
        $minutesLeft = $this->sla->minutesUntilDeadline($ticket->sla_resolve_due_at, $breachAt);

        app(SupportSlaAlertOutboxService::class)->enqueue($ticket, 'resolution', [
            'event' => 'breached',
            'minutes' => $minutesLeft,
            'column' => 'sla_resolve_breached_at',
        ]);
    }

    protected function safeNotify(callable $fn, string $event, int $ticketId): void
    {
        try {
            $fn();
        } catch (\Throwable $exception) {
            Log::error('farast.support.notification_delivery_failed', [
                'event' => $event,
                'ticket_id' => $ticketId,
                'exception_class' => get_class($exception),
                'request_id' => request()->attributes->get('request_id'),
                'correlation_id' => request()->header('X-Correlation-Id'),
            ]);

        }
    }
}
