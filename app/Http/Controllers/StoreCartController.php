<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\SiteSetting;
use App\Models\StoreCoupon;
use App\Models\StoreLibraryItem;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use App\Services\StoreCartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreCartController extends Controller
{
    public function index(Request $request, StoreCartService $cart)
    {
        $current = $cart->current($request);
        $payload = $cart->payload($current, $request);

        $recentIds = collect($request->session()->get('store_recent_ids', []))->take(8);
        $recent = $recentIds->isEmpty()
            ? collect()
            : StoreProduct::published()->whereIn('id', $recentIds)->get()->sortBy(fn ($p) => $recentIds->search($p->id));

        return view('store.cart', ['cart' => $payload, 'recent' => $recent]);
    }

    public function add(Request $request, StoreCartService $cart, StoreProduct $product)
    {
        $data = $request->validate(['quantity' => 'nullable|integer|min:1|max:99']);
        $current = $cart->add($request, $product, (int) ($data['quantity'] ?? 1));

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
        $request->validate(['accept_terms' => ['accepted']]);
        abort_unless($request->user(), 401);

        $current = $cart->current($request)->load('items.product.files');
        $payload = $cart->payload($current, $request);
        abort_if(empty($payload['items']), 422, 'سبد خرید خالی است.');

        $order = DB::transaction(function () use ($request, $current, $payload) {
            $taxRate = max(0, (float) SiteSetting::read('tax_rate_percent', 10));
            $taxEnabled = filter_var(SiteSetting::read('tax_enabled', true), FILTER_VALIDATE_BOOLEAN);
            $subtotal = (int) $payload['subtotal_rials'];
            $discount = (int) $payload['discount_rials'];
            $taxable = max(0, $subtotal - $discount);
            $tax = ($taxable > 0 && $taxEnabled) ? (int) round($taxable * $taxRate / 100) : 0;
            $total = $taxable + $tax;

            $order = Order::create([
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
                ],
                'free_pages_applied' => 0,
                'terms_accepted_at' => now(),
            ]);

            $orderItems = [];
            foreach ($current->items as $item) {
                $product = $item->product;
                if (! $product || $product->status !== 'published') {
                    throw new \RuntimeException('یکی از محصولات دیگر قابل خرید نیست.');
                }
                $file = $product->files->firstWhere('is_primary', true)
                    ?: $product->files->firstWhere('is_active', true);
                if (! $file || ! $file->is_active) {
                    throw new \RuntimeException('فایل محصول برای تحویل آماده نیست.');
                }
                $unit = (int) $product->price_rials;
                $line = $unit * (int) $item->quantity;
                $orderItem = StoreOrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'title_snapshot' => $product->title,
                    'sku_snapshot' => $product->sku,
                    'quantity' => $item->quantity,
                    'unit_price_rials' => $unit,
                    'discount_rials' => 0,
                    'tax_rials' => 0,
                    'total_rials' => $line,
                    'product_snapshot' => [
                        'title' => $product->title,
                        'sku' => $product->sku,
                        'version' => $product->version,
                    ],
                ]);
                $orderItems[] = [$orderItem, $product, $file];
            }

            if ($payload['coupon_code']) {
                StoreCoupon::where('code', $payload['coupon_code'])->increment('used_count');
            }

            $current->delete();
            $request->session()->forget('store_coupon_code');

            if ($total === 0) {
                Payment::create([
                    'order_id' => $order->id,
                    'gateway' => 'free',
                    'amount_rials' => 0,
                    'status' => 'paid',
                    'transaction_id' => 'STORE-FREE-'.$order->id.'-'.now()->timestamp,
                    'paid_at' => now(),
                ]);
                foreach ($orderItems as [$orderItem, $product, $file]) {
                    StoreLibraryItem::firstOrCreate(
                        [
                            'user_id' => $request->user()->id,
                            'product_id' => $product->id,
                            'order_id' => $order->id,
                        ],
                        [
                            'order_item_id' => $orderItem->id,
                            'product_file_id' => $file->id,
                            'granted_at' => now(),
                            'revoked_at' => null,
                        ]
                    );
                }
                $order->update(['status' => 'paid', 'paid_at' => now()]);
            }

            return $order->fresh();
        });

        if ((int) $order->total_rials === 0 && $order->status === 'paid') {
            return redirect()->route('library')->with('status', 'سفارش رایگان ثبت شد؛ فایل‌ها در کتابخانه شماست.');
        }

        return redirect()->route('checkout', $order);
    }
}
