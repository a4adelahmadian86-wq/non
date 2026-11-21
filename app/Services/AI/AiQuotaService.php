<?php

namespace App\Services\AI;

use App\Models\AiInteraction;
use App\Models\User;
use App\Services\CapabilityService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AiQuotaService
{
    public function __construct(private CapabilityService $capabilities) {}

    public function assertAllowed(User $user, string $capability = 'can_ai'): array
    {
        $caps = $this->capabilities->forUser($user);
        $this->assertCapabilities($caps, $capability, $user->id);

        return $caps;
    }

    /**
     * Atomically checks the daily allowance and reserves one request before provider work starts.
     *
     * Locking the user's row serializes concurrent reservations for the same account.
     * The pending interaction itself counts against the daily limit while the provider is processing.
     *
     * @param array<string, mixed> $attributes
     */
    public function reserveInteraction(User $user, array $attributes, string $capability = 'can_ai'): AiInteraction
    {
        return DB::transaction(function () use ($user, $attributes, $capability): AiInteraction {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $caps = $this->capabilities->forUser($lockedUser);
            $this->assertCapabilities($caps, $capability, $lockedUser->id);

            return AiInteraction::create(array_merge($attributes, [
                'user_id' => $lockedUser->getKey(),
            ]));
        }, 3);
    }

    /**
     * @param array<string, mixed> $caps
     */
    private function assertCapabilities(array $caps, string $capability, int $userId): void
    {
        if (! ($caps['active'] ?? false) || ! ($caps[$capability] ?? false)) {
            throw new HttpException(403, 'این قابلیت هوش مصنوعی برای حساب شما فعال نیست.');
        }

        if (! ($caps['unlimited'] ?? false)) {
            $used = AiInteraction::query()
                ->where('user_id', $userId)
                ->whereDate('created_at', today())
                ->count();

            if ($used >= (int) ($caps['daily_ai_requests'] ?? 0)) {
                throw new HttpException(429, 'سقف روزانه پردازش هوش مصنوعی شما تکمیل شده است.');
            }
        }
    }
}
