<?php

namespace App\Services;

use App\Models\SiteSetting;

/**
 * تنظیم سرویس ایمیل از پنل ادمین.
 *
 * اولویت: رایگان و بدون وابستگی خارجی.
 * - log: همیشه کار می‌کند (ایمیل در storage/logs)
 * - sendmail: فقط روی لینوکس با باینری واقعی
 * - local/smtp: 127.0.0.1 — هرگز mailpit
 */
class MailConfigService
{
    public const PROVIDERS = [
        'log' => 'لاگ داخلی سایت (همیشه کار می‌کند — تست رایگان)',
        'sendmail' => 'Sendmail/Postfix روی سرور لینوکس (رایگان)',
        'local' => 'SMTP محلی 127.0.0.1 (سرور خودتان)',
        'smtp' => 'SMTP دلخواه (هاست ایمیل خودتان)',
        'mailtrap' => 'Mailtrap (اختیاری — بیرونی)',
        'brevo' => 'Brevo (اختیاری — بیرونی)',
        'resend' => 'Resend (اختیاری — بیرونی)',
    ];

    public function apply(): void
    {
        $provider = (string) SiteSetting::read('mail_provider', env('MAIL_MAILER', 'log'));

        $fromAddress = SiteSetting::read('mail_from_address', env('MAIL_FROM_ADDRESS', 'noreply@localhost'));
        $fromName = SiteSetting::read('mail_from_name', env('MAIL_FROM_NAME', 'FARAST'));

        config([
            'mail.from.address' => $fromAddress ?: 'noreply@localhost',
            'mail.from.name' => $fromName ?: 'FARAST',
        ]);

        match ($provider) {
            'sendmail' => $this->applySendmail(),
            'local' => $this->applySmtpPreset(
                host: $this->sanitizeHost(SiteSetting::read('mail_host', '127.0.0.1') ?: '127.0.0.1'),
                port: (int) (SiteSetting::read('mail_port', 25) ?: 25),
                username: null,
                password: null,
                encryption: null,
            ),
            'mailtrap' => $this->applySmtpPreset(
                host: $this->sanitizeHost(SiteSetting::read('mail_host', 'sandbox.smtp.mailtrap.io')),
                port: (int) SiteSetting::read('mail_port', 2525),
                username: SiteSetting::read('mail_username', env('MAIL_USERNAME')),
                password: SiteSetting::read('mail_password', env('MAIL_PASSWORD')) ?: null,
                encryption: SiteSetting::read('mail_encryption', 'tls') ?: 'tls',
            ),
            'brevo' => $this->applySmtpPreset(
                host: $this->sanitizeHost(SiteSetting::read('mail_host', 'smtp-relay.brevo.com')),
                port: (int) SiteSetting::read('mail_port', 587),
                username: SiteSetting::read('mail_username', env('MAIL_USERNAME')),
                password: SiteSetting::read('mail_password', env('MAIL_PASSWORD'))
                    ?: SiteSetting::read('brevo_api_key', env('BREVO_API_KEY'))
                    ?: null,
                encryption: SiteSetting::read('mail_encryption', 'tls') ?: 'tls',
            ),
            'resend' => $this->applyResend(
                SiteSetting::read('resend_api_key', env('RESEND_API_KEY'))
            ),
            'smtp' => $this->applySmtpPreset(
                host: $this->sanitizeHost(
                    SiteSetting::read('mail_host')
                        ?: $this->envHostWithoutMailpit()
                        ?: '127.0.0.1'
                ),
                port: (int) (SiteSetting::read('mail_port') ?: $this->envPortWithoutMailpit() ?: 25),
                username: SiteSetting::read('mail_username', env('MAIL_USERNAME')),
                password: SiteSetting::read('mail_password', env('MAIL_PASSWORD')) ?: null,
                encryption: SiteSetting::read('mail_encryption', env('MAIL_ENCRYPTION')) ?: null,
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
            'sendmail' => $this->sendmailBinaryAvailable(),
            'local' => true,
            'resend' => filled(SiteSetting::read('resend_api_key', env('RESEND_API_KEY'))),
            'mailtrap', 'brevo', 'smtp' => filled(SiteSetting::read('mail_username', env('MAIL_USERNAME')))
                || filled(SiteSetting::read('mail_password', env('MAIL_PASSWORD')))
                || filled(SiteSetting::read('brevo_api_key', env('BREVO_API_KEY')))
                || filled($this->sanitizeHost(SiteSetting::read('mail_host', '') ?: '')),
            default => false,
        };
    }

    public function isSelfHosted(string $provider): bool
    {
        return in_array($provider, ['sendmail', 'local', 'smtp', 'log'], true);
    }

    public function sendmailBinaryAvailable(): bool
    {
        $path = (string) env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i');
        $binary = strtok($path, ' ') ?: '';
        if ($binary === '') {
            return false;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return is_file($binary);
        }

        return is_file($binary) || is_executable($binary);
    }

    protected function applySendmail(): void
    {
        if (! $this->sendmailBinaryAvailable()) {
            config(['mail.default' => 'log']);

            return;
        }

        $path = env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i');
        config([
            'mail.default' => 'sendmail',
            'mail.mailers.sendmail.path' => $path,
        ]);
    }

    protected function applySmtpPreset(
        string $host,
        int $port,
        ?string $username,
        ?string $password,
        ?string $encryption,
    ): void {
        $host = $this->sanitizeHost($host);

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => $port > 0 ? $port : 25,
            'mail.mailers.smtp.username' => $username,
            'mail.mailers.smtp.password' => $password,
            'mail.mailers.smtp.encryption' => $encryption ?: null,
            'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.url' => null,
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
            config(['mail.default' => 'log']);
        }
    }

    protected function sanitizeHost(?string $host): string
    {
        $host = trim((string) $host);
        if ($host === '' || $this->isMailpitHost($host)) {
            return '127.0.0.1';
        }

        return $host;
    }

    protected function isMailpitHost(?string $host): bool
    {
        $h = strtolower(trim((string) $host));

        return $h === 'mailpit' || str_contains($h, 'mailpit');
    }

    protected function envHostWithoutMailpit(): ?string
    {
        $host = env('MAIL_HOST');
        if (! is_string($host) || $this->isMailpitHost($host)) {
            return null;
        }

        return $host;
    }

    protected function envPortWithoutMailpit(): ?int
    {
        if ($this->isMailpitHost(env('MAIL_HOST'))) {
            return null;
        }
        $port = env('MAIL_PORT');

        return is_numeric($port) ? (int) $port : null;
    }
}
