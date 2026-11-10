<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class MarketingSignalService
{
    private const ALLOWED_EVENTS = ['product_viewed', 'wishlist_added', 'cart_added', 'cart_abandoned', 'coupon_attempted', 'offer_viewed'];

    /**
     * Store a minimal first-party signal only after explicit account consent.
     * Repeated identical actions are coalesced within one minute.
     */
    public function record(User $user, string $event, ?int $productId = null, ?string $idempotencyScope = null): bool
    {
        if (! $user->exists || ! in_array($event, self::ALLOWED_EVENTS, true) || ! Schema::hasTable('farast_workflow_events')) {
            return false;
        }

        $inserted = DB::transaction(function () use ($user, $event, $productId, $idempotencyScope): bool {
            $currentUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (! $currentUser || ! $currentUser->marketing_consent) {
                return false;
            }

            $key = implode(':', [
                'marketing', $event, $user->id, $productId ? (string) $productId : 'none',
                $idempotencyScope ?: now()->format('YmdHi'),
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
        });

        if ($inserted && $productId && $event !== 'offer_viewed') {
            try {
                $freshUser = User::query()->find($user->id);
                if ($freshUser) {
                    app(SmartDiscountMarketingService::class)->evaluate($freshUser, $productId);
                }
            } catch (\Throwable $exception) {
                Log::warning('marketing.smart_discount_evaluation_failed', [
                    'user_id' => $user->id,
                    'product_id' => $productId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $inserted;
    }

    public function withdraw(User $user): void
    {
        DB::transaction(function () use ($user): void {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (Schema::hasTable('store_coupon_assignments') && Schema::hasTable('store_coupons')) {
                $couponIds = DB::table('store_coupon_assignments')
                    ->where('user_id', $user->id)
                    ->where('status', 'offered')
                    ->pluck('coupon_id');

                DB::table('store_coupon_assignments')
                    ->where('user_id', $user->id)
                    ->where('status', 'offered')
                    ->update(['status' => 'revoked', 'updated_at' => now()]);

                if ($couponIds->isNotEmpty()) {
                    DB::table('store_coupons')->whereIn('id', $couponIds)->update([
                        'is_active' => false,
                        'updated_at' => now(),
                    ]);
                }
            }

            if (Schema::hasTable('farast_workflow_events')) {
                DB::table('farast_workflow_events')
                    ->where('user_id', $user->id)
                    ->where('event_type', 'like', 'marketing.%')
                    ->delete();
            }
        });
    }
}
