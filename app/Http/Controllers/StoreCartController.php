<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\SiteSetting;
use App\Models\StoreLibraryItem;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use App\Services\StoreCartService;
use App\Services\StoreCourseEnrollmentService;
use App\Services\MarketingSignalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreCartController extends Controller
{
    public function index(Request $request, StoreCartService $cart)
    {
        $checkoutIdempotencyKey = $request->session()->get('store_checkout_idempotency_key');
        if (! is_string($checkoutIdempotencyKey) || ! Str::isUuid($checkoutIdempotencyKey)) {
            $checkoutIdempotencyKey = (string) Str::uuid();
            $request->session()->put('store_checkout_idempotency_key', $checkoutIdempotencyKey);
        }
        $current = $cart->current($request);
        $payload = $cart->payload($current, $request);

        $recentIds = collect($request->session()->get('store_recent_ids', []))->take(8);
        $recent = $recentIds->isEmpty()
            ? collect()
            : StoreProduct::published()->whereIn('id', $recentIds)->get()->sortBy(fn ($p) => $recentIds->search($p->id));

        return view('store.cart', ['cart' => $payload, 'recent' => $recent, 'checkoutIdempotencyKey' => $checkoutIdempotencyKey]);
    }

    public function summary(Request $request, StoreCartService $cart)
    {
        return response()->json(['ok' => true] + $cart->payload($cart->current($request), $request));
    }

    public function add(Request $request, StoreCartService $cart, StoreProduct $product)
    {
        $data = $request->validate(['quantity' => 'nullable|integer|min:1|max:99']);
        $current = $cart->add($request, $product, (int) ($data['quantity'] ?? 1));
        if ($request->user()) {
            app(MarketingSignalService::class)->record($request->user(), 'cart_added', (int) $product->id);
        }

        return response()->json(['ok' => true] + $cart->payload($current, $request));
    }

    public function update(Request $request, StoreCartService $cart, StoreProduct $product)
    {
        $data = $request->validate(['quantity' => 'required|integer|min:0|max:99']);
        $current = $cart->update($request, $product, (int) $data['quantity']);

        return response()->json(['ok' => true] + $cart->payload($current, $request));
    }

    public function remove(Request $request, StoreCartService $cart, StoreProduct $product)
    {
        $current = $cart->remove($request, $product);

        return response()->json(['ok' => true] + $cart->payload($current, $request));
    }

    public function applyCoupon(Request $request, StoreCartService $cart)
    {
        $data = $request->validate(['code' => 'required|string|max:40']);
        if ($request->user()) {
            foreach ($cart->current($request)->items as $cartItem) {
                app(MarketingSignalService::class)->record($request->user(), 'coupon_attempted', (int) $cartItem->product_id);
            }
        }
        try {
            $payload = $cart->applyCoupon($request, $data['code']);
            if ($request->expectsJson()) {
                return response()->json(['ok' => true] + $payload);
            }

            return back()->with('status', 'کد تخفیف اعمال شد.');
        } catch (\RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['coupon' => $e->getMessage()]);
        }
    }

    public function clearCoupon(Request $request, StoreCartService $cart)
    {
        $payload = $cart->clearCoupon($request);
        if ($request->expectsJson()) {
            return response()->json(['ok' => true] + $payload);
        }

        return back()->with('status', 'کد تخفیف حذف شد.');
    }

    public function checkout(Request $request, StoreCartService $cart)
    {
        $data = $request->validate([
            'accept_terms' => ['accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        abort_unless($request->user(), 401);

        $idempotencyKey = 'store-cart-checkout-'.$request->user()->id.'-'.$data['idempotency_key'];
        $existingOrder = Order::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existingOrder) {
            $request->session()->put('store_checkout_idempotency_key', (string) Str::uuid());
            return $existingOrder->status === 'paid'
                ? redirect()->route('library')->with('status', 'این سفارش قبلاً ثبت و تسویه شده است.')
                : redirect()->route('checkout', $existingOrder);
        }

        $sessionKey = (string) $request->session()->get('store_checkout_idempotency_key', '');
        abort_unless($sessionKey !== '' && hash_equals($sessionKey, $data['idempotency_key']), 419, 'فرم تسویه منقضی شده است؛ صفحه سبد خرید را دوباره باز کنید.');

        $current = $cart->current($request)->load('items.product.files');
        $payload = $cart->payload($current, $request);
        abort_if(empty($payload['items']), 422, 'سبد خرید خالی است.');

        $order = DB::transaction(function () use ($request, $current, $cart, $idempotencyKey) {
            $existing = Order::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            $lockedCart = \App\Models\StoreCart::query()->whereKey($current->id)->lockForUpdate()->first();
            if (! $lockedCart) {
                $existing = Order::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }
                abort(409, 'سبد خرید قبلاً تسویه شده یا تغییر کرده است.');
            }
            $lockedCart->load('items.product.files');
            $payload = $cart->payload($lockedCart, $request);
            abort_if(empty($payload['items']), 422, 'سبد خرید خالی است.');

            $taxRate = max(0, (float) SiteSetting::read('tax_rate_percent', 10));
            $taxEnabled = filter_var(SiteSetting::read('tax_enabled', true), FILTER_VALIDATE_BOOLEAN);
            $subtotal = (int) $payload['subtotal_rials'];
            $discount = (int) $payload['discount_rials'];
            $taxable = max(0, $subtotal - $discount);
            $tax = ($taxable > 0 && $taxEnabled) ? (int) round($taxable * $taxRate / 100) : 0;
            $total = $taxable + $tax;

            $order = Order::create([
                'idempotency_key' => $idempotencyKey,
                'user_id' => $request->user()->id,
                'document_id' => null,
                'subtotal_rials' => $subtotal,
                'discount_rials' => $discount,
                'tax_rials' => $tax,
                'total_rials' => $total,
                'status' => 'pending',
                'pricing_snapshot' => [
                    'kind' => 'store',
                    'tax_rate' => $taxRate,
                    'cart_items' => $payload['items'],
                    'coupon_code' => $payload['coupon_code'],
                    'coupon_assignment_id' => $payload['coupon_assignment_id'] ?? null,
                    'coupon_product_id' => $payload['coupon_product_id'] ?? null,
                    'coupon_redemption_pending' => (bool) $payload['coupon_code'],
                ],
                'free_pages_applied' => 0,
                'terms_accepted_at' => now(),
            ]);

            $orderItems = [];
            $cartItems = $current->items->values();
            $remainingDiscount = $discount;
            $remainingTax = $tax;
            $remainingSubtotal = $subtotal;
            $remainingTaxable = $taxable;
            $targetProductId = (int) ($payload['coupon_product_id'] ?? 0);

            foreach ($cartItems as $index => $item) {
                $product = $item->product;
                if (! $product || ! app(StoreCartService::class)->isPurchasable($product)) {
                    throw new \RuntimeException('یکی از محصولات دیگر قابل خرید نیست.');
                }
                $file = null;
                if ($product->type === 'course') {
                    if (! $product->course || ! $product->course->sections()->whereHas('lessons', fn ($query) => $query->where('is_published', true))->exists()) {
                        throw new \RuntimeException('برنامه آموزشی این دوره هنوز برای تحویل آماده نیست.');
                    }
                } else {
                    $file = $product->files()
                        ->where('purpose', 'product_download')
                        ->where('is_active', true)
                        ->whereIn('scan_status', ['clean', 'legacy'])
                        ->orderByDesc('is_primary')
                        ->orderByDesc('id')
                        ->first();
                    if (! $file || ! $file->is_active || ! in_array($file->scan_status, ['clean', 'legacy'], true)) {
                        throw new \RuntimeException('فایل اصلی محصول پس از اسکن امنیتی برای تحویل آماده نیست.');
                    }
                }

                $unit = (int) $product->price_rials;
                $quantity = (int) $item->quantity;
                $line = $unit * $quantity;
                $isLast = $index === $cartItems->count() - 1;

                if ($targetProductId > 0) {
                    $lineDiscount = (int) $product->id === $targetProductId
                        ? min($line, $remainingDiscount)
                        : 0;
                } else {
                    $lineDiscount = $isLast
                        ? $remainingDiscount
                        : min($line, $remainingSubtotal > 0 ? (int) floor($remainingDiscount * $line / $remainingSubtotal) : 0);
                }
                $remainingDiscount -= $lineDiscount;

                $lineTaxable = max(0, $line - $lineDiscount);
                $lineTax = $isLast
                    ? $remainingTax
                    : min($remainingTax, $remainingTaxable > 0 ? (int) floor($remainingTax * $lineTaxable / $remainingTaxable) : 0);
                $remainingTax -= $lineTax;
                $remainingSubtotal -= $line;
                $remainingTaxable -= $lineTaxable;

                $orderItem = StoreOrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'title_snapshot' => $product->title,
                    'sku_snapshot' => $product->sku,
                    'quantity' => $quantity,
                    'unit_price_rials' => $unit,
                    'discount_rials' => $lineDiscount,
                    'tax_rials' => $lineTax,
                    'total_rials' => $lineTaxable,
                    'product_snapshot' => [
                        'title' => $product->title,
                        'sku' => $product->sku,
                        'version' => $product->version,
                        'cost_price_rials' => $product->cost_price_rials === null ? null : (int) $product->cost_price_rials,
                        'cost_snapshot_captured_at' => now()->toIso8601String(),
                        // Pin digital delivery to the exact asset chosen when the order was placed.
                        'file_id' => $file?->id,
                        'file_sha256' => $file?->sha256,
                    ],
                ]);
                $orderItems[] = [$orderItem, $product, $file];
            }

            if ($remainingDiscount !== 0 || $remainingTax !== 0 || $remainingSubtotal !== 0 || $remainingTaxable !== 0) {
                throw new \RuntimeException('توزیع مالی سفارش با جمع مبالغ سبد مطابقت ندارد؛ سفارش ثبت نشد.');
            }

            $lockedCart->delete();
            $request->session()->forget('store_coupon_code');

            if ($total === 0) {
                Payment::firstOrCreate(['idempotency_key' => 'free-store-order-'.$order->id], [
                    'order_id' => $order->id,
                    'gateway' => 'free',
                    'amount_rials' => 0,
                    'status' => 'paid',
                    'transaction_id' => 'STORE-FREE-'.$order->id.'-'.now()->timestamp,
                    'paid_at' => now(),
                ]);
                app(\App\Services\StoreCouponRedemptionService::class)->redeemForPaidOrder($order);

                foreach ($orderItems as [$orderItem, $product, $file]) {
                    $libraryItem = StoreLibraryItem::firstOrCreate(
                        ['user_id' => $request->user()->id, 'product_id' => $product->id, 'order_id' => $order->id],
                        [
                            'order_item_id' => $orderItem->id,
                            'product_file_id' => $file?->id,
                            'granted_at' => now(),
                            'revoked_at' => null,
                        ]
                    );
                    app(StoreCourseEnrollmentService::class)->grant($libraryItem, $product);
                }
                $order->update(['status' => 'paid', 'paid_at' => now()]);
            }

            return $order->fresh();
        });
        $request->session()->forget('store_checkout_idempotency_key');

        if ((int) $order->total_rials === 0 && $order->status === 'paid') {
            return redirect()->route('library')->with('status', 'سفارش رایگان ثبت شد؛ فایل یا دسترسی دوره در کتابخانه شما فعال است.');
        }

        return redirect()->route('checkout', $order);
    }
}
