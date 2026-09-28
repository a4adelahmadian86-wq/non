<?php

namespace App\Services;

use App\Mail\OrderPaidMail;
use App\Mail\OtpMail;
use App\Mail\PasswordChangedMail;
use App\Mail\TestMail;
use App\Mail\TicketReplyMail;
use App\Mail\WelcomeMail;
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
        'password_changed' => 'تغییر رمز',
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
            'orderId' => (int) $order->id,
            'amount' => (int) $order->total_rials,
            'paidAt' => optional($order->paid_at)->format('Y/m/d H:i') ?: now()->format('Y/m/d H:i'),
            'editorUrl' => route('editor'),
        ];

        $this->dispatch(
            type: 'order_paid',
            to: $user->email,
            user: $user,
            subject: 'تأیید پرداخت سفارش #'.$order->id,
            mailable: new OrderPaidMail(...$data),
            htmlView: 'emails.order-paid',
            viewData: $data,
            meta: ['order_id' => $order->id, 'amount' => $order->total_rials],
        );
    }

    public function sendTicketReply(User $user, Ticket $ticket, string $messageBody): void
    {
        if (! $user->email || ! $this->isEnabled('ticket_reply')) {
            return;
        }

        $data = [
            'name' => $user->name ?: 'کاربر',
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'messageBody' => mb_substr($messageBody, 0, 800),
            'supportUrl' => route('support'),
        ];

        $this->dispatch(
            type: 'ticket_reply',
            to: $user->email,
            user: $user,
            subject: 'پاسخ جدید به تیکت: '.$ticket->subject,
            mailable: new TicketReplyMail(
                name: $data['name'],
                subjectLine: $ticket->subject,
                status: $ticket->status,
                messageBody: $data['messageBody'],
                supportUrl: $data['supportUrl'],
            ),
            htmlView: 'emails.ticket-reply',
            viewData: $data,
            meta: ['ticket_id' => $ticket->id],
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
        $this->mailConfig->apply();
        $provider = $this->mailConfig->currentProvider();

        if ($provider === 'sendmail' && ! $this->mailConfig->sendmailBinaryAvailable()) {
            throw new \RuntimeException(
                'Sendmail روی این سیستم نصب نیست (روی ویندوز معمول است). در پنل ایمیل، سرویس‌دهنده را روی «لاگ داخلی سایت» بگذارید و دوباره تست کنید. متن ایمیل در storage/logs ذخیره می‌شود.'
            );
        }

        $this->dispatch(
            type: 'test',
            to: $email,
            user: null,
            subject: 'ایمیل آزمایشی فراست',
            mailable: new TestMail(),
            htmlView: 'emails.test',
            viewData: [],
        );
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
