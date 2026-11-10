<?php

namespace App\Services;

use App\Models\StoreCart;
use App\Models\StoreCoupon;
use App\Models\StoreCouponAssignment;
use App\Models\Order;
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
                            $existing->update(['quantity' => min(99, $existing->quantity + $item->quantity), 'marketing_abandoned_at' => null]);
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
        abort_unless($this->isPurchasable($product), 404);
        $cart = $this->current($request);
        $item = $cart->items()->firstOrNew(['product_id' => $product->id]);
        $item->quantity = min(99, max(1, (int) $item->quantity + $quantity));
        $item->price_snapshot_rials = (int) $product->price_rials;
        $item->marketing_abandoned_at = null;
        $item->save();

        return $cart->fresh('items.product');
    }

    public function update(Request $request, StoreProduct $product, int $quantity): StoreCart
    {
        abort_unless($this->isPurchasable($product), 404);
        $cart = $this->current($request);
        $item = $cart->items()->where('product_id', $product->id)->firstOrFail();
        if ($quantity <= 0) {
            $item->delete();
        } else {
            $item->update([
                'quantity' => min(99, $quantity),
                'price_snapshot_rials' => (int) $product->price_rials,
                'marketing_abandoned_at' => null,
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
        $code = mb_strtoupper(trim($code));
        if ($code === '' || mb_strlen($code) > 40) {
            throw new \RuntimeException('کد تخفیف را به‌درستی وارد کنید.');
        }
        $coupon = StoreCoupon::query()
            ->whereRaw('UPPER(code) = ?', [$code])
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
        if (! in_array($coupon->type, ['percent', 'fixed'], true)
            || (int) $coupon->value <= 0
            || ($coupon->type === 'percent' && (int) $coupon->value > 100)) {
            throw new \RuntimeException('تنظیمات این کد تخفیف معتبر نیست.');
        }

        $cart = $this->current($request);
        $assignment = StoreCouponAssignment::query()->where('coupon_id', $coupon->id)->first();
        if ($assignment) {
            if (! $request->user() || (int) $assignment->user_id !== (int) $request->user()->id
                || $assignment->status !== 'offered' || ! $assignment->expires_at || $assignment->expires_at->lte(now())
                || Order::query()->where('user_id', $assignment->user_id)->where('status', 'paid')
                    ->whereHas('storeItems', fn ($items) => $items->where('product_id', $assignment->product_id))->exists()) {
                throw new \RuntimeException('این کد تخفیف اختصاصی برای حساب شما معتبر نیست یا مهلت آن پایان یافته است.');
            }
            if (! $cart->items->contains(fn ($item) => (int) $item->product_id === (int) $assignment->product_id)) {
                throw new \RuntimeException('این کد فقط برای محصول مشخص‌شده در پیشنهاد اختصاصی قابل استفاده است.');
            }
            $assignedProduct = StoreProduct::query()->find($assignment->product_id);
            $assignedQuantity = (int) $cart->items->where('product_id', $assignment->product_id)->sum('quantity');
            $safeDiscountCap = $assignedProduct && $assignedProduct->cost_price_rials !== null
                ? max(0, ((int) $assignedProduct->price_rials - (int) $assignedProduct->cost_price_rials - max(0, (int) $assignedProduct->marketing_min_margin_rials)) * $assignedQuantity)
                : 0;
            if ($coupon->type !== 'fixed' || (int) $coupon->value > $safeDiscountCap) {
                throw new \RuntimeException('شرایط سودآوری محصول تغییر کرده و این پیشنهاد دیگر قابل استفاده نیست.');
            }
        }
        $cartPayload = $this->payload($cart);
        if ((int) $cartPayload['subtotal_rials'] <= 0) {
            throw new \RuntimeException('ابتدا یک محصول به سبد خرید اضافه کنید.');
        }
        if ($coupon->min_subtotal_rials !== null && (int) $cartPayload['subtotal_rials'] < (int) $coupon->min_subtotal_rials) {
            throw new \RuntimeException('حداقل مبلغ سبد برای استفاده از این کد تخفیف رعایت نشده است.');
        }

        $request->session()->put('store_coupon_code', $coupon->code);

        return $this->payload($cart, $request);
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
            if (! $item->product || ! $this->isPurchasable($item->product)) {
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
        $couponAssignmentId = null;
        $couponProductId = null;
        if ($request) {
            $couponCode = $request->session()->get('store_coupon_code');
            if ($couponCode) {
                $couponCode = mb_strtoupper(trim((string) $couponCode));
                $coupon = StoreCoupon::whereRaw('UPPER(code) = ?', [$couponCode])
                    ->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                    ->first();

                $hasRemainingUses = $coupon
                    && ($coupon->max_uses === null || (int) $coupon->used_count < (int) $coupon->max_uses);
                $meetsMinimum = $coupon
                    && ($coupon->min_subtotal_rials === null || $subtotal >= (int) $coupon->min_subtotal_rials);

                if ($coupon && $hasRemainingUses && $meetsMinimum && $subtotal > 0
                    && in_array($coupon->type, ['percent', 'fixed'], true)
                    && (int) $coupon->value > 0
                    && ! ($coupon->type === 'percent' && (int) $coupon->value > 100)) {
                    $assignment = StoreCouponAssignment::query()->where('coupon_id', $coupon->id)->first();
                    if ($assignment) {
                        $eligibleLineTotal = 0;
                        $eligibleQuantity = 0;
                        foreach ($items as $item) {
                            if ((int) $item['product_id'] === (int) $assignment->product_id) {
                                $eligibleLineTotal += (int) $item['line_total_rials'];
                                $eligibleQuantity += (int) $item['quantity'];
                            }
                        }
                        $assignedProduct = StoreProduct::query()->find($assignment->product_id);
                        $safeDiscountCap = $assignedProduct && $assignedProduct->cost_price_rials !== null
                            ? max(0, ((int) $assignedProduct->price_rials - (int) $assignedProduct->cost_price_rials - max(0, (int) $assignedProduct->marketing_min_margin_rials)) * $eligibleQuantity)
                            : 0;
                        $validAssignment = $coupon->type === 'fixed'
                            && (int) $coupon->value <= $safeDiscountCap
                            && $request->user()
                            && (int) $assignment->user_id === (int) $request->user()->id
                            && $assignment->status === 'offered'
                            && $assignment->expires_at
                            && $assignment->expires_at->gt(now())
                            && $eligibleLineTotal > 0
                            && ! Order::query()->where('user_id', $assignment->user_id)->where('status', 'paid')
                                ->whereHas('storeItems', fn ($orderItems) => $orderItems->where('product_id', $assignment->product_id))->exists();

                        if ($validAssignment) {
                            $discount = $coupon->type === 'percent'
                                ? (int) floor($eligibleLineTotal * (int) $coupon->value / 100)
                                : min($eligibleLineTotal, (int) $coupon->value);
                            $couponAssignmentId = (int) $assignment->id;
                            $couponProductId = (int) $assignment->product_id;
                        } else {
                            $couponCode = null;
                        }
                    } else {
                        $discount = $coupon->type === 'percent'
                            ? (int) floor($subtotal * (int) $coupon->value / 100)
                            : min($subtotal, (int) $coupon->value);
                    }
                } else {
                    $couponCode = null;
                }

                if ($couponCode === null) {
                    $request->session()->forget('store_coupon_code');
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
            'coupon_assignment_id' => $couponAssignmentId,
            'coupon_product_id' => $couponProductId,
        ];
    }

    public function isPurchasable(StoreProduct $product): bool
    {
        return $product->status === 'published'
            && $product->published_at !== null
            && $product->published_at->lte(now());
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
