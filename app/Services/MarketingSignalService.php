<?php

namespace App\\Services;

use App\\Models\\User;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

class MarketingSignalService
{
    private const ALLOWED_EVENTS = ['product_viewed', 'wishlist_added', 'cart_added', 'coupon_attempted'];

    /**
     * Store a minimal first-party signal only after explicit account consent.
     * Repeated identical actions are coalesced within one minute.
     */
    public function record(User $user, string $event, ?int $productId = null): bool
    {
        if (! $user->marketing_consent || ! in_array($event, self::ALLOWED_EVENTS, true)) {
            return false;
        }

        if (! Schema::hasTable('farast_workflow_events')) {
            return false;
        }

        $key = implode(':', [
            'marketing', $event, $user->id, $productId ? (string) $productId : 'none',
            now()->format('YmdHi'),
        ]);

        return DB::table('farast_workflow_events')->insertOrIgnore([
            'event_type' => 'marketing.'.$event,
            'idempotency_key' => $key,
            'user_id' => $user->id,
            'context' => json_encode(array_filter(['product_id' => $productId], static fn ($value) => $value !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'matched_workflows' => 0,
            'processed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    public function withdraw(User $user): void
    {
        if (! Schema::hasTable('farast_workflow_events')) {
            return;
        }

        DB::table('farast_workflow_events')
            ->where('user_id', $user->id)
            ->where('event_type', 'like', 'marketing.%')
            ->delete();
    }
}
