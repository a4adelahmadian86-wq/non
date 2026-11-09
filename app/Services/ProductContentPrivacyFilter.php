<?php

namespace App\Services;

class ProductContentPrivacyFilter
{
    public function sanitize(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->sanitizeText($value);
        }

        if (is_array($value)) {
            $privateMetadataKeys = [
                'file_name',
                'filename',
                'client_filename',
                'upload_name',
                'source_file_name',
                'archive_entry',
                'original_name',
                'source_sha256',
                'storage_path',
                'file_path',
                'absolute_path',
                'private_path',
                'path',
                'disk',
            ];
            foreach ($value as $key => $item) {
                if (is_string($key) && in_array(strtolower($key), $privateMetadataKeys, true)) {
                    unset($value[$key]);
                    continue;
                }
                $value[$key] = $this->sanitize($item);
            }
        }

        return $value;
    }

    public function sanitizeText(string $value): string
    {
        $patterns = [
            '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----[\\s\\S]*?-----END (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/u',
            '/(?i)\\bBearer\\s+[A-Za-z0-9._~+\\/-]+=*/u',
            '/\\b(?:sk-[A-Za-z0-9_-]{16,}|sk_(?:live|test)_[A-Za-z0-9]{16,}|AIza[0-9A-Za-z_-]{25,}|gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|xox[baprs]-[A-Za-z0-9-]{20,}|AKIA[A-Z0-9]{16})\\b/u',
            '/\\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}\\b/iu',
            '/(?<![\\p{L}\\p{N}])(?:\\+|00)(?:[0-9۰-۹٠-٩][\\s().-]*){8,15}(?![\\p{L}\\p{N}])/u',
            '/(?<![\\p{L}\\p{N}])0[0-9۰-۹٠-٩]{9,10}(?![\\p{L}\\p{N}])/u',
            '/\\b[A-Z]{2}[0-9]{2}(?:\\s?[A-Z0-9]){11,30}\\b/iu',
            '/([?&](?:token|access_token|api_key|key|secret|password|auth)=)[^&\\s]+/iu',
            '/((?:کد\\s*ملی|شماره\\s*حساب|شماره\\s*کارت|national\\s*id|account\\s*(?:no|number)|card\\s*number)\\s*[:：]?\\s*)[0-9۰-۹٠-٩]{8,24}/iu',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '[REDACTED]', $value) ?? $value;
        }

        $value = preg_replace_callback(
            '/(?<!\\p{Nd})(?:\\p{Nd}[ -]?){13,19}(?!\\p{Nd})/u',
            function (array $match): string {
                $digits = preg_replace('/[^\\p{Nd}]/u', '', $match[0]) ?? '';
                return $this->passesLuhn($digits) ? '[REDACTED]' : $match[0];
            },
            $value
        ) ?? $value;

        return $value;
    }

    private function passesLuhn(string $digits): bool
    {
        $digits = strtr($digits, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $length = strlen($digits);
        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;
        $double = false;
        for ($index = $length - 1; $index >= 0; $index--) {
            $digit = (int) $digits[$index];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
