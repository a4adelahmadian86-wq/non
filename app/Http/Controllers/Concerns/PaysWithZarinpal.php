<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreLibraryItem;
use App\Models\StoreProductFile;
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
                'authority' => $result['authority'],
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

        // Bind the callback authority to the pending payment created for this exact order.
        $payment = Payment::query()
            ->where('order_id', $order->id)
            ->where('gateway', 'zarinpal')
            ->where(function ($query) use ($authority) {
                $query->where('authority', $authority)->orWhere('transaction_id', $authority);
            })
            ->latest('id')
            ->first();

        if (! $payment || (int) $payment->amount_rials !== (int) $order->total_rials) {
            return redirect()->route('checkout', $order)->withErrors(['payment' => 'تراکنش درگاه به این سفارش متصل نیست.']);
        }

        if ($order->isPaid() && $payment->status === 'paid') {
            $request->session()->forget(['zarinpal_order_id', 'zarinpal_authority']);
            return redirect()->route('library')->with('status', 'این سفارش قبلاً پرداخت شده است.');
        }

        if ($payment->status !== 'pending') {
            return redirect()->route('checkout', $order)->withErrors(['payment' => 'وضعیت تراکنش برای تأیید معتبر نیست.']);
        }

        if (strtoupper($status) !== 'OK') {
            return redirect()->route('checkout', $order)->withErrors(['payment' => 'پرداخت توسط کاربر لغو شد.']);
        }

        try {
            $verify = $zarinpal->verify($authority, (int) $payment->amount_rials);
        } catch (\Throwable $e) {
            return redirect()->route('checkout', $order)->withErrors(['payment' => $e->getMessage()]);
        }

        if (! in_array($verify['code'], [100, 101], true)) {
            return redirect()->route('checkout', $order)->withErrors(['payment' => 'تأیید پرداخت ناموفق بود (کد '.$verify['code'].').']);
        }

        $paymentProcessed = false;

        try {
            DB::transaction(function () use ($order, $payment, $authority, $verify, &$paymentProcessed) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

                abort_unless(
                    (int) $lockedPayment->order_id === (int) $locked->id
                        && $lockedPayment->gateway === 'zarinpal'
                        && ($lockedPayment->authority === $authority || $lockedPayment->transaction_id === $authority),
                    409,
                    'تراکنش درگاه با سفارش مطابقت ندارد.'
                );

                if ($locked->isPaid() && $lockedPayment->status === 'paid') {
                    return;
                }

                abort_unless($locked->status === 'pending' && $lockedPayment->status === 'pending', 409, 'سفارش یا تراکنش قبلاً تعیین تکلیف شده است.');
                abort_unless((int) $lockedPayment->amount_rials === (int) $locked->total_rials, 409, 'مبلغ تراکنش با سفارش مطابقت ندارد.');

                $items = $locked->storeItems()->with('product')->get();
                foreach ($items as $item) {
                    $product = $item->product;
                    if (! $product || $product->status !== 'published') {
                        throw new \RuntimeException('یکی از محصولات دیگر قابل خرید نیست.');
                    }

                    $snapshot = $item->product_snapshot ?? [];
                    $fileId = (int) ($snapshot['file_id'] ?? 0);
                    // Preserve the file selected at checkout; support older pending orders without snapshots.
                    $file = $fileId > 0
                        ? StoreProductFile::query()->whereKey($fileId)->where('product_id', $product->id)->first()
                        : ($product->files()->where('is_primary', true)->first()
                            ?: $product->files()->where('is_active', true)->first());

                    if (! $file || ! $file->is_active) {
                        throw new \RuntimeException('فایل محصول برای تحویل آماده نیست.');
                    }

                    $expectedHash = trim((string) ($snapshot['file_sha256'] ?? ''));
                    if ($expectedHash !== '' && ! hash_equals($expectedHash, (string) $file->sha256)) {
                        throw new \RuntimeException('نسخه فایل محصول پس از ثبت سفارش تغییر کرده است.');
                    }
                    StoreLibraryItem::firstOrCreate(
                        [
                            'user_id' => $locked->user_id,
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

                $lockedPayment->update([
                    'status' => 'paid',
                    'gateway_response' => [
                        'verification_code' => (int) $verify['code'],
                        'ref_id' => $verify['ref_id'] ?? null,
                    ],
                    'paid_at' => now(),
                    'transaction_id' => $verify['ref_id'] ? 'ZP-'.$verify['ref_id'] : $authority,
                ]);

                $locked->update(['status' => 'paid', 'paid_at' => now()]);
                $paymentProcessed = true;
            });
        } catch (\RuntimeException $e) {
            return redirect()->route('checkout', $order)->withErrors(['payment' => $e->getMessage()]);
        }

        $request->session()->forget(['zarinpal_order_id', 'zarinpal_authority']);

        if ($paymentProcessed) {
            try {
                $emailService->sendOrderPaid(auth()->user(), $order->fresh());
            } catch (\Throwable) {
            }
        }

        if ($paymentProcessed) {
            try {
                app(\App\Services\WorkflowEngine::class)->dispatch(
                    'order.paid',
                    ['user_id' => (int) $order->user_id, 'order_id' => (int) $order->id, 'amount_rials' => (int) $order->total_rials],
                    'order:'.$order->id.':paid',
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return redirect()->route('library')->with('status', 'پرداخت زرین‌پال موفق بود؛ فایل‌ها در کتابخانه شماست.');
    }
}
