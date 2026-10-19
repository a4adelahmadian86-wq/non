<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    public function sendOtp(string $mobile, string $code): void
    {
        $template = config('services.kavenegar.otp_template');
        if ($template) {
            $this->sendLookup($mobile, $template, ['token' => $code]);

            return;
        }

        $this->sendText($mobile, 'کد ورود فراست: '.$code);
    }

    /**
     * ارسال پیامک متنی ساده (Simple API).
     */
    public function sendText(string $mobile, string $message): bool
    {
        $mobile = $this->normalizeMobile($mobile);
        if (! $mobile) {
            return false;
        }

        $key = config('services.kavenegar.key');
        if (! $key) {
            Log::info('farast.sms.skipped_no_key', ['mobile_hash' => $this->hashMobile($mobile)]);

            return false;
        }

        try {
            $response = Http::timeout(15)->asForm()->post(
                'https://api.kavenegar.com/v1/'.$key.'/sms/send.json',
                [
                    'receptor' => $mobile,
                    'sender' => config('services.kavenegar.sender'),
                    'message' => $message,
                ]
            );

            if (! $response->successful()) {
                Log::warning('farast.sms.failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            Log::info('farast.sms.sent', ['mobile_hash' => $this->hashMobile($mobile)]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('farast.sms.exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * ارسال با قالب VerifyLookup کاوه‌نگار.
     *
     * @param  array<string, string|int>  $tokens  کلیدها: token, token2, token3, token10, ...
     */
    public function sendLookup(string $mobile, string $template, array $tokens = []): bool
    {
        $mobile = $this->normalizeMobile($mobile);
        if (! $mobile || $template === '') {
            return false;
        }

        $key = config('services.kavenegar.key');
        if (! $key) {
            Log::info('farast.sms.lookup_skipped_no_key', [
                'template' => $template,
                'mobile_hash' => $this->hashMobile($mobile),
            ]);

            return false;
        }

        $payload = array_merge(
            [
                'receptor' => $mobile,
                'template' => $template,
            ],
            $this->normalizeTokens($tokens)
        );

        try {
            $response = Http::timeout(15)->asForm()->post(
                'https://api.kavenegar.com/v1/'.$key.'/verify/lookup.json',
                $payload
            );

            if (! $response->successful()) {
                Log::warning('farast.sms.lookup_failed', [
                    'template' => $template,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            Log::info('farast.sms.lookup_sent', [
                'template' => $template,
                'mobile_hash' => $this->hashMobile($mobile),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('farast.sms.lookup_exception', [
                'template' => $template,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * ترجیح قالب Lookup؛ در نبود قالب یا شکست، متن ساده.
     *
     * @param  array<string, string|int>  $tokens
     */
    public function sendEvent(string $mobile, string $event, string $fallbackText, array $tokens = []): bool
    {
        $template = config("support.sms_templates.{$event}")
            ?: config("services.kavenegar.templates.{$event}");

        if ($template) {
            if ($this->sendLookup($mobile, $template, $tokens)) {
                return true;
            }
        }

        return $this->sendText($mobile, $fallbackText);
    }

    protected function normalizeMobile(string $mobile): ?string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';
        if (str_starts_with($digits, '98') && strlen($digits) >= 12) {
            $digits = '0'.substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        return strlen($digits) === 11 && str_starts_with($digits, '09') ? $digits : null;
    }

    /**
     * @param  array<string, string|int>  $tokens
     * @return array<string, string>
     */
    protected function normalizeTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $key => $value) {
            $k = is_int($key) ? 'token'.($key === 0 ? '' : ($key + 1)) : (string) $key;
            if (! str_starts_with($k, 'token')) {
                $k = 'token';
            }
            // کاوه‌نگار فاصله را در token قبول نمی‌کند — با - جایگزین می‌کنیم
            $out[$k] = str_replace(' ', '-', (string) $value);
        }

        return $out;
    }

    protected function hashMobile(string $mobile): string
    {
        return substr(hash('sha256', $mobile), 0, 12);
    }
}
