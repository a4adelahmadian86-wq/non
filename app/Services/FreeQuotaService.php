<?php

namespace App\Services;

use App\Models\FreeCredit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FreeQuotaService
{
    public function availablePages(User $user, int $configuredPages): int
    {
        if ($user->isAdmin() || $configuredPages <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($user, $configuredPages): int {
            return $this->current($user, $configuredPages)->remaining();
        }, 3);
    }

    public function consume(User $user, int $pages = 1, int $configuredPages = 1): int
    {
        if ($user->isAdmin() || $pages <= 0 || $configuredPages <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($user, $pages, $configuredPages): int {
            $credit = $this->current($user, $configuredPages);
            $use = min($pages, $credit->remaining());

            if ($use > 0) {
                $credit->increment('consumed_pages', $use);
            }

            return $use;
        }, 3);
    }

    /**
     * Create the weekly row without a read-then-insert race, then lock it
     * before reconciling the configured grant or reading/consuming its balance.
     *
     * Callers must execute this method inside a database transaction.
     */
    private function current(User $user, int $configuredPages): FreeCredit
    {
        $hash = hash('sha256', trim($user->mobile));
        $week = now()->startOfWeek()->toDateString();
        $timestamp = now();

        // The unique (mobile_hash, week_start) key arbitrates concurrent first
        // requests. INSERT IGNORE / ON CONFLICT DO NOTHING avoids a duplicate
        // key exception when two requests initialize the same weekly allowance.
        DB::table('free_credits')->insertOrIgnore([
            'mobile_hash' => $hash,
            'user_id' => $user->id,
            'week_start' => $week,
            'granted_pages' => $configuredPages,
            'consumed_pages' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $credit = FreeCredit::query()
            ->where('mobile_hash', $hash)
            ->where('week_start', $week)
            ->lockForUpdate()
            ->firstOrFail();

        $changes = [];
        if ((int) $credit->user_id !== (int) $user->id) {
            $changes['user_id'] = $user->id;
        }
        if ((int) $credit->granted_pages !== $configuredPages) {
            $changes['granted_pages'] = $configuredPages;
        }

        if ($changes !== []) {
            $credit->forceFill($changes)->save();
        }

        return $credit;
    }
}
