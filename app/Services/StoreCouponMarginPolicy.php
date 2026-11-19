<?php

namespace App\Services;

use App\Models\StoreProduct;

/**
 * One shared margin rule for issuance, cart validation and final redemption.
 * A configured product floor can be stricter; otherwise at least 5% of the
 * selling price remains as gross margin after a smart discount.
 */
class StoreCouponMarginPolicy
{
    private const DEFAULT_MINIMUM_MARGIN_PERCENT = 5;

    public function minimumMargin(StoreProduct $product, ?int $sellingPriceRials = null): int
    {
        $price = max(0, $sellingPriceRials ?? (int) $product->price_rials);
        $configuredFloor = max(0, (int) $product->marketing_min_margin_rials);
        $defaultFloor = app(PricingEngine::class)->percentageAmount($price, self::DEFAULT_MINIMUM_MARGIN_PERCENT * 100, 1, 'up');

        return max($configuredFloor, $defaultFloor);
    }

    /**
     * Lowest selling price that still satisfies the configured floor and the
     * default percentage margin. A missing cost price means no safe base-price
     * recommendation can be derived.
     */
    public function minimumSafeSellingPrice(StoreProduct $product): ?int
    {
        if ($product->cost_price_rials === null) {
            return null;
        }

        $cost = max(0, (int) $product->cost_price_rials);
        $configuredFloor = max(0, (int) $product->marketing_min_margin_rials);
        $low = max(1, $cost);
        $high = max($low + 1, $cost + $configuredFloor + 1, ($cost * 2) + 1);

        while ($low < $high) {
            $candidate = intdiv($low + $high, 2);
            $margin = $candidate - $cost;
            if ($margin >= $this->minimumMargin($product, $candidate)) {
                $high = $candidate;
            } else {
                $low = $candidate + 1;
            }
        }

        return $low;
    }

    public function maximumDiscount(
        StoreProduct $product,
        ?int $sellingPriceRials = null,
        int $quantity = 1,
    ): int {
        if ($product->cost_price_rials === null || (int) $product->cost_price_rials < 0) {
            return 0;
        }

        $price = max(0, $sellingPriceRials ?? (int) $product->price_rials);
        $available = $price - (int) $product->cost_price_rials - $this->minimumMargin($product, $price);

        return app(PricingEngine::class)->lineAmount(max(0, $available), max(1, $quantity));
    }
}
