<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
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
        $ticket = DB::transaction(function () use ($user, $data, $attachment) {
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

            // ارجاع خودکار به کم‌بارترین کارشناس
            if (Schema::hasColumn('tickets', 'assigned_to') && empty($data['assigned_to'])) {
                $agent = $this->pickLeastLoadedAgent();
                if ($agent) {
                    $payload['assigned_to'] = $agent->id;
                }
            } elseif (! empty($data['assigned_to']) && Schema::hasColumn('tickets', 'assigned_to')) {
                $payload['assigned_to'] = $data['assigned_to'];
            }

            $ticket = Ticket::create($payload);

            $this->addMessage($ticket, $user, $data['body'], [
                'is_staff' => false,
                'is_internal' => false,
                'attachment' => $attachment,
                'touch_status' => false,
                'notify' => false,
            ]);

            $ticket = $ticket->fresh(['messages', 'user', 'relatedProduct', 'assignee']);
            $this->sla->applyTargets($ticket);

            return $ticket->fresh(['messages', 'user', 'relatedProduct', 'assignee']);
        });

        $this->safeNotify(fn () => $this->notifier->ticketCreated($ticket));

        if ($ticket->assignee) {
            $this->safeNotify(fn () => $this->notifier->assigned($ticket, $ticket->assignee));
        }

        return $ticket;
    }

    /**
     * انتخاب کارشناس با کمترین تیکت باز.
     */
    public function pickLeastLoadedAgent(): ?User
    {
        if (! Schema::hasColumn('tickets', 'assigned_to')) {
            return null;
        }

        $admins = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('is_blocked', false)
            ->limit(50)
            ->get();

        if ($admins->isEmpty()) {
            return null;
        }

        $openCounts = Ticket::query()
            ->whereNotIn('status', ['closed'])
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, COUNT(*) as c')
            ->groupBy('assigned_to')
            ->pluck('c', 'assigned_to');

        return $admins->sortBy(fn (User $u) => (int) ($openCounts[$u->id] ?? 0))->first();
    }

    public function addMessage(Ticket $ticket, ?User $actor, string $body, array $options = []): TicketMessage
    {
        $isStaff = (bool) ($options['is_staff'] ?? false);
        $isInternal = (bool) ($options['is_internal'] ?? false);
        $touchStatus = $options['touch_status'] ?? true;
        $notify = $options['notify'] ?? true;
        $previousStatus = $ticket->status;

        $payload = [
            'user_id' => $actor?->id,
            'body' => $body,
            'is_internal' => $isInternal,
        ];

        if (Schema::hasColumn('ticket_messages', 'is_staff')) {
            $payload['is_staff'] = $isStaff;
        }

        if (! empty($options['attachment']) && $options['attachment'] instanceof UploadedFile
            && Schema::hasColumn('ticket_messages', 'attachments')) {
            $file = $options['attachment'];
            $path = $file->store('ticket-attachments/'.$ticket->id, 'public');
            $payload['attachments'] = [[
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ]];
        }

        $message = $ticket->messages()->create($payload);

        if ($touchStatus && ! $isInternal) {
            $update = [];
            if (Schema::hasColumn('tickets', 'last_reply_at')) {
                $update['last_reply_at'] = now();
            }

            if ($isStaff) {
                $update['status'] = $options['status'] ?? 'answered';
                if (Schema::hasColumn('tickets', 'first_response_at') && empty($ticket->first_response_at)) {
                    $update['first_response_at'] = now();
                }
                if (($options['status'] ?? null) === 'closed' && Schema::hasColumn('tickets', 'closed_at')) {
                    $update['closed_at'] = now();
                }
                if (($options['status'] ?? null) === 'closed' && Schema::hasColumn('tickets', 'resolved_at')) {
                    $update['resolved_at'] = now();
                }
            } else {
                if ($ticket->status !== 'closed') {
                    $update['status'] = 'user_replied';
                }
                if (Schema::hasColumn('tickets', 'closed_at')) {
                    $update['closed_at'] = null;
                }
            }

            if ($update) {
                $ticket->update($update);
            }
        }

        if ($notify && ! $isInternal) {
            $fresh = $ticket->fresh();
            if ($isStaff) {
                $this->safeNotify(fn () => $this->notifier->staffReplied($fresh, $body, false));
                if (($options['status'] ?? null) === 'closed') {
                    $this->safeNotify(fn () => $this->notifier->statusChanged($fresh, $previousStatus, 'closed'));
                }
            } else {
                $this->safeNotify(fn () => $this->notifier->customerReplied($fresh, $body));
            }
        }

        return $message;
    }

    public function changeStatus(Ticket $ticket, string $status, ?User $actor = null, bool $systemNote = true): Ticket
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('وضعیت نامعتبر است.');
        }

        $old = $ticket->status;
        $update = ['status' => $status];
        if ($status === 'closed' && Schema::hasColumn('tickets', 'closed_at')) {
            $update['closed_at'] = now();
        }
        if ($status === 'closed' && Schema::hasColumn('tickets', 'resolved_at')) {
            $update['resolved_at'] = now();
        }
        if ($status !== 'closed' && Schema::hasColumn('tickets', 'closed_at')) {
            $update['closed_at'] = null;
        }
        $ticket->update($update);

        if ($systemNote && $actor) {
            $label = $ticket->fresh()->statusLabel();
            $this->addMessage($ticket, $actor, '[سیستم] وضعیت تیکت به «'.$label.'» تغییر کرد.', [
                'is_staff' => true,
                'is_internal' => true,
                'touch_status' => false,
                'notify' => false,
            ]);
        }

        $fresh = $ticket->fresh();
        $this->safeNotify(fn () => $this->notifier->statusChanged($fresh, $old, $status));

        return $fresh;
    }

    public function assign(Ticket $ticket, ?User $agent, ?User $actor = null): Ticket
    {
        if (! Schema::hasColumn('tickets', 'assigned_to')) {
            return $ticket;
        }

        $ticket->update(['assigned_to' => $agent?->id]);

        if ($actor) {
            $name = $agent?->name ?? $agent?->email ?? 'هیچ‌کس';
            $this->addMessage($ticket, $actor, '[سیستم] تیکت به «'.$name.'» ارجاع شد.', [
                'is_staff' => true,
                'is_internal' => true,
                'touch_status' => false,
                'notify' => false,
            ]);
        }

        $fresh = $ticket->fresh(['assignee']);
        $this->safeNotify(fn () => $this->notifier->assigned($fresh, $agent));

        return $fresh;
    }

    /**
     * ثبت رضایت‌سنجی (CSAT) پس از بستن تیکت — امتیاز ۱ تا ۵.
     */
    public function submitCsat(Ticket $ticket, int $score, ?string $comment = null): Ticket
    {
        if ($ticket->status !== 'closed') {
            throw new \InvalidArgumentException('فقط تیکت بسته‌شده قابل امتیازدهی است.');
        }
        if ($score < 1 || $score > 5) {
            throw new \InvalidArgumentException('امتیاز باید بین ۱ تا ۵ باشد.');
        }
        if (! Schema::hasColumn('tickets', 'csat_score')) {
            return $ticket;
        }
        if ($ticket->csat_at) {
            throw new \InvalidArgumentException('قبلاً امتیاز ثبت شده است.');
        }

        $ticket->update([
            'csat_score' => $score,
            'csat_comment' => $comment ? Str::limit($comment, 500) : null,
            'csat_at' => now(),
        ]);

        return $ticket->fresh();
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

        $claimed = 0;
        $q = Ticket::query()->whereNull('user_id');

        if (filled($user->email)) {
            $email = mb_strtolower(trim((string) $user->email));
            $claimed += (clone $q)->whereRaw('LOWER(guest_email) = ?', [$email])
                ->update(['user_id' => $user->id]);
        }

        // تطبیق با ایمیل session ویجت پس از لاگین
        $sessionEmail = session('support_guest_email');
        if ($sessionEmail && mb_strtolower(trim((string) $sessionEmail)) !== mb_strtolower(trim((string) ($user->email ?? '')))) {
            $se = mb_strtolower(trim((string) $sessionEmail));
            $claimed += Ticket::query()
                ->whereNull('user_id')
                ->whereRaw('LOWER(guest_email) = ?', [$se])
                ->update(['user_id' => $user->id]);
        }

        if ($claimed > 0) {
            session()->forget(['support_guest_email', 'support_guest_name']);
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
