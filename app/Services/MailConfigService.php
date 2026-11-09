<?php

namespace App\Services;

use App\Models\SiteSetting;
use RuntimeException;

class MailConfigService
{
    public const PROVIDERS = [
        'local' => 'ارسال از عامل ایمیل محلی FARAST (127.0.0.1)',
        'sendmail' => 'عامل Sendmail/Postfix نصب‌شده روی همان میزبان',
        'log' => 'حالت آزمایش و ثبت داخلی (بدون ارسال به صندوق مقصد)',
    ];

    public function currentProvider(): string
    {
        $provider = (string) SiteSetting::read('mail_provider', env('MAIL_MAILER', 'log'));

        return array_key_exists($provider, self::PROVIDERS) ? $provider : 'log';
    }

    public function apply(): void
    {
        $provider = $this->currentProvider();
        $fromAddress = SiteSetting::read('mail_from_address', env('MAIL_FROM_ADDRESS', 'noreply@localhost'));
        $fromName = SiteSetting::read('mail_from_name', env('MAIL_FROM_NAME', 'FARAST'));

        config([
            'mail.from.address' => $fromAddress ?: 'noreply@localhost',
            'mail.from.name' => $fromName ?: 'FARAST',
        ]);

        if ($provider === 'local') {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.host' => '127.0.0.1',
                'mail.mailers.smtp.port' => 25,
                'mail.mailers.smtp.username' => null,
                'mail.mailers.smtp.password' => null,
                'mail.mailers.smtp.encryption' => null,
                'mail.mailers.smtp.scheme' => null,
                'mail.mailers.smtp.url' => null,
                'mail.mailers.smtp.timeout' => 8,
            ]);

            return;
        }

        if ($provider === 'sendmail' && $this->sendmailBinaryAvailable()) {
            config([
                'mail.default' => 'sendmail',
                'mail.mailers.sendmail.path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
            ]);

            return;
        }

        config(['mail.default' => 'log']);
    }

    public function providerConfigured(string $provider): bool
    {
        return match ($provider) {
            'log' => true,
            'sendmail' => $this->sendmailBinaryAvailable(),
            'local' => $this->localMtaAvailable(),
            default => false,
        };
    }

    public function assertAvailable(?string $provider = null): void
    {
        $provider = $provider ?: $this->currentProvider();

        if ($provider === 'log') {
            return;
        }

        if (! $this->providerConfigured($provider)) {
            throw new RuntimeException(match ($provider) {
                'local' => 'عامل ارسال ایمیل محلی روی 127.0.0.1:25 در دسترس نیست. هیچ ایمیلی به مقصد خارجی ارسال نشده است.',
                'sendmail' => 'برنامه Sendmail/Postfix روی همین میزبان نصب یا قابل اجرا نیست.',
                default => 'روش ارسال ایمیل پشتیبانی نمی‌شود.',
            });
        }
    }

    public function diagnostics(): array
    {
        $provider = $this->currentProvider();
        $available = $this->providerConfigured($provider);

        return [
            'provider' => $provider,
            'label' => self::PROVIDERS[$provider],
            'available' => $available,
            'queue' => (string) config('queue.default', 'sync'),
            'local_mta' => $this->localMtaAvailable(),
            'sendmail' => $this->sendmailBinaryAvailable(),
            'meaning' => $provider === 'log'
                ? 'حالت آزمایش: فقط در گزارش داخلی ثبت می‌شود و به صندوق مقصد نمی‌رسد.'
                : 'پذیرش توسط عامل ارسال محلی ثبت می‌شود؛ تحویل نهایی صندوق مقصد از این میزبان قابل تضمین نیست.',
            'host' => $provider === 'local' ? '127.0.0.1' : null,
            'port' => $provider === 'local' ? 25 : null,
        ];
    }

    public function localMtaAvailable(): bool
    {
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client('tcp://127.0.0.1:25', $errno, $error, 1);

        if (! is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }

    public function sendmailBinaryAvailable(): bool
    {
        $path = (string) env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i');
        $binary = strtok($path, ' ') ?: '';

        if ($binary === '') {
            return false;
        }

        return DIRECTORY_SEPARATOR === '\'
            ? is_file($binary)
            : (is_file($binary) && is_executable($binary));
    }
}
