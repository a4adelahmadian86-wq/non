<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StoreCartItem;

class StoreCartAbandonmentService
{
    public function process(int $limit = 200): int
    {
        $cutoff = now()->subHours(2);
        $items = StoreCartItem::query()
            ->whereNull('marketing_abandoned_at')
            ->where('updated_at', '<=', $cutoff)
            ->whereHas('product', fn ($product) => $product->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now()))
            ->whereHas('cart', fn ($cart) => $cart->whereNotNull('user_id')
                ->whereHas('user', fn ($users) => $users->where('marketing_consent', true)))
            ->with(['cart.user', 'product'])
            ->orderBy('id')
            ->limit(max(1, min($limit, 1000)))
            ->get();

        $recorded = 0;
        foreach ($items as $item) {
            $user = $item->cart?->user;
            if (! $user || ! $user->marketing_consent) {
                continue;
            }

            if (! $item->product
                || $item->product->status !== 'published'
                || ! $item->product->published_at
                || $item->product->published_at->gt(now())) {
                continue;
            }

            $alreadyPurchased = Order::query()
                ->where('user_id', $user->id)
                ->where('status', 'paid')
                ->whereHas('storeItems', fn ($orderItems) => $orderItems->where('product_id', $item->product_id))
                ->exists();

            if (! $alreadyPurchased) {
                $scope = 'cart-item-'.$item->id.'-'.$item->updated_at->timestamp;
                if (app(MarketingSignalService::class)->record($user, 'cart_abandoned', (int) $item->product_id, $scope)) {
                    $recorded++;
                }
            }

            $item->forceFill(['marketing_abandoned_at' => now()])->save();
        }

        return $recorded;
    }
}
