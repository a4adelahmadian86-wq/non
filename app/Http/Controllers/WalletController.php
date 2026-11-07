<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\ZarinpalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function index()
    {
        $wallet = auth()->user()->wallet()->firstOrCreate([], ['balance_rials' => 0]);
        $transactions = $wallet->transactions()->latest()->limit(30)->get();
        $gatewayEnabled = app(ZarinpalService::class)->enabled();

        return view('wallet', compact('wallet', 'transactions', 'gatewayEnabled'));
    }

    public function topUp(Request $request, ZarinpalService $zarinpal)
    {
        $data = $request->validate([
            'amount_rials' => ['required', 'integer', 'min:10000', 'max:1000000000'],
        ]);

        if (! $zarinpal->enabled()) {
            return back()->withErrors(['topup' => 'درگاه زرین‌پال فعال یا پیکربندی نشده است.']);
        }

        $amount = (int) $data['amount_rials'];
        $order = Order::create([
            'user_id' => $request->user()->id,
            'document_id' => null,
            'subtotal_rials' => $amount,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => $amount,
            'status' => 'pending',
            'pricing_snapshot' => [
                'kind' => 'wallet_topup',
                'amount_rials' => $amount,
                'currency' => 'IRR',
            ],
            'free_pages_applied' => 0,
            'terms_accepted_at' => now(),
        ]);

        try {
            $gateway = $zarinpal->request(
                $amount,
                'افزایش موجودی کیف پول - سفارش #' . $order->id,
                route('wallet.topup.callback'),
                $request->user()->mobile ?? null,
                $request->user()->email ?? null
            );

            Payment::create([
                'order_id' => $order->id,
                'gateway' => 'zarinpal',
                'authority' => $gateway['authority'],
                'transaction_id' => $gateway['authority'],
                'amount_rials' => $amount,
                'status' => 'pending',
            ]);
        } catch (\Throwable $exception) {
            $order->update(['status' => 'failed']);
            report($exception);

            return back()->withErrors(['topup' => 'اتصال به درگاه پرداخت انجام نشد؛ موجودی کیف پول تغییر نکرد.']);
        }

        return redirect()->away($gateway['url']);
    }

    /**
     * The gateway callback is public by design: payment is verified server-to-server
     * and credited only to the user bound to the pending order/payment record.
     */
    public function topUpCallback(Request $request, ZarinpalService $zarinpal)
    {
        $authority = trim((string) $request->query('Authority', ''));
        $status = strtoupper((string) $request->query('Status', ''));

        if ($authority === '') {
            return redirect()->route(auth()->check() ? 'wallet' : 'login')->withErrors(['topup' => 'شناسه بازگشت از درگاه معتبر نیست.']);
        }

        $payment = Payment::query()
            ->where('gateway', 'zarinpal')
            ->where('authority', $authority)
            ->latest('id')
            ->first();

        if (! $payment) {
            return redirect()->route(auth()->check() ? 'wallet' : 'login')->withErrors(['topup' => 'تراکنش افزایش موجودی پیدا نشد.']);
        }

        $order = Order::query()
            ->whereKey($payment->order_id)
            ->first();

        if ($order && ($order->pricing_snapshot['kind'] ?? null) !== 'wallet_topup') {
            $order = null;
        }
        if ($order && $order->status !== 'pending') {
            $order = null;
        }

        if (! $order) {
            if ($payment->status === 'paid') {
                return redirect()->route(auth()->check() ? 'wallet' : 'login')->with('status', 'این افزایش موجودی قبلاً ثبت شده است.');
            }

            return redirect()->route(auth()->check() ? 'wallet' : 'login')->withErrors(['topup' => 'سفارش افزایش موجودی قابل تأیید نیست.']);
        }

        if ((int) $payment->amount_rials !== (int) $order->total_rials || (int) $order->total_rials < 10000) {
            return redirect()->route(auth()->check() ? 'wallet' : 'login')->withErrors(['topup' => 'مبلغ تراکنش با سفارش افزایش موجودی مطابقت ندارد.']);
        }

        if ($status !== 'OK') {
            // Callback query parameters are controlled by the browser. Do not mutate
            // payment state based only on Status=NOK; a later verified callback may arrive.
            return redirect()->route(auth()->check() ? 'wallet' : 'login')->withErrors(['topup' => 'پرداخت تأیید نشد؛ هیچ اعتباری اضافه نشده است. در صورت کسر وجه، وضعیت تراکنش را پیگیری کنید.']);
        }

        try {
            $verification = $zarinpal->verify($authority, (int) $payment->amount_rials);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route(auth()->check() ? 'wallet' : 'login')->withErrors(['topup' => 'تأیید پرداخت از درگاه در دسترس نیست؛ موجودی تا تأیید قطعی تغییر نمی‌کند.']);
        }

        if (! in_array((int) $verification['code'], [100, 101], true)) {
            return redirect()->route(auth()->check() ? 'wallet' : 'login')->withErrors(['topup' => 'تأیید پرداخت ناموفق بود؛ موجودی کیف پول تغییر نکرد.']);
        }

        $credited = false;

        try {
            DB::transaction(function () use ($order, $payment, $authority, $verification, &$credited): void {
                $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

                abort_unless(
                    $lockedPayment->gateway === 'zarinpal'
                    && $lockedPayment->authority === $authority
                    && (int) $lockedPayment->order_id === (int) $lockedOrder->id
                    && (int) $lockedPayment->amount_rials === (int) $lockedOrder->total_rials
                    && ($lockedOrder->pricing_snapshot['kind'] ?? null) === 'wallet_topup',
                    409,
                    'تراکنش با سفارش افزایش موجودی مطابقت ندارد.'
                );

                if ($lockedOrder->status === 'paid' && $lockedPayment->status === 'paid') {
                    return;
                }

                abort_unless($lockedOrder->status === 'pending' && $lockedPayment->status === 'pending', 409, 'این تراکنش قبلاً تعیین تکلیف شده است.');

                $wallet = Wallet::query()->firstOrCreate(
                    ['user_id' => $lockedOrder->user_id],
                    ['balance_rials' => 0]
                );
                $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();

                $idempotencyKey = 'wallet-topup-order-' . $lockedOrder->id;
                $existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first();

                if (! $existing) {
                    $before = (int) $wallet->balance_rials;
                    $amount = (int) $lockedPayment->amount_rials;
                    $wallet->update(['balance_rials' => $before + $amount]);

                    WalletTransaction::create([
                        'wallet_id' => $wallet->id,
                        'type' => 'credit',
                        'amount_rials' => $amount,
                        'balance_before' => $before,
                        'balance_after' => $before + $amount,
                        'reference_type' => 'wallet_topup',
                        'reference_id' => $lockedOrder->id,
                        'description' => 'افزایش موجودی کیف پول از طریق زرین‌پال',
                        'idempotency_key' => $idempotencyKey,
                    ]);
                }

                $lockedPayment->update([
                    'status' => 'paid',
                    'gateway_response' => [
                        'verification_code' => (int) $verification['code'],
                        'ref_id' => $verification['ref_id'] ?? null,
                    ],
                    'transaction_id' => ! empty($verification['ref_id']) ? 'ZP-' . $verification['ref_id'] : $authority,
                    'paid_at' => now(),
                ]);
                $lockedOrder->update(['status' => 'paid', 'paid_at' => now()]);
                $credited = true;
            });
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route(auth()->check() ? 'wallet' : 'login')->withErrors(['topup' => 'ثبت نهایی افزایش موجودی انجام نشد؛ برای جلوگیری از ثبت تکراری، وضعیت تراکنش بررسی خواهد شد.']);
        }

        return redirect()->route(auth()->check() ? 'wallet' : 'login')->with(
            'status',
            $credited ? 'افزایش موجودی با موفقیت تأیید شد.' : 'این افزایش موجودی قبلاً ثبت شده است.'
        );
    }
}
