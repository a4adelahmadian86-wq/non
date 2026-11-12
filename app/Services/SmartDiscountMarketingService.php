<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StoreCoupon;
use App\Models\SiteSetting;
use App\Models\StoreCouponAssignment;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SmartDiscountMarketingService
{
    private const SCORE_THRESHOLD = 5;
    private const SIGNAL_WINDOW_DAYS = 14;
    private const OFFER_COOLDOWN_DAYS = 7;
    private const OFFER_VALID_HOURS = 48;
    private const MAX_DISCOUNT_PERCENT = 10;

    public function evaluate(User $user, int $productId): ?StoreCouponAssignment
    {
        if (! $user->marketing_consent || $productId <= 0) {
            return null;
        }

        $scoreThreshold = max(1, min(12, (int) SiteSetting::read('smart_discount_score_threshold', self::SCORE_THRESHOLD)));
        $signalWindowDays = max(1, min(60, (int) SiteSetting::read('smart_discount_signal_window_days', self::SIGNAL_WINDOW_DAYS)));
        $offerCooldownDays = max(1, min(90, (int) SiteSetting::read('smart_discount_cooldown_days', self::OFFER_COOLDOWN_DAYS)));
        $offerValidHours = max(1, min(168, (int) SiteSetting::read('smart_discount_offer_valid_hours', self::OFFER_VALID_HOURS)));
        $maxDiscountPercent = max(1, min(50, (int) SiteSetting::read('smart_discount_max_discount_percent', self::MAX_DISCOUNT_PERCENT)));

        $product = StoreProduct::query()->published()->find($productId);
        if (! $product || $product->cost_price_rials === null) {
            return null;
        }

        if ($this->hasPurchased($user->id, $productId)) {
            return null;
        }

        // Aggregate only events for this product in SQL. The previous approach
        // loaded every marketing event for the customer and filtered JSON in PHP,
        // making every product interaction increasingly expensive as history grew.
        $eventCounts = DB::table('farast_workflow_events')
            ->where('user_id', $user->id)
            ->whereIn('event_type', [
                'marketing.product_viewed',
                'marketing.wishlist_added',
                'marketing.cart_added',
                'marketing.coupon_attempted',
                'marketing.cart_abandoned',
            ])
            ->where('created_at', '>=', now()->subDays($signalWindowDays))
            ->whereJsonContains('context->product_id', $productId)
            ->select('event_type')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('event_type')
            ->pluck('aggregate', 'event_type');

        $counts = [
            'marketing.product_viewed' => (int) ($eventCounts['marketing.product_viewed'] ?? 0),
            'marketing.wishlist_added' => (int) ($eventCounts['marketing.wishlist_added'] ?? 0),
            'marketing.cart_added' => (int) ($eventCounts['marketing.cart_added'] ?? 0),
            'marketing.coupon_attempted' => (int) ($eventCounts['marketing.coupon_attempted'] ?? 0),
            'marketing.cart_abandoned' => (int) ($eventCounts['marketing.cart_abandoned'] ?? 0),
        ];

        $score = min(3, $counts['marketing.product_viewed']);
        $reasons = [];
        if ($counts['marketing.product_viewed'] >= 2) {
            $reasons[] = 'repeated_product_views';
        }
        if ($counts['marketing.wishlist_added'] > 0) {
            $score += 3;
            $reasons[] = 'wishlist_interest';
        }
        if ($counts['marketing.cart_added'] > 0) {
            $score += 1;
            $reasons[] = 'cart_intent';
        }
        if ($counts['marketing.cart_abandoned'] > 0) {
            $score += 5;
            $reasons[] = 'cart_abandoned';
        }
        if ($counts['marketing.coupon_attempted'] > 0) {
            $score += 2;
            $reasons[] = 'coupon_interest';
        }

        if ($score < $scoreThreshold) {
            return null;
        }

        $price = (int) $product->price_rials;
        $cost = (int) $product->cost_price_rials;
        $availableDiscount = app(StoreCouponMarginPolicy::class)->maximumDiscount($product, $price, 1);
        $effectiveDiscountPercent = $this->effectiveDiscountPercent($productId, $maxDiscountPercent);
        $discount = min((int) floor($price * $effectiveDiscountPercent / 100), $availableDiscount);

        if ($price <= 0 || $cost < 0 || $availableDiscount <= 0 || $discount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $productId, $score, $reasons, $discount, $offerCooldownDays, $offerValidHours, $maxDiscountPercent) {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (! $lockedUser || ! $lockedUser->marketing_consent) {
                return null;
            }

            if ($this->hasPurchased($lockedUser->id, $productId)) {
                return null;
            }

            // Never issue a second code for the same user/product while an
            // earlier offer is still redeemable, even if the configured cooldown
            // is shorter than the offer validity period.
            if (StoreCouponAssignment::query()
                ->where('user_id', $lockedUser->id)
                ->where('product_id', $productId)
                ->where('status', 'offered')
                ->where('expires_at', '>', now())
                ->exists()) {
                return null;
            }

            // Cooldown is product-specific: an offer for one item must not
            // suppress a genuinely separate buying opportunity for another item.
            if (StoreCouponAssignment::query()
                ->where('user_id', $lockedUser->id)
                ->where('product_id', $productId)
                ->where('offered_at', '>=', now()->subDays($offerCooldownDays))
                ->exists()) {
                return null;
            }

            $lockedProduct = StoreProduct::query()->published()->whereKey($productId)->lockForUpdate()->first();
            if (! $lockedProduct || $lockedProduct->cost_price_rials === null) {
                return null;
            }

            $currentAvailable = app(StoreCouponMarginPolicy::class)->maximumDiscount($lockedProduct, (int) $lockedProduct->price_rials, 1);
            $currentDiscountPercent = $this->effectiveDiscountPercent($productId, $maxDiscountPercent);
            $currentDiscount = min(
                (int) floor((int) $lockedProduct->price_rials * $currentDiscountPercent / 100),
                $currentAvailable
            );
            if ($currentDiscount <= 0 || $currentDiscount !== $discount) {
                return null;
            }

            // Reserve budget before creating and notifying an offer. The same
            // locked policy is checked again at payment, so outstanding offers
            // cannot collectively promise more than the monthly cap.
            if (! app(StoreCouponBudgetPolicy::class)->allows($currentDiscount)) {
                return null;
            }

            $expiresAt = now()->addHours($offerValidHours);
            $code = $this->uniqueCode();

            $coupon = StoreCoupon::create([
                'code' => $code,
                'type' => 'fixed',
                'value' => $discount,
                'min_subtotal_rials' => null,
                'max_uses' => 1,
                'used_count' => 0,
                'is_active' => true,
                'starts_at' => now(),
                'ends_at' => $expiresAt,
                'description' => 'پیشنهاد اختصاصی بازاریابی برای محصول '.$lockedProduct->title,
            ]);

            $assignment = StoreCouponAssignment::create([
                'coupon_id' => $coupon->id,
                'user_id' => $lockedUser->id,
                'product_id' => $lockedProduct->id,
                'status' => 'offered',
                'score' => $score,
                'reason_codes' => $reasons,
                'offered_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            $notification = app(NotificationService::class)->pushToUser(
                $lockedUser,
                'marketing_discount_offer',
                'یک پیشنهاد اختصاصی برای شما',
                'برای محصول «'.$lockedProduct->title.'»، کد اختصاصی '.$coupon->code.' با تخفیف '.number_format($discount).' ریال دریافت کرده‌اید. این پیشنهاد تا '.$offerValidHours.' ساعت معتبر است.',
                [
                    'coupon_code' => $coupon->code,
                    'product_id' => $lockedProduct->id,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'url' => route('store.product', $lockedProduct->slug),
                ],
                true,
                'smart-discount-assignment-'.$assignment->id
            );

            $assignment->update(['notification_id' => $notification->id]);

            return $assignment->fresh(['coupon', 'product']);
        });
    }

    /**
     * Learn conservatively from the last 90 days of this product's real offers.
     * Small samples never change the configured policy. Strong conversion earns
     * a smaller incentive; weak conversion may use the configured ceiling, but
     * the margin policy remains the hard financial limit in every case.
     */
    /**
     * Campaigns use the same product-level learning ceiling as automated offers.
     * The configured campaign percentage is a maximum, never a guaranteed spend.
     */
    public function effectiveCampaignDiscountPercent(int $productId, int $configuredMaximum): float
    {
        return $this->effectiveDiscountPercent($productId, $configuredMaximum);
    }

    private function effectiveDiscountPercent(int $productId, int $configuredMaximum): float
    {
        $history = StoreCouponAssignment::query()
            ->where('product_id', $productId)
            ->where('offered_at', '>=', now()->subDays(90))
            ->where(function ($query) {
                // Only completed outcomes belong in the learning denominator.
                // Counting still-valid offers as failures biases conversion downward.
                $query->where('status', 'redeemed')
                    ->orWhere(function ($expired) {
                        $expired->where('status', 'offered')
                            ->where('expires_at', '<=', now());
                    });
            });

        $total = (clone $history)->count();
        if ($total < 10) {
            return $configuredMaximum;
        }

        $redeemed = (clone $history)->where('status', 'redeemed')->count();
        $conversion = $redeemed / max(1, $total);

        if ($conversion >= 0.35) {
            return max(1.0, $configuredMaximum * 0.75);
        }

        if ($conversion >= 0.10) {
            return max(1.0, $configuredMaximum * 0.90);
        }

        return $configuredMaximum;
    }

    private function hasPurchased(int $userId, int $productId): bool
    {
        return Order::query()
            ->where('user_id', $userId)
            ->where('status', 'paid')
            ->whereHas('storeItems', fn ($items) => $items->where('product_id', $productId))
            ->exists();
    }

    private function uniqueCode(): string
    {
        do {
            $code = 'FARAST'.Str::upper(Str::random(10));
        } while (StoreCoupon::query()->where('code', $code)->exists());

        return $code;
    }
}
