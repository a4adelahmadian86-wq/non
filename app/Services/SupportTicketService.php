<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\WorkflowEngine;
use App\Services\Storage\StorageManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * هسته یکپارچه سیستم پشتیبانی — ایجاد، پاسخ، وضعیت، ارجاع خودکار، مهمان، اطلاع‌رسانی، SLA، CSAT.
 */
class SupportTicketService
{
    public const STATUSES = ['open', 'pending', 'answered', 'user_replied', 'closed'];

    public const DEPARTMENTS = ['support', 'sales', 'billing', 'complaint'];

    public const PRIORITIES = ['low', 'normal', 'high'];

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

        try {
            $ticket = DB::transaction(function () use ($user, $data, $attachment, &$initialMessage) {
                $payload = [
                    'user_id' => $user?->id,
                    'subject' => $data['subject'],
                    'status' => 'open',
                    'priority' => $data['priority'] ?? 'normal',
                    'department' => $data['department'] ?? 'support',
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
                    $payload['source'] = $data['source'] ?? ($user ? 'dashboard' : 'widget');
                }
                if (Schema::hasColumn('tickets', 'last_reply_at')) {
                    $payload['last_reply_at'] = now();
                }
    
                $ticket = Ticket::create($payload);
    
                // Assignment is resolved against the persisted ticket so RBAC scope
                // checks always evaluate the actual record, not an incomplete payload.
                if (Schema::hasColumn('tickets', 'assigned_to')) {
                    $agent = ! empty($data['assigned_to'])
                        ? User::query()->find((int) $data['assigned_to'])
                        : $this->pickLeastLoadedAgent($ticket);
    
                    if ($agent) {
                        abort_unless($this->isEligibleSupportAgent($agent, $ticket), 403);
                        $ticket->forceFill(['assigned_to' => $agent->id])->save();
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

            throw $exception;
        }

        $this->safeNotify(fn () => $this->notifier->ticketCreated($ticket));

        if ($ticket->assignee) {
            $this->safeNotify(fn () => $this->notifier->assigned($ticket, $ticket->assignee));
        }

        return $ticket;
    }

    /**
     * انتخاب کارشناس با کمترین تیکت باز.
     */
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

    public function pickLeastLoadedAgent(Ticket $ticket): ?User
    {
        $agents = $this->eligibleAgentsFor($ticket);
        if ($agents->isEmpty()) {
            return null;
        }

        $openCounts = Ticket::query()
            ->whereNotIn('status', ['closed'])
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, COUNT(*) as c')
            ->groupBy('assigned_to')
            ->pluck('c', 'assigned_to');

        return $agents->sortBy(fn (User $user) => (int) ($openCounts[$user->id] ?? 0))->first();
    }

    public function addMessage(Ticket $ticket, ?User $actor, string $body, array $options = []): TicketMessage
    {
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
            [$message, $previousStatus, $fresh, $resolutionBreachDetected] = DB::transaction(function () use (
                $ticket, $actor, $body, $options, $payload, $isStaff, $isInternal, $touchStatus
            ): array {
                $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
                $previousStatus = (string) $locked->status;
                $resolutionBreachDetected = false;

                // A customer reply must not race past a concurrent ticket close.
                if (! $isStaff && ! $isInternal && $previousStatus === 'closed') {
                    throw new \InvalidArgumentException('این تیکت بسته شده و امکان ارسال پاسخ ندارد.');
                }

                $message = $locked->messages()->create($payload);

                foreach ((array) ($payload['attachments'] ?? []) as $attachment) {
                    if (! empty($attachment['managed_file_id'])) {
                        app(StorageManager::class)->bindReference(
                            (int) $attachment['managed_file_id'],
                            TicketMessage::class,
                            (int) $message->id
                        );
                    }
                }

                if ($touchStatus && ! $isInternal) {
                    $update = [];
                    if (Schema::hasColumn('tickets', 'last_reply_at')) {
                        $update['last_reply_at'] = now();
                    }

                    $targetStatus = $isStaff
                        ? (string) ($options['status'] ?? 'answered')
                        : 'user_replied';

                    if ($isStaff && Schema::hasColumn('tickets', 'first_response_at') && empty($locked->first_response_at)) {
                        $update['first_response_at'] = now();
                    }

                    $transition = $this->buildStatusTransitionUpdate($locked, $targetStatus);
                    $update = array_merge($update, $transition['attributes']);
                    $resolutionBreachDetected = $transition['resolution_breach_detected'];

                    if ($update !== []) {
                        $locked->update($update);
                    }
                }

                return [$message, $previousStatus, $locked->fresh(), $resolutionBreachDetected];
            });
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
            throw $exception;
        }

        if ($temporaryPath !== null && $temporaryPath !== '') {
            \Illuminate\Support\Facades\Storage::disk('private')->delete($temporaryPath);
        }

        if ($resolutionBreachDetected) {
            $this->safeNotify(fn () => $this->notifier->slaBreached($fresh, 'resolution'));
        }

        if ($notify && ! $isInternal) {
            if ($isStaff) {
                $this->safeNotify(fn () => $this->notifier->staffReplied($fresh, $body, false, (int) $message->id));
                if (($options['status'] ?? null) === 'closed') {
                    $this->safeNotify(fn () => $this->notifier->statusChanged($fresh, $previousStatus, 'closed', 'message:'.$message->id));
                }
            } else {
                $this->safeNotify(fn () => $this->notifier->customerReplied($fresh, $body, (int) $message->id));
            }
        }

        return $message;
    }

    /**
     * Build the canonical lifecycle fields for every status change, whether
     * initiated explicitly or as part of a conversation reply. This keeps
     * timestamps and SLA pause/resume behavior consistent across both paths.
     *
     * @return array{attributes: array<string, mixed>, resolution_breach_detected: bool}
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
            'resolution_breach_detected' => ! empty($attributes['sla_resolve_breached_at'])
                && empty($ticket->sla_resolve_breached_at),
        ];
    }

    public function changeStatus(Ticket $ticket, string $status, ?User $actor = null, bool $systemNote = true): Ticket
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('وضعیت نامعتبر است.');
        }

        [$fresh, $old, $transitionMessageId, $changed, $resolutionBreachDetected] = DB::transaction(function () use ($ticket, $status, $actor, $systemNote): array {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $old = (string) $locked->status;

            if ($old === $status) {
                return [$locked->fresh(), $old, null, false, false];
            }

            $transition = $this->buildStatusTransitionUpdate($locked, $status);
            $locked->update($transition['attributes']);
            $resolutionBreachDetected = $transition['resolution_breach_detected'];

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

            return [$locked->fresh(), $old, $transitionMessage?->id, true, $resolutionBreachDetected];
        });

        if (! $changed) {
            return $fresh;
        }

        if ($resolutionBreachDetected) {
            $this->safeNotify(fn () => $this->notifier->slaBreached($fresh, 'resolution'));
        }

        $this->safeNotify(fn () => $this->notifier->statusChanged(
            $fresh,
            $old,
            $status,
            $transitionMessageId ? 'message:'.$transitionMessageId : 'transition:'.($fresh->updated_at?->format('U.u') ?? 'unknown')
        ));

        return $fresh;
    }

    public function assign(Ticket $ticket, ?User $agent, ?User $actor = null): Ticket
    {
        if (! Schema::hasColumn('tickets', 'assigned_to')) {
            return $ticket;
        }

        [$fresh, $assignmentMessageId, $changed] = DB::transaction(function () use ($ticket, $agent, $actor): array {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if ((int) ($locked->assigned_to ?? 0) === (int) ($agent?->id ?? 0)) {
                return [$locked->fresh(['assignee']), null, false];
            }

            if ($agent !== null) {
                abort_unless($this->isEligibleSupportAgent($agent, $locked), 403);
            }

            $locked->update(['assigned_to' => $agent?->id]);

            $assignmentMessage = null;
            if ($actor) {
                $name = $agent?->name ?? $agent?->email ?? 'هیچ‌کس';
                $assignmentMessage = $this->addMessage($locked, $actor, '[سیستم] تیکت به «'.$name.'» ارجاع شد.', [
                    'is_staff' => true,
                    'is_internal' => true,
                    'touch_status' => false,
                    'notify' => false,
                ]);
            }

            return [$locked->fresh(['assignee']), $assignmentMessage?->id, true];
        });

        if (! $changed) {
            return $fresh;
        }

        $this->safeNotify(fn () => $this->notifier->assigned(
            $fresh,
            $agent,
            $assignmentMessageId ? 'message:'.$assignmentMessageId : 'assignment:'.($fresh->updated_at?->format('U.u') ?? 'unknown')
        ));

        return $fresh;
    }

    /**
     * به‌روزرسانی اولویت / دپارتمان؛ در صورت تغییر اولویت، مهلت SLA از نو محاسبه می‌شود.
     */
    public function updateMeta(Ticket $ticket, array $data, ?User $actor = null): Ticket
    {
        [$fresh, $resolutionBreachDetected] = DB::transaction(function () use ($ticket, $data, $actor): array {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $wasResolutionBreached = ! empty($locked->sla_resolve_breached_at);
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
                return [$locked->fresh(['assignee', 'user']), false];
            }

            $locked->update($update);
            if (isset($update['priority'])) {
                $this->sla->applyTargets($locked);
            }

            if ($actor && $notes) {
                $this->addMessage($locked, $actor, '[سیستم] '.implode(' و ', $notes).' تغییر کرد.', [
                    'is_staff' => true,
                    'is_internal' => true,
                    'touch_status' => false,
                    'notify' => false,
                ]);
            }

            $resolutionBreachDetected = ! $wasResolutionBreached && ! empty($locked->sla_resolve_breached_at);

            return [$locked->fresh(['assignee', 'user']), $resolutionBreachDetected];
        });

        if ($resolutionBreachDetected) {
            $this->safeNotify(fn () => $this->notifier->slaBreached($fresh, 'resolution'));
        }

        return $fresh;
    }

    /**
     * ثبت رضایت‌سنجی (CSAT) پس از بستن تیکت — امتیاز ۱ تا ۵.
     */
    public function submitCsat(Ticket $ticket, int $score, ?string $comment = null): Ticket
    {
        if ($score < 1 || $score > 5) {
            throw new \InvalidArgumentException('امتیاز باید بین ۱ تا ۵ باشد.');
        }
        if (! Schema::hasColumn('tickets', 'csat_score')) {
            return $ticket;
        }

        return DB::transaction(function () use ($ticket, $score, $comment): Ticket {
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

        $claimed = Ticket::query()
            ->whereNull('user_id')
            ->whereIn('id', $ticketIds)
            ->whereRaw('LOWER(guest_email) = ?', [$sessionEmail])
            ->update(['user_id' => $user->id]);

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

    protected function safeNotify(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('farast.support.notify_error', ['error' => $e->getMessage()]);
        }
    }
}
