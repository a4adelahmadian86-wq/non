<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;

class ZarinpalService
{
    public function enabled(): bool
    {
        return SiteSetting::read('payment_gateway', 'none') === 'zarinpal'
            && filled(SiteSetting::read('gateway_merchant_id'));
    }

    public function merchantId(): string
    {
        return (string) SiteSetting::read('gateway_merchant_id', '');
    }

    public function sandbox(): bool
    {
        return filter_var(SiteSetting::read('zarinpal_sandbox', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function baseApi(): string
    {
        return $this->sandbox()
            ? 'https://sandbox.zarinpal.com/pg/v4/payment'
            : 'https://api.zarinpal.com/pg/v4/payment';
    }

    private function startPayUrl(string $authority): string
    {
        $host = $this->sandbox() ? 'https://sandbox.zarinpal.com' : 'https://www.zarinpal.com';

        return $host.'/pg/StartPay/'.$authority;
    }

    /** @return array{authority:string,url:string} */
    public function request(int $amountRials, string $description, string $callbackUrl, ?string $mobile = null, ?string $email = null): array
    {
        if ($amountRials < 1000) {
            throw new \RuntimeException('مبلغ برای درگاه معتبر نیست.');
        }

        $payload = [
            'merchant_id' => $this->merchantId(),
            'amount' => $amountRials,
            'description' => mb_substr($description, 0, 250),
            'callback_url' => $callbackUrl,
            'metadata' => array_filter([
                'mobile' => $mobile,
                'email' => $email,
            ]),
        ];

        $response = Http::timeout(20)->acceptJson()->post($this->baseApi().'/request.json', $payload);
        $data = $response->json('data') ?? [];
        $code = (int) ($data['code'] ?? $response->json('errors.code') ?? 0);

        if (! $response->successful() || $code !== 100 || empty($data['authority'])) {
            $message = $response->json('errors.message')
                ?? $data['message']
                ?? 'خطا در اتصال به زرین‌پال';
            throw new \RuntimeException(is_string($message) ? $message : 'خطا در اتصال به زرین‌پال');
        }

        return [
            'authority' => (string) $data['authority'],
            'url' => $this->startPayUrl((string) $data['authority']),
        ];
    }

    /** @return array{ref_id:string|null,code:int} */
    public function verify(string $authority, int $amountRials): array
    {
        $response = Http::timeout(20)->acceptJson()->post($this->baseApi().'/verify.json', [
            'merchant_id' => $this->merchantId(),
            'amount' => $amountRials,
            'authority' => $authority,
        ]);

        $data = $response->json('data') ?? [];
        $code = (int) ($data['code'] ?? 0);

        return [
            'code' => $code,
            'ref_id' => isset($data['ref_id']) ? (string) $data['ref_id'] : null,
        ];
    }
}
