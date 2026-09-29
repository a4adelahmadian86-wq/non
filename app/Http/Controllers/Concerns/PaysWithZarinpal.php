<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreLibraryItem;
use App\Services\EmailService;
use App\Services\ZarinpalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait PaysWithZarinpal
{
    public function payWithZarinpal(Request $request, Order $order, ZarinpalService $zarinpal)
    {
        abort_unless($order->user_id === auth()->id(), 404);
        abort_unless($order->status === 'pending', 409, 'این سفارش قابل پرداخت نیست.');
        abort_unless(($order->pricing_snapshot['kind'] ?? null) === 'store', 422, 'درگاه مستقیم فعلاً برای سفارش فروشگاه فعال است.');
        $request->validate(['accept_terms' => ['accepted']]);

        if (! $zarinpal->enabled()) {
            return back()->withErrors(['payment' => 'درگاه زرین‌پال پیکربندی نشده است.']);
        }

        $amount = (int) $order->total_rials;
        if ($amount <= 0) {
            return back()->withErrors(['payment' => 'مبلغ سفارش برای درگاه معتبر نیست.']);
        }

        try {
            $result = $zarinpal->request(
                $amount,
                'پرداخت سفارش فروشگاه #'.$order->id,
                route('checkout.zarinpal.callback'),
                auth()->user()->mobile ?? null,
                auth()->user()->email ?? null
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        Payment::updateOrCreate(
            ['order_id' => $order->id, 'gateway' => 'zarinpal', 'status' => 'pending'],
            [
                'amount_rials' => $amount,
                'transaction_id' => $result['authority'],
                'paid_at' => null,
            ]
        );

        $request->session()->put('zarinpal_order_id', $order->id);
        $request->session()->put('zarinpal_authority', $result['authority']);

        return redirect()->away($result['url']);
    }

    public function zarinpalCallback(Request $request, ZarinpalService $zarinpal, EmailService $emailService)
    {
        $authority = (string) $request->query('Authority', $request->session()->get('zarinpal_authority', ''));
        $status = (string) $request->query('Status', '');
        $orderId = (int) $request->session()->get('zarinpal_order_id', 0);
        $order = Order::whereKey($orderId)->where('user_id', auth()->id())->first();

        if (! $order || $authority === '') {
            return redirect()->route('store')->withErrors(['payment' => 'بازگشت از درگاه نامعتبر بود.']);
        }

        if (strtoupper($status) !== 'OK') {
            return redirect()->route('checkout', $order)->withErrors(['payment' => 'پرداخت توسط کاربر لغو شد.']);
        }

        try {
            $verify = $zarinpal->verify($authority, (int) $order->total_rials);
        } catch (\Throwable $e) {
            return redirect()->route('checkout', $order)->withErrors(['payment' => $e->getMessage()]);
        }

        if (! in_array($verify['code'], [100, 101], true)) {
            return redirect()->route('checkout', $order)->withErrors(['payment' => 'تأیید پرداخت ناموفق بود (کد '.$verify['code'].').']);
        }

        try {
            DB::transaction(function () use ($order, $authority, $verify) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->isPaid()) {
                    return;
                }

                $items = $locked->storeItems()->with(['product.files'])->get();
                foreach ($items as $item) {
                    $product = $item->product;
                    $file = $product?->files->firstWhere('is_primary', true)
                        ?: $product?->files->firstWhere('is_active', true);
                    if (! $product || ! $file) {
                        throw new \RuntimeException('فایل محصول برای تحویل آماده نیست.');
                    }
                    StoreLibraryItem::firstOrCreate(
                        [
                            'user_id' => auth()->id(),
                            'product_id' => $product->id,
                            'order_id' => $locked->id,
                        ],
                        [
                            'order_item_id' => $item->id,
                            'product_file_id' => $file->id,
                            'granted_at' => now(),
                            'revoked_at' => null,
                        ]
                    );
                }

                Payment::updateOrCreate(
                    ['order_id' => $locked->id, 'gateway' => 'zarinpal', 'transaction_id' => $authority],
                    [
                        'amount_rials' => (int) $locked->total_rials,
                        'status' => 'paid',
                        'paid_at' => now(),
                        'transaction_id' => $verify['ref_id'] ? 'ZP-'.$verify['ref_id'] : $authority,
                    ]
                );

                $locked->update(['status' => 'paid', 'paid_at' => now()]);
            });
        } catch (\RuntimeException $e) {
            return redirect()->route('checkout', $order)->withErrors(['payment' => $e->getMessage()]);
        }

        $request->session()->forget(['zarinpal_order_id', 'zarinpal_authority']);

        try {
            $emailService->sendOrderPaid(auth()->user(), $order->fresh());
        } catch (\Throwable) {
        }

        return redirect()->route('library')->with('status', 'پرداخت زرین‌پال موفق بود؛ فایل‌ها در کتابخانه شماست.');
    }
}
