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
        $defaultFloor = (int) ceil($price * self::DEFAULT_MINIMUM_MARGIN_PERCENT / 100);

        return max($configuredFloor, $defaultFloor);
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

        return max(0, $available) * max(1, $quantity);
    }
}
