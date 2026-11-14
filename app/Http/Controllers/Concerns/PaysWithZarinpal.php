<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Order;
use App\Models\Payment;
use App\Services\EmailService;
use App\Services\ZarinpalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait PaysWithZarinpal
{
    public function payWithZarinpal(Request $request, Order $order, ZarinpalService $zarinpal)
    {
        abort_unless($order->user_id === auth()->id(), 404);
        abort_unless(($order->pricing_snapshot['kind'] ?? null) === 'store', 422, 'درگاه مستقیم فعلاً برای سفارش فروشگاه فعال است.');
        $request->validate(['accept_terms' => ['accepted']]);

        if (! $zarinpal->enabled()) {
            return back()->withErrors(['payment' => 'درگاه زرین‌پال پیکربندی نشده است.']);
        }

        $amount = (int) $order->total_rials;
        if ($amount <= 0) {
            return back()->withErrors(['payment' => 'مبلغ سفارش برای درگاه معتبر نیست.']);
        }

        // Reserve the order before contacting the gateway. Wallet payment cannot
        // proceed while this external payment attempt is being initialized.
        try {
            [$lockedOrder, $payment] = DB::transaction(function () use ($order, $amount) {
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->status === 'pending', 409, 'این سفارش در حال پرداخت یا قبلاً تعیین تکلیف شده است.');
                abort_unless((int) $locked->total_rials === $amount, 409, 'مبلغ سفارش تغییر کرده است.');

                $locked->update(['status' => 'gateway_pending']);
                $attempt = Payment::create([
                    'order_id' => $locked->id,
                    'gateway' => 'zarinpal',
                    'idempotency_key' => 'zarinpal-attempt-'.Str::uuid(),
                    'amount_rials' => $amount,
                    'status' => 'initiating',
                ]);

                return [$locked->fresh(), $attempt];
            });
        } catch (\Throwable $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        try {
            $result = $zarinpal->request(
                $amount,
                'پرداخت سفارش فروشگاه #'.$lockedOrder->id,
                route('checkout.zarinpal.callback'),
                auth()->user()->mobile ?? null,
                auth()->user()->email ?? null
            );

            abort_unless(! empty($result['authority']) && ! empty($result['url']), 502, 'پاسخ درگاه معتبر نیست.');

            DB::transaction(function () use ($payment, $result) {
                $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
                abort_unless($lockedPayment->status === 'initiating', 409, 'درخواست پرداخت قبلاً تعیین تکلیف شده است.');
                $lockedPayment->update([
                    'authority' => $result['authority'],
                    'transaction_id' => $result['authority'],
                    'status' => 'pending',
                ]);
            });
        } catch (\Throwable $e) {
            DB::transaction(function () use ($lockedOrder, $payment) {
                $locked = Order::query()->whereKey($lockedOrder->id)->lockForUpdate()->first();
                $attempt = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
                if ($attempt && $attempt->status === 'initiating') {
                    $attempt->update(['status' => 'failed']);
                }
                if ($locked && $locked->status === 'gateway_pending') {
                    $locked->update(['status' => 'pending']);
                }
            });

            return back()->withErrors(['payment' => $e->getMessage()]);
        }

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

                abort_unless(in_array($locked->status, ['pending', 'gateway_pending'], true) && $lockedPayment->status === 'pending', 409, 'سفارش یا تراکنش قبلاً تعیین تکلیف شده است.');
                abort_unless((int) $lockedPayment->amount_rials === (int) $locked->total_rials, 409, 'مبلغ تراکنش با سفارش مطابقت ندارد.');

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

                try {
                    app(\App\Services\StoreCouponRedemptionService::class)->redeemForPaidOrder($locked);
                } catch (\RuntimeException $exception) {
                    // External funds are already captured. Preserve the paid order and
                    // expose a reconciliation marker instead of rolling payment back.
                    report($exception);
                    $gatewayResponse = $lockedPayment->gateway_response ?? [];
                    $gatewayResponse['coupon_redemption_reconciliation'] = [
                        'status' => 'required',
                        'reason_code' => 'post_capture_validation_failed',
                        'reason' => mb_substr($exception->getMessage(), 0, 180),
                        'recorded_at' => now()->toIso8601String(),
                    ];
                    $lockedPayment->forceFill(['gateway_response' => $gatewayResponse])->save();
                }

                $fulfillmentReady = true;
                try {
                    app(\App\Services\StoreOrderFulfillmentService::class)->fulfill($locked);
                } catch (\Throwable $exception) {
                    report($exception);
                    app(\App\Services\StoreOrderFulfillmentService::class)
                        ->markReconciliationRequired($lockedPayment, $exception);
                    $fulfillmentReady = false;
                }

                app(\App\Services\NotificationService::class)->push(
                    (int) $locked->user_id,
                    'order_paid',
                    $fulfillmentReady ? 'خرید شما با موفقیت ثبت شد' : 'پرداخت ثبت شد؛ آماده‌سازی محصول در حال پیگیری است',
                    $fulfillmentReady
                        ? 'سفارش فروشگاه شماره '.$locked->id.' از طریق زرین‌پال پرداخت شد و محتوای خریداری‌شده در کتابخانه شما قرار گرفت.'
                        : 'پرداخت سفارش شماره '.$locked->id.' تأیید شده است؛ تحویل محصول در حال بازیابی است و نتیجه جداگانه اطلاع‌رسانی می‌شود.',
                    ['order_id' => $locked->id, 'amount_rials' => (int) $locked->total_rials, 'url' => route('library')],
                    true,
                    'order:'.$locked->id.':paid:user:'.$locked->user_id,
                );

                // Zarinpal has already captured external funds. Persist the event
                // in the same transaction when possible; if the outbox is
                // temporarily unavailable, retain an explicit reconciliation
                // marker on the settled payment instead of rolling back capture.
                try {
                    app(\App\Services\WorkflowEngine::class)->enqueue(
                        'order.paid',
                        [
                            'user_id' => (int) $locked->user_id,
                            'customer_id' => (int) $locked->user_id,
                            'order_id' => (int) $locked->id,
                            'amount_rials' => (int) $locked->total_rials,
                            'order_kind' => (string) data_get($locked->pricing_snapshot, 'kind', 'unknown'),
                            'status' => 'paid',
                        ],
                        'order:'.$locked->id.':order.paid',
                    );
                } catch (\Throwable $exception) {
                    report($exception);
                    $gatewayResponse = $lockedPayment->gateway_response ?? [];
                    $gatewayResponse['workflow_event_reconciliation'] = [
                        'status' => 'required',
                        'event_type' => 'order.paid',
                        'idempotency_key' => 'order:'.$locked->id.':order.paid',
                        'reason_code' => 'outbox_persist_failed',
                        'reason' => mb_substr($exception->getMessage(), 0, 180),
                        'recorded_at' => now()->toIso8601String(),
                    ];
                    $lockedPayment->forceFill(['gateway_response' => $gatewayResponse])->save();
                }

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

        return redirect()->route('library')->with('status', 'پرداخت زرین‌پال موفق بود؛ فایل یا دوره در کتابخانه شما فعال شد.');
    }
}
