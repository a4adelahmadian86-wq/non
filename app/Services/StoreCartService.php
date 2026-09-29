<?php

namespace App\Services;

use App\Models\StoreCart;
use App\Models\StoreCoupon;
use App\Models\StoreProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreCartService
{
    public function current(Request $request): StoreCart
    {
        $token = $this->sessionToken($request);
        if ($request->user()) {
            $cart = StoreCart::firstOrCreate(['user_id' => $request->user()->id], ['expires_at' => now()->addDays(14)]);
            $guest = StoreCart::whereNull('user_id')->where('session_token', $token)->first();
            if ($guest && $guest->id !== $cart->id) {
                DB::transaction(function () use ($guest, $cart) {
                    foreach ($guest->items as $item) {
                        $existing = $cart->items()->where('product_id', $item->product_id)->first();
                        if ($existing) {
                            $existing->update(['quantity' => min(99, $existing->quantity + $item->quantity)]);
                        } else {
                            $cart->items()->create([
                                'product_id' => $item->product_id,
                                'quantity' => $item->quantity,
                                'price_snapshot_rials' => $item->price_snapshot_rials,
                            ]);
                        }
                    }
                    $guest->delete();
                });
            }

            return $cart->load('items.product');
        }

        return StoreCart::firstOrCreate(
            ['session_token' => $token],
            ['expires_at' => now()->addDays(7)]
        )->load('items.product');
    }

    public function add(Request $request, StoreProduct $product, int $quantity = 1): StoreCart
    {
        abort_unless($product->status === 'published' && $product->published_at?->lte(now()), 404);
        $cart = $this->current($request);
        $item = $cart->items()->firstOrNew(['product_id' => $product->id]);
        $item->quantity = min(99, max(1, (int) $item->quantity + $quantity));
        $item->price_snapshot_rials = (int) $product->price_rials;
        $item->save();

        return $cart->fresh('items.product');
    }

    public function update(Request $request, StoreProduct $product, int $quantity): StoreCart
    {
        $cart = $this->current($request);
        $item = $cart->items()->where('product_id', $product->id)->firstOrFail();
        if ($quantity <= 0) {
            $item->delete();
        } else {
            $item->update([
                'quantity' => min(99, $quantity),
                'price_snapshot_rials' => (int) $product->price_rials,
            ]);
        }

        return $cart->fresh('items.product');
    }

    public function remove(Request $request, StoreProduct $product): StoreCart
    {
        $cart = $this->current($request);
        $cart->items()->where('product_id', $product->id)->delete();

        return $cart->fresh('items.product');
    }

    public function applyCoupon(Request $request, string $code): array
    {
        $code = Str::upper(trim($code));
        $coupon = StoreCoupon::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->first();

        if (! $coupon) {
            throw new \RuntimeException('کد تخفیف معتبر نیست.');
        }
        if ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses) {
            throw new \RuntimeException('ظرفیت استفاده از این کد تمام شده است.');
        }

        $request->session()->put('store_coupon_code', $coupon->code);

        return $this->payload($this->current($request), $request);
    }

    public function clearCoupon(Request $request): array
    {
        $request->session()->forget('store_coupon_code');

        return $this->payload($this->current($request), $request);
    }

    public function payload(StoreCart $cart, ?Request $request = null): array
    {
        $subtotal = 0;
        $count = 0;
        $items = [];
        foreach ($cart->items as $item) {
            if (! $item->product || $item->product->status !== 'published') {
                continue;
            }
            $unit = (int) $item->product->price_rials;
            $qty = (int) $item->quantity;
            $line = $unit * $qty;
            $subtotal += $line;
            $count += $qty;
            $items[] = [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'title' => $item->product->title,
                'slug' => $item->product->slug,
                'quantity' => $qty,
                'unit_price_rials' => $unit,
                'line_total_rials' => $line,
                'unit_price_toman' => (int) floor($unit / 10),
                'line_total_toman' => (int) floor($line / 10),
            ];
        }

        $discount = 0;
        $couponCode = null;
        if ($request) {
            $couponCode = $request->session()->get('store_coupon_code');
            if ($couponCode) {
                $coupon = StoreCoupon::where('code', $couponCode)->where('is_active', true)->first();
                if ($coupon && $subtotal > 0) {
                    if ($coupon->type === 'percent') {
                        $discount = (int) floor($subtotal * min(100, max(0, (int) $coupon->value)) / 100);
                    } else {
                        $discount = min($subtotal, max(0, (int) $coupon->value));
                    }
                    if ($coupon->min_subtotal_rials && $subtotal < $coupon->min_subtotal_rials) {
                        $discount = 0;
                        $couponCode = null;
                    }
                } else {
                    $couponCode = null;
                }
            }
        }

        $taxable = max(0, $subtotal - $discount);

        return [
            'items' => $items,
            'count' => $count,
            'subtotal_rials' => $subtotal,
            'discount_rials' => $discount,
            'tax_rials' => 0,
            'total_rials' => $taxable,
            'subtotal_toman' => (int) floor($subtotal / 10),
            'discount_toman' => (int) floor($discount / 10),
            'total_toman' => (int) floor($taxable / 10),
            'coupon_code' => $couponCode,
        ];
    }

    private function sessionToken(Request $request): string
    {
        $token = $request->session()->get('store_cart_token');
        if (! $token) {
            $token = Str::random(64);
            $request->session()->put('store_cart_token', $token);
        }

        return $token;
    }
}
