<?php

namespace App\Services;

use App\Mail\AnnouncementMail;
use App\Mail\OrderPaidMail;
use App\Mail\OtpMail;
use App\Mail\PasswordChangedMail;
use App\Mail\SupportTicketMail;
use App\Mail\TestMail;
use App\Mail\TicketReplyMail;
use App\Mail\WelcomeMail;
use App\Models\Announcement;
use App\Models\EmailLog;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Throwable;

class EmailService
{
    public const TYPES = [
        'otp' => 'کد OTP',
        'welcome' => 'خوش‌آمدگویی',
        'order_paid' => 'تأیید پرداخت',
        'ticket_reply' => 'پاسخ تیکت',
        'ticket_created' => 'ثبت تیکت',
        'ticket_created_staff' => 'تیکت جدید (کارشناس)',
        'ticket_customer_reply' => 'پاسخ کاربر به تیکت',
        'ticket_closed' => 'بستن تیکت',
        'ticket_assigned' => 'ارجاع تیکت',
        'ticket_sla_warning' => 'هشدار SLA',
        'ticket_sla_breach' => 'نقض SLA',
        'password_changed' => 'تغییر رمز',
        'announcement' => 'اعلان عمومی',
        'test' => 'ایمیل آزمایشی',
    ];

    public function __construct(protected MailConfigService $mailConfig) {}

    public function isEnabled(string $type): bool
    {
        $global = filter_var(SiteSetting::read('email_enabled', true), FILTER_VALIDATE_BOOLEAN);
        if (! $global) {
            return false;
        }

        return filter_var(SiteSetting::read('email_'.$type.'_enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    public function sendOtp(string $email, string $code, ?User $user = null): void
    {
        if (! $this->isEnabled('otp')) {
            Log::info('farast.email.otp_skipped_disabled', ['email_hash' => $this->hash($email)]);

            return;
        }

        $this->dispatch(
            type: 'otp',
            to: $email,
            user: $user,
            subject: 'کد تأیید ورود به فراست',
            mailable: new OtpMail($code),
            htmlView: 'emails.otp',
            viewData: ['code' => $code],
            meta: ['code_length' => strlen($code)],
        );
    }

    public function sendWelcome(User $user): void
    {
        if (! $user->email || ! $this->isEnabled('welcome')) {
            return;
        }

        $data = [
            'name' => $user->name ?: 'کاربر',
            'mobile' => $user->mobile,
            'email' => $user->email,
            'editorUrl' => route('editor'),
        ];

        $this->dispatch(
            type: 'welcome',
            to: $user->email,
            user: $user,
            subject: 'خوش آمدید به فراست',
            mailable: new WelcomeMail(...$data),
            htmlView: 'emails.welcome',
            viewData: $data,
        );
    }

    public function sendOrderPaid(User $user, Order $order): void
    {
        if (! $user->email || ! $this->isEnabled('order_paid')) {
            return;
        }

        $data = [
            'name' => $user->name ?: 'کاربر',
            'orderId' => $order->id,
            'amount' => $order->amount ?? null,
        ];

        try {
            $this->dispatch(
                type: 'order_paid',
                to: $user->email,
                user: $user,
                subject: 'تأیید پرداخت سفارش #'.$order->id,
                mailable: new OrderPaidMail($user, $order),
                htmlView: 'emails.order-paid',
                viewData: $data,
                meta: ['order_id' => $order->id],
            );
        } catch (Throwable $e) {
            Log::warning('farast.email.order_paid_failed', ['error' => $e->getMessage()]);
        }
    }

    public function sendTicketReply(User $user, Ticket $ticket, string $messageBody): void
    {
        if (! $user->email || ! $this->isEnabled('ticket_reply')) {
            return;
        }

        $url = $this->ticketUrl($ticket, false);
        $status = $ticket->statusLabel();
        $code = $ticket->displayCode();

        $data = [
            'name' => $user->name ?: 'کاربر',
            'subject' => $ticket->subject,
            'status' => $status,
            'messageBody' => mb_substr($messageBody, 0, 800),
            'supportUrl' => $url,
            'ticketCode' => $code,
            'headline' => 'پاسخ جدید به تیکت پشتیبانی',
        ];

        $this->dispatch(
            type: 'ticket_reply',
            to: $user->email,
            user: $user,
            subject: 'پاسخ جدید به تیکت '.$code,
            mailable: new TicketReplyMail(
                name: $data['name'],
                subjectLine: $ticket->subject,
                status: $status,
                messageBody: $data['messageBody'],
                supportUrl: $url,
            ),
            htmlView: 'emails.ticket-reply',
            viewData: $data,
            meta: ['ticket_id' => $ticket->id],
        );
    }

    public function sendTicketReplyGuest(Ticket $ticket, string $messageBody): void
    {
        $email = $ticket->guest_email;
        if (! $email || ! $this->isEnabled('ticket_reply')) {
            return;
        }

        $this->sendSupportMail(
            type: 'ticket_reply',
            to: $email,
            user: null,
            ticket: $ticket,
            headline: 'پاسخ جدید به تیکت پشتیبانی',
            body: mb_substr($messageBody, 0, 800),
            staffUrl: false,
            name: $ticket->guest_name ?: 'کاربر',
        );
    }

    public function sendTicketCreated(User $user, Ticket $ticket): void
    {
        if (! $user->email || ! $this->isEnabled('ticket_created')) {
            return;
        }

        $this->sendSupportMail(
            type: 'ticket_created',
            to: $user->email,
            user: $user,
            ticket: $ticket,
            headline: 'تیکت شما ثبت شد',
            body: 'تیکت شما با موفقیت ثبت شد و کارشناسان ما به‌زودی پاسخ می‌دهند.',
            staffUrl: false,
        );
    }

    public function sendTicketCreatedStaff(User $staff, Ticket $ticket): void
    {
        if (! $staff->email || ! $this->isEnabled('ticket_created_staff')) {
            return;
        }

        $this->sendSupportMail(
            type: 'ticket_created_staff',
            to: $staff->email,
            user: $staff,
            ticket: $ticket,
            headline: 'تیکت پشتیبانی جدید',
            body: 'از طرف '.$ticket->displayName()."\n\n".'موضوع: '.$ticket->subject,
            staffUrl: true,
        );
    }

    public function sendTicketCustomerReply(User $staff, Ticket $ticket, string $body): void
    {
        if (! $staff->email || ! $this->isEnabled('ticket_customer_reply')) {
            return;
        }

        $this->sendSupportMail(
            type: 'ticket_customer_reply',
            to: $staff->email,
            user: $staff,
            ticket: $ticket,
            headline: 'پاسخ جدید کاربر',
            body: mb_substr($body, 0, 800),
            staffUrl: true,
        );
    }

    public function sendTicketClosed(User $user, Ticket $ticket): void
    {
        if (! $user->email || ! $this->isEnabled('ticket_closed')) {
            return;
        }

        $this->sendSupportMail(
            type: 'ticket_closed',
            to: $user->email,
            user: $user,
            ticket: $ticket,
            headline: 'تیکت بسته شد',
            body: 'تیکت شما بسته شد. در صورت نیاز می‌توانید تیکت جدیدی ثبت کنید.',
            staffUrl: false,
        );
    }

    public function sendTicketAssigned(User $agent, Ticket $ticket): void
    {
        if (! $agent->email || ! $this->isEnabled('ticket_assigned')) {
            return;
        }

        $this->sendSupportMail(
            type: 'ticket_assigned',
            to: $agent->email,
            user: $agent,
            ticket: $ticket,
            headline: 'تیکت به شما ارجاع شد',
            body: 'موضوع: '.$ticket->subject,
            staffUrl: true,
        );
    }

    public function sendTicketSlaAlert(User $staff, Ticket $ticket, string $headline, string $body): void
    {
        if (! $staff->email || ! $this->isEnabled('ticket_sla_warning')) {
            return;
        }

        $this->sendSupportMail(
            type: 'ticket_sla_warning',
            to: $staff->email,
            user: $staff,
            ticket: $ticket,
            headline: $headline,
            body: $body,
            staffUrl: true,
        );
    }

    public function sendSystemAnnouncement(User $user, Announcement $announcement): void
    {
        if (! $user->email || ! $this->isEnabled('announcement')) {
            return;
        }

        $subject = match ($announcement->type) {
            'danger' => 'اعلان مهم: '.$announcement->title,
            'warning' => 'هشدار: '.$announcement->title,
            default => 'اعلان فراست: '.$announcement->title,
        };

        $name = $user->name ?: 'کاربر';
        $link = $announcement->link_url ?: url('/announcements');

        $this->dispatch(
            type: 'announcement',
            to: $user->email,
            user: $user,
            subject: $subject,
            mailable: new AnnouncementMail(
                name: $name,
                title: $announcement->title,
                body: $announcement->body,
                type: $announcement->type,
                link: $link,
                subjectLine: $subject,
            ),
            htmlView: 'emails.announcement',
            viewData: [
                'name' => $name,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'type' => $announcement->type,
                'link' => $link,
            ],
            meta: ['announcement_id' => $announcement->id],
        );
    }

    public function sendPasswordChanged(User $user): void
    {
        if (! $user->email || ! $this->isEnabled('password_changed')) {
            return;
        }

        $data = [
            'name' => $user->name ?: 'کاربر',
            'loginUrl' => route('login'),
        ];

        $this->dispatch(
            type: 'password_changed',
            to: $user->email,
            user: $user,
            subject: 'رمز عبور حساب شما تغییر کرد',
            mailable: new PasswordChangedMail(...$data),
            htmlView: 'emails.password-changed',
            viewData: $data,
        );
    }

    public function sendTest(string $email): void
    {
        $this->dispatch(
            type: 'test',
            to: $email,
            user: null,
            subject: 'ایمیل آزمایشی فراست',
            mailable: new TestMail,
            htmlView: 'emails.test',
            viewData: [],
        );
    }

    protected function sendSupportMail(
        string $type,
        string $to,
        ?User $user,
        Ticket $ticket,
        string $headline,
        string $body,
        bool $staffUrl,
        ?string $name = null,
    ): void {
        $url = $this->ticketUrl($ticket, $staffUrl);
        $status = $ticket->statusLabel();
        $code = $ticket->displayCode();
        $displayName = $name ?: ($user?->name ?: 'کاربر');
        $subject = $headline.' — '.$code;

        $data = [
            'headline' => $headline,
            'name' => $displayName,
            'subject' => $ticket->subject,
            'status' => $status,
            'messageBody' => $body,
            'supportUrl' => $url,
            'ticketCode' => $code,
        ];

        $this->dispatch(
            type: $type,
            to: $to,
            user: $user,
            subject: $subject,
            mailable: new SupportTicketMail(
                headline: $headline,
                name: $displayName,
                subjectLine: $ticket->subject,
                status: $status,
                messageBody: $body,
                supportUrl: $url,
                ticketCode: $code,
                mailSubject: $subject,
            ),
            htmlView: 'emails.support-ticket',
            viewData: $data,
            meta: ['ticket_id' => $ticket->id],
        );
    }

    protected function ticketUrl(Ticket $ticket, bool $staff): string
    {
        try {
            return $staff
                ? route('admin.support.management', ['ticket' => $ticket->id])
                : route('support.show', $ticket);
        } catch (Throwable) {
            return url('/support');
        }
    }

    protected function dispatch(
        string $type,
        string $to,
        ?User $user,
        string $subject,
        object $mailable,
        string $htmlView,
        array $viewData = [],
        array $meta = [],
    ): void {
        $this->mailConfig->apply();

        $log = EmailLog::create([
            'type' => $type,
            'to_email' => mb_strtolower(trim($to)),
            'user_id' => $user?->id,
            'subject' => $subject,
            'status' => 'queued',
            'meta' => array_merge($meta, ['provider' => $this->mailConfig->currentProvider()]),
        ]);

        try {
            $provider = $this->mailConfig->currentProvider();

            if ($provider === 'resend') {
                $this->sendViaResendApi($to, $subject, $htmlView, $viewData);
            } else {
                $selfHosted = in_array($provider, ['sendmail', 'local', 'smtp', 'log'], true);
                $forceSync = $selfHosted
                    || config('queue.default') === 'sync'
                    || filter_var(SiteSetting::read('email_sync', true), FILTER_VALIDATE_BOOLEAN);

                if ($forceSync) {
                    Mail::to($to)->send($mailable);
                } else {
                    Mail::to($to)->queue($mailable);
                }
            }

            $log->update(['status' => 'sent', 'sent_at' => now()]);
            Log::info('farast.email.sent', [
                'type' => $type,
                'provider' => $provider,
                'email_hash' => $this->hash($to),
                'log_id' => $log->id,
            ]);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains(strtolower($msg), 'mailpit')) {
                $msg = 'اتصال به mailpit ممکن نیست. این host فقط داخل Docker کار می‌کند. در پنل ایمیل سرویس‌دهنده را «لاگ داخلی» بگذارید یا SMTP را روی 127.0.0.1 تنظیم کنید.';
            } elseif (str_contains(strtolower($msg), 'sendmail')) {
                $msg = 'Sendmail در دسترس نیست. روی ویندوز از «لاگ داخلی» استفاده کنید؛ روی سرور لینوکس Postfix/Sendmail نصب کنید.';
            }

            $log->update([
                'status' => 'failed',
                'error' => mb_substr($msg, 0, 1000),
            ]);

            Log::error('farast.email.failed', [
                'type' => $type,
                'email_hash' => $this->hash($to),
                'error' => $msg,
            ]);

            if ($type !== 'test') {
                return;
            }

            throw new \RuntimeException($msg, 0, $e);
        }
    }

    protected function sendViaResendApi(string $to, string $subject, string $htmlView, array $viewData): void
    {
        $apiKey = SiteSetting::read('resend_api_key', env('RESEND_API_KEY'));
        if (! filled($apiKey)) {
            throw new \RuntimeException('کلید Resend تنظیم نشده است.');
        }

        $html = View::make($htmlView, array_merge($viewData, ['subject' => $subject]))->render();

        $from = config('mail.from.address');
        $fromName = config('mail.from.name');

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->post('https://api.resend.com/emails', [
                'from' => "{$fromName} <{$from}>",
                'to' => [$to],
                'subject' => $subject,
                'html' => $html,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Resend API: '.$response->body());
        }
    }

    protected function hash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }
}
