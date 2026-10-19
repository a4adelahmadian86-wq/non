<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * کانال‌های اطلاع‌رسانی پشتیبانی: اعلان داخل پنل + ایمیل + پیامک
 * مطابق الگوی رایج هلپ‌دسک‌های ایرانی و بین‌المللی.
 */
class SupportNotificationService
{
    public function __construct(
        private readonly EmailService $email,
        private readonly SmsService $sms,
    ) {}

    public function ticketCreated(Ticket $ticket): void
    {
        $ticket->loadMissing(['user', 'assignee']);

        // تأیید برای صاحب تیکت
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

        // اعلان به ادمین‌ها / کارشناسان
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

        // پیامک به ادمین (اختیاری)
        if ($this->smsEnabled('ticket_created_staff')) {
            $mobile = $this->staffAlertMobile();
            if ($mobile) {
                $this->sms->sendText(
                    $mobile,
                    'فراست: تیکت جدید '.$ticket->displayCode().' — '.Str::limit($ticket->subject, 40)
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
                $this->sms->sendText(
                    $mobile,
                    'فراست: پاسخ فوری کاربر روی '.$ticket->displayCode()
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
                $this->sms->sendText(
                    $ticket->user->mobile,
                    'فراست: پاسخ جدید برای تیکت '.$ticket->displayCode().'. مشاهده در پنل پشتیبانی.'
                );
            }
        } elseif ($ticket->guest_email) {
            // مهمان: فقط ایمیل
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
                'در صورت نیاز می‌توانید تیکت جدید ثبت کنید.',
                $ticket
            );
            $this->email->sendTicketClosed($ticket->user, $ticket);

            if ($this->smsEnabled('ticket_closed') && $ticket->user?->mobile) {
                $this->sms->sendText(
                    $ticket->user->mobile,
                    'فراست: تیکت '.$ticket->displayCode().' بسته شد.'
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
            $this->sms->sendText(
                $agent->mobile,
                'فراست: تیکت '.$ticket->displayCode().' به شما ارجاع شد.'
            );
        }
    }

    protected function pushInApp(int $userId, string $type, string $title, string $body, Ticket $ticket, bool $staff = false): void
    {
        if (! Schema::hasTable('farast_notifications')) {
            return;
        }

        try {
            $url = $staff
                ? route('admin.support.management', ['ticket' => $ticket->id])
                : route('support.show', $ticket);

            DB::table('farast_notifications')->insert([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => json_encode([
                    'ticket_id' => $ticket->id,
                    'ticket_code' => $ticket->ticket_code,
                    'url' => $url,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
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
