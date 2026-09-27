<?php

namespace App\Services;

use App\Models\SiteSetting;

/**
 * اعمال تنظیمات سرویس ایمیل از پنل ادمین / .env
 * پشتیبانی از سرویس‌های رایگان رایج: Log, SMTP, Mailtrap, Brevo, Resend
 */
class MailConfigService
{
    public const PROVIDERS = [
        'log' => 'Log (فقط توسعه محلی)',
        'smtp' => 'SMTP عمومی',
        'mailtrap' => 'Mailtrap (رایگان برای تست)',
        'brevo' => 'Brevo / Sendinblue (رایگان ~۳۰۰/روز)',
        'resend' => 'Resend (رایگان ~۳۰۰۰/ماه)',
    ];

    public function apply(): void
    {
        $provider = (string) SiteSetting::read('mail_provider', env('MAIL_MAILER', 'log'));

        $fromAddress = SiteSetting::read('mail_from_address', env('MAIL_FROM_ADDRESS', 'noreply@example.com'));
        $fromName = SiteSetting::read('mail_from_name', env('MAIL_FROM_NAME', 'FARAST'));

        config([
            'mail.from.address' => $fromAddress,
            'mail.from.name' => $fromName,
        ]);

        match ($provider) {
            'mailtrap' => $this->applySmtpPreset(
                host: SiteSetting::read('mail_host', 'sandbox.smtp.mailtrap.io'),
                port: (int) SiteSetting::read('mail_port', 2525),
                username: SiteSetting::read('mail_username', env('MAIL_USERNAME')),
                password: SiteSetting::read('mail_password', env('MAIL_PASSWORD')) ?: null,
                encryption: SiteSetting::read('mail_encryption', 'tls'),
            ),
            'brevo' => $this->applySmtpPreset(
                host: SiteSetting::read('mail_host', 'smtp-relay.brevo.com'),
                port: (int) SiteSetting::read('mail_port', 587),
                username: SiteSetting::read('mail_username', env('MAIL_USERNAME')),
                password: SiteSetting::read('mail_password', env('MAIL_PASSWORD')) ?: SiteSetting::read('brevo_api_key', env('BREVO_API_KEY')) ?: null,
                encryption: SiteSetting::read('mail_encryption', 'tls'),
            ),
            'resend' => $this->applyResend(
                SiteSetting::read('resend_api_key', env('RESEND_API_KEY'))
            ),
            'smtp' => $this->applySmtpPreset(
                host: SiteSetting::read('mail_host', env('MAIL_HOST', '127.0.0.1')),
                port: (int) SiteSetting::read('mail_port', env('MAIL_PORT', 587)),
                username: SiteSetting::read('mail_username', env('MAIL_USERNAME')),
                password: SiteSetting::read('mail_password', env('MAIL_PASSWORD')) ?: null,
                encryption: SiteSetting::read('mail_encryption', env('MAIL_ENCRYPTION', 'tls')),
            ),
            default => config(['mail.default' => 'log']),
        };
    }

    public function currentProvider(): string
    {
        return (string) SiteSetting::read('mail_provider', env('MAIL_MAILER', 'log'));
    }

    public function providerConfigured(string $provider): bool
    {
        return match ($provider) {
            'log' => true,
            'resend' => filled(SiteSetting::read('resend_api_key', env('RESEND_API_KEY'))),
            'mailtrap', 'brevo', 'smtp' => filled(SiteSetting::read('mail_username', env('MAIL_USERNAME')))
                || filled(SiteSetting::read('mail_password', env('MAIL_PASSWORD')))
                || filled(SiteSetting::read('brevo_api_key', env('BREVO_API_KEY'))),
            default => false,
        };
    }

    protected function applySmtpPreset(
        string $host,
        int $port,
        ?string $username,
        ?string $password,
        ?string $encryption,
    ): void {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => $port,
            'mail.mailers.smtp.username' => $username,
            'mail.mailers.smtp.password' => $password,
            'mail.mailers.smtp.encryption' => $encryption ?: null,
        ]);
    }

    protected function applyResend(?string $apiKey): void
    {
        if (filled($apiKey)) {
            config([
                'services.resend.key' => $apiKey,
                'mail.default' => 'resend',
            ]);
        } else {
            // بدون کلید، به log برگرد تا خطا ندهد
            config(['mail.default' => 'log']);
        }
    }
}
