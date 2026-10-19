<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * اطلاع‌رسانی رویدادهای پشتیبانی از طریق اعلان پنل + ایمیل + پیامک
 */
class SupportNotificationService
{
    public function __construct(
        private readonly EmailService $email,
        private readonly SmsService $sms,
        private readonly NotificationService $notifications,
    ) {}

    public function ticketCreated(Ticket $ticket): void
    {
        $ticket->loadMissing(['user', 'assignee']);

        if ($ticket->user) {
            $this->pushInApp(
                $ticket->user_id,
                'ticket_created',
                'تیکت '.$ticket->displayCode().' ثبت شد',
                'موضوع: '.$ticket->subject,
                $ticket
            );
            $this->email->sendTicketCreated($ticket->user, $ticket);
        }

        foreach ($this->staffUsers() as $admin) {
            $this->pushInApp(
                $admin->id,
                'ticket_created_staff',
                'تیکت جدید '.$ticket->displayCode(),
                $ticket->displayName().' — '.$ticket->subject,
                $ticket,
                staff: true
            );
            $this->email->sendTicketCreatedStaff($admin, $ticket);
        }

        if ($this->smsEnabled('ticket_created_staff')) {
            $mobile = $this->staffAlertMobile();
            if ($mobile) {
                $this->sms->sendEvent(
                    $mobile,
                    'ticket_created_staff',
                    'فراست: تیکت جدید '.$ticket->displayCode().' — '.Str::limit($ticket->subject, 40),
                    [
                        'token' => $ticket->displayCode(),
                        'token2' => Str::limit($ticket->subject, 30),
                    ]
                );
            }
        }
    }

    public function customerReplied(Ticket $ticket, string $body): void
    {
        $ticket->loadMissing(['user', 'assignee']);

        $targets = collect();
        if ($ticket->assignee) {
            $targets->push($ticket->assignee);
        } else {
            $targets = $targets->merge($this->staffUsers());
        }

        foreach ($targets->unique('id') as $staff) {
            $this->pushInApp(
                $staff->id,
                'ticket_customer_reply',
                'پاسخ کاربر روی '.$ticket->displayCode(),
                Str::limit($body, 120),
                $ticket,
                staff: true
            );
            $this->email->sendTicketCustomerReply($staff, $ticket, $body);
        }

        if ($this->smsEnabled('ticket_customer_reply') && $ticket->priority === 'high') {
            $mobile = $ticket->assignee?->mobile ?: $this->staffAlertMobile();
            if ($mobile) {
                $this->sms->sendEvent(
                    $mobile,
                    'ticket_customer_reply',
                    'فراست: پاسخ فوری کاربر روی '.$ticket->displayCode(),
                    ['token' => $ticket->displayCode()]
                );
            }
        }
    }

    public function staffReplied(Ticket $ticket, string $body, bool $isInternal = false): void
    {
        if ($isInternal) {
            return;
        }

        $ticket->loadMissing(['user']);

        if ($ticket->user_id) {
            $this->pushInApp(
                $ticket->user_id,
                'ticket_reply',
                'پاسخ جدید به تیکت '.$ticket->displayCode(),
                Str::limit($body, 160),
                $ticket
            );
            $this->email->sendTicketReply($ticket->user, $ticket, $body);

            if ($this->smsEnabled('ticket_reply') && $ticket->user?->mobile) {
                $this->sms->sendEvent(
                    $ticket->user->mobile,
                    'ticket_reply',
                    'فراست: پاسخ جدید برای تیکت '.$ticket->displayCode().'. مشاهده در پنل پشتیبانی.',
                    ['token' => $ticket->displayCode()]
                );
            }
        } elseif ($ticket->guest_email) {
            $this->email->sendTicketReplyGuest($ticket, $body);
        }
    }

    public function statusChanged(Ticket $ticket, string $oldStatus, string $newStatus): void
    {
        if ($oldStatus === $newStatus) {
            return;
        }

        $ticket->loadMissing(['user']);

        if ($newStatus === 'closed' && $ticket->user_id) {
            $this->pushInApp(
                $ticket->user_id,
                'ticket_closed',
                'تیکت '.$ticket->displayCode().' بسته شد',
                'در صورت نیاز می‌توانید تیکت جدید ثبت کنید یا به کیفیت پشتیبانی امتیاز دهید.',
                $ticket
            );
            $this->email->sendTicketClosed($ticket->user, $ticket);

            if ($this->smsEnabled('ticket_closed') && $ticket->user?->mobile) {
                $this->sms->sendEvent(
                    $ticket->user->mobile,
                    'ticket_closed',
                    'فراست: تیکت '.$ticket->displayCode().' بسته شد.',
                    ['token' => $ticket->displayCode()]
                );
            }
        }
    }

    public function assigned(Ticket $ticket, ?User $agent): void
    {
        if (! $agent) {
            return;
        }

        $this->pushInApp(
            $agent->id,
            'ticket_assigned',
            'تیکت '.$ticket->displayCode().' به شما ارجاع شد',
            $ticket->subject,
            $ticket,
            staff: true
        );
        $this->email->sendTicketAssigned($agent, $ticket);

        if ($this->smsEnabled('ticket_assigned') && $agent->mobile) {
            $this->sms->sendEvent(
                $agent->mobile,
                'ticket_assigned',
                'فراست: تیکت '.$ticket->displayCode().' به شما ارجاع شد.',
                ['token' => $ticket->displayCode()]
            );
        }
    }

    /** @param  'first_response'|'resolution'  $kind */
    public function slaWarning(Ticket $ticket, string $kind, int $minutesLeft): void
    {
        $ticket->loadMissing(['assignee', 'user']);
        $label = $kind === 'resolution' ? 'مهلت حل' : 'اولین پاسخ';
        $title = 'هشدار SLA — '.$ticket->displayCode();
        $body = $label.' حدود '.$minutesLeft.' دقیقه دیگر به پایان می‌رسد. موضوع: '.$ticket->subject;

        foreach ($this->staffTargets($ticket) as $staff) {
            $this->pushInApp($staff->id, 'ticket_sla_warning', $title, $body, $ticket, staff: true);
            try {
                if ($staff->email) {
                    $this->email->sendTicketSlaAlert($staff, $ticket, $title, $body);
                }
            } catch (\Throwable) {
            }
        }

        if ($ticket->priority === 'high' && $this->smsEnabled('ticket_sla_warning')) {
            $mobile = $ticket->assignee?->mobile ?: $this->staffAlertMobile();
            if ($mobile) {
                $this->sms->sendEvent(
                    $mobile,
                    'ticket_sla_warning',
                    'فراست SLA: '.$ticket->displayCode().' — '.$minutesLeft.'د تا '.$label,
                    [
                        'token' => $ticket->displayCode(),
                        'token2' => (string) $minutesLeft,
                    ]
                );
            }
        }
    }

    /** @param  'first_response'|'resolution'  $kind */
    public function slaBreached(Ticket $ticket, string $kind): void
    {
        $ticket->loadMissing(['assignee', 'user']);
        $label = $kind === 'resolution' ? 'مهلت حل' : 'اولین پاسخ';
        $title = 'نقض SLA — '.$ticket->displayCode();
        $body = $label.' برای این تیکت به پایان رسیده است. موضوع: '.$ticket->subject;

        foreach ($this->staffTargets($ticket) as $staff) {
            $this->pushInApp($staff->id, 'ticket_sla_breach', $title, $body, $ticket, staff: true);
            try {
                if ($staff->email) {
                    $this->email->sendTicketSlaAlert($staff, $ticket, $title, $body);
                }
            } catch (\Throwable) {
            }
        }

        if ($this->smsEnabled('ticket_sla_breach')) {
            $mobile = $ticket->assignee?->mobile ?: $this->staffAlertMobile();
            if ($mobile) {
                $this->sms->sendEvent(
                    $mobile,
                    'ticket_sla_breach',
                    'فراست نقض SLA: '.$ticket->displayCode().' — '.$label,
                    ['token' => $ticket->displayCode()]
                );
            }
        }
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    protected function staffTargets(Ticket $ticket)
    {
        if ($ticket->assignee) {
            return collect([$ticket->assignee]);
        }

        return $this->staffUsers();
    }

    protected function pushInApp(int $userId, string $type, string $title, string $body, Ticket $ticket, bool $staff = false): void
    {
        try {
            $url = $staff
                ? route('admin.support.management', ['ticket' => $ticket->id])
                : route('support.show', $ticket);

            $this->notifications->push($userId, $type, $title, $body, [
                'ticket_id' => $ticket->id,
                'ticket_code' => $ticket->ticket_code,
                'url' => $url,
            ]);
        } catch (\Throwable $e) {
            Log::warning('farast.support.notification_failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    protected function staffUsers()
    {
        try {
            return User::query()
                ->where('role', User::ROLE_ADMIN)
                ->limit(30)
                ->get();
        } catch (\Throwable) {
            return User::all()->filter(fn ($u) => $u->isAdmin())->take(30)->values();
        }
    }

    protected function staffAlertMobile(): ?string
    {
        $fromSetting = SiteSetting::read('support_alert_mobile', null);
        if ($fromSetting) {
            return preg_replace('/\D+/', '', (string) $fromSetting) ?: null;
        }

        $admin = $this->staffUsers()->first(fn ($u) => filled($u->mobile));

        return $admin?->mobile ? preg_replace('/\D+/', '', $admin->mobile) : null;
    }

    protected function smsEnabled(string $event): bool
    {
        $global = filter_var(SiteSetting::read('sms_enabled', true), FILTER_VALIDATE_BOOLEAN);
        if (! $global) {
            return false;
        }

        return filter_var(SiteSetting::read('sms_'.$event.'_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }
}
