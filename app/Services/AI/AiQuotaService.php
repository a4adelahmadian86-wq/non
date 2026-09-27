<?php

namespace App\Services\AI;

use App\Models\AiInteraction;
use App\Models\User;
use App\Services\CapabilityService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AiQuotaService
{
    public function __construct(private CapabilityService $capabilities) {}

    public function assertAllowed(User $user, string $capability = 'can_ai'): array
    {
        $caps = $this->capabilities->forUser($user);
        if (! ($caps['active'] ?? false) || ! ($caps[$capability] ?? false)) throw new HttpException(403, 'این قابلیت هوش مصنوعی برای حساب شما فعال نیست.');
        if (! ($caps['unlimited'] ?? false)) {
            $used = AiInteraction::where('user_id', $user->id)->whereDate('created_at', today())->count();
            if ($used >= (int) ($caps['daily_ai_requests'] ?? 0)) throw new HttpException(429, 'سقف روزانه پردازش هوش مصنوعی شما تکمیل شده است.');
        }
        return $caps;
    }
}
