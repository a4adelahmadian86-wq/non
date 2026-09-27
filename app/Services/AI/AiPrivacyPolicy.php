<?php

namespace App\Services\AI;

use Illuminate\Validation\ValidationException;

class AiPrivacyPolicy
{
    public const MODES = ['automatic', 'local', 'server', 'external'];

    public function resolve(?string $requestedMode, array $availableModes): string
    {
        $requestedMode = $requestedMode ?: 'automatic';
        if (! in_array($requestedMode, self::MODES, true)) throw ValidationException::withMessages(['processing_mode' => 'حالت پردازش نامعتبر است.']);
        if ($requestedMode !== 'automatic') {
            if (! in_array($requestedMode, $availableModes, true)) throw ValidationException::withMessages(['processing_mode' => 'این عملیات در حالت پردازشی انتخاب‌شده در دسترس نیست و داده به حالت دیگری منتقل نشد.']);
            return $requestedMode;
        }
        foreach (['local', 'server', 'external'] as $mode) if (in_array($mode, $availableModes, true)) return $mode;
        throw ValidationException::withMessages(['processing_mode' => 'هیچ مسیر پردازشی مجازی برای این عملیات موجود نیست.']);
    }
}
