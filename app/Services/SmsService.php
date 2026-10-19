<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    public function sendOtp(string $mobile, string $code): void
    {
        $this->sendText($mobile, 'کد ورود فراست: '.$code);
    }

    /**
     * ارسال پیامک متنی از طریق کاوه‌نگار (در صورت پیکربندی).
     */
    public function sendText(string $mobile, string $message): bool
    {
        $mobile = $this->normalizeMobile($mobile);
        if (! $mobile) {
            return false;
        }

        $key = config('services.kavenegar.key');
        if (! $key) {
            Log::info('farast.sms.skipped_no_key', ['mobile_hash' => substr(hash('sha256', $mobile), 0, 12)]);

            return false;
        }

        try {
            $response = Http::timeout(15)->get('https://api.kavenegar.com/v1/'.$key.'/sms/send.json', [
                'receptor' => $mobile,
                'sender' => config('services.kavenegar.sender'),
                'message' => $message,
            ]);

            if (! $response->successful()) {
                Log::warning('farast.sms.failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            Log::info('farast.sms.sent', ['mobile_hash' => substr(hash('sha256', $mobile), 0, 12)]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('farast.sms.exception', ['error' => $e->getMessage()]);

            return false;
        }
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
}
