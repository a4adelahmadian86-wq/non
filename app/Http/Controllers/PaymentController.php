<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\SiteSetting;
use App\Models\StoreLibraryItem;
use App\Models\TypingDocument;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\EmailService;
use App\Services\FreeQuotaService;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{
    public function createForDocument(Request $request, TypingDocument $document, PricingService $pricing, FreeQuotaService $free)
    {
        abort_unless($document->user_id === auth()->id() && $document->status !== 'deleted', 404);
        $request->validate(['accept_terms' => ['accepted']]);

        $quote = $pricing->quote((string) $document->content, max(1, (int) $document->page_count));
        $freePages = $free->availablePages(auth()->user(), $this->freePagesSetting());
        $freeApplied = min(1, $freePages, $quote['pages']);
        [$subtotal, $discount, $tax, $gross] = $this->totals($quote, $freeApplied);
        $depositCredit = $this->paidDepositCredit($document);
        $total = max(0, $gross - $depositCredit);
        $hash = hash('sha256', (string) $document->content);

        $order = Order::create([
            'user_id' => auth()->id(),
            'document_id' => $document->id,
            'subtotal_rials' => $subtotal,
            'discount_rials' => $discount,
            'tax_rials' => $tax,
            'total_rials' => $total,
            'status' => 'pending',
            'content_hash' => $hash,
            'pricing_snapshot' => [
                'kind' => 'typing_final',
                'content_hash' => $hash,
                'quote' => $quote,
                'tax_rate' => $this->taxRate(),
                'free_pages_available' => $freePages,
                'gross_total_rials' => $gross,
                'deposit_credit_rials' => $depositCredit,
            ],
            'free_pages_applied' => $freeApplied,
            'terms_accepted_at' => now(),
        ]);

        return redirect()->route('checkout', $order);
    }

    public function show(Order $order)
    {
        abort_unless($order->user_id === auth()->id(), 404);
        $wallet = auth()->user()->wallet()->firstOrCreate([], ['balance_rials' => 0]);

        return view('checkout', compact('order', 'wallet'));
    }

    public function payWithWallet(Request $request, Order $order, PricingService $pricing, FreeQuotaService $free, EmailService $emailService)
    {
        abort_unless($order->user_id === auth()->id(), 404);
        $request->validate(['accept_terms' => ['accepted']]);

        if ($order->isDeposit()) {
            return $this->payDepositWithWallet($request, $order);
        }

        if (($order->pricing_snapshot['kind'] ?? null) === 'store') {
            return $this->payStoreOrderWithWallet($order, $emailService);
        }

        try {
            $paidOrder = null;

            DB::transaction(function () use ($order, $pricing, $free, &$paidOrder) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->isPaid()) return;

                $document = TypingDocument::whereKey($locked->document_id)
                    ->where('user_id', auth()->id())
                    ->lockForUpdate()
                    ->firstOrFail();

                $quote = $pricing->quote((string) $document->content, max(1, (int) $document->page_count));
                $available = $free->availablePages(auth()->user(), $this->freePagesSetting());
                $freeApplied = min(1, $available, $quote['pages']);
                [$subtotal, $discount, $tax, $gross] = $this->totals($quote, $freeApplied);
                $depositCredit = $this->paidDepositCredit($document, true);
                $total = max(0, $gross - $depositCredit);
                $hash = hash('sha256', (string) $document->content);

                $locked->update([
                    'subtotal_rials' => $subtotal,
                    'discount_rials' => $discount,
                    'tax_rials' => $tax,
                    'total_rials' => $total,
                    'free_pages_applied' => $freeApplied,
                    'content_hash' => $hash,
                    'pricing_snapshot' => [
                        'kind' => 'typing_final',
                        'content_hash' => $hash,
                        'quote' => $quote,
                        'tax_rate' => $this->taxRate(),
                        'free_pages_available' => $available,
                        'gross_total_rials' => $gross,
                        'deposit_credit_rials' => $depositCredit,
                    ],
                    'terms_accepted_at' => now(),
                ]);

                $wallet = Wallet::firstOrCreate(['user_id' => auth()->id()], ['balance_rials' => 0]);
                $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();

                if ($total > $wallet->balance_rials) {
                    throw new \RuntimeException('موجودی کیف پول برای این سفارش کافی نیست.');
                }

                $before = (int) $wallet->balance_rials;
                $after = $before - $total;

                if ($total > 0) {
                    $wallet->update(['balance_rials' => $after]);
                    WalletTransaction::create([
                        'wallet_id' => $wallet->id,
                        'type' => 'debit',
                        'amount_rials' => $total,
                        'balance_before' => $before,
                        'balance_after' => $after,
                        'reference_type' => 'order',
                        'reference_id' => $locked->id,
                        'description' => 'تسویه نهایی خدمات تایپ',
                        'idempotency_key' => 'order-'.$locked->id,
                    ]);
                }

                if ($freeApplied > 0) {
                    $free->consume(auth()->user(), $freeApplied, $this->freePagesSetting());
                }

                Payment::create([
                    'order_id' => $locked->id,
                    'gateway' => 'wallet',
                    'amount_rials' => $total,
                    'status' => 'paid',
                    'transaction_id' => 'WALLET-'.$locked->id.'-'.now()->timestamp,
                    'paid_at' => now(),
                ]);

                $locked->update(['status' => 'paid', 'paid_at' => now()]);
                $document->update(['status' => 'paid', 'price_rials' => $gross]);
                $paidOrder = $locked->fresh();
            });

            if ($paidOrder) {
                try { $emailService->sendOrderPaid(auth()->user(), $paidOrder); } catch (\Throwable) {}
            }

            return redirect()->route('editor')->with('status', 'پرداخت با موفقیت ثبت شد؛ اکنون خروجی قابل دریافت است.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }
    }

    private function payStoreOrderWithWallet(Order $order, EmailService $emailService)
    {
        try {
            $paidOrder = null;

            DB::transaction(function () use ($order, &$paidOrder) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->isPaid()) {
                    $paidOrder = $locked->fresh();
                    return;
                }

                $items = $locked->storeItems()->with(['product.files'])->lockForUpdate()->get();
                if ($items->isEmpty()) {
                    throw new \RuntimeException('سفارش فروشگاه فاقد محصول است.');
                }

                $subtotal = 0;
                $validatedItems = [];
                foreach ($items as $item) {
                    $product = $item->product;
                    if (! $product || $product->status !== 'published') {
                        throw new \RuntimeException('یکی از محصولات دیگر قابل خرید نیست.');
                    }
                    $file = $product->files->firstWhere('is_primary', true) ?: $product->files->firstWhere('is_active', true);
                    if (! $file || ! $file->is_active) {
                        throw new \RuntimeException('فایل محصول برای تحویل آماده نیست.');
                    }

                    $unit = max(0, (int) $product->price_rials);
                    $line = $unit * max(1, (int) $item->quantity);
                    $subtotal += $line;
                    $validatedItems[] = [$item, $product, $file, $unit, $line];
                }

                $taxRate = max(0, (float) SiteSetting::read('tax_rate_percent', 10));
                $taxEnabled = filter_var(SiteSetting::read('tax_enabled', true), FILTER_VALIDATE_BOOLEAN);
                $tax = $taxEnabled ? (int) round($subtotal * $taxRate / 100) : 0;
                $total = $subtotal + $tax;

                $locked->update([
                    'subtotal_rials' => $subtotal,
                    'discount_rials' => 0,
                    'tax_rials' => $tax,
                    'total_rials' => $total,
                    'pricing_snapshot' => [
                        'kind' => 'store',
                        'tax_rate' => $taxRate,
                        'items' => collect($validatedItems)->map(fn ($row) => [
                            'product_id' => $row[1]->id,
                            'title' => $row[1]->title,
                            'sku' => $row[1]->sku,
                            'quantity' => $row[0]->quantity,
                            'unit_price_rials' => $row[3],
                            'line_total_rials' => $row[4],
                            'file_id' => $row[2]->id,
                            'file_sha256' => $row[2]->sha256,
                        ])->values()->all(),
                    ],
                    'terms_accepted_at' => now(),
                ]);

                $wallet = Wallet::firstOrCreate(['user_id' => auth()->id()], ['balance_rials' => 0]);
                $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
                if ($total > $wallet->balance_rials) {
                    throw new \RuntimeException('موجودی کیف پول برای این سفارش کافی نیست.');
                }

                $before = (int) $wallet->balance_rials;
                $after = $before - $total;
                if ($total > 0) {
                    $wallet->update(['balance_rials' => $after]);
                    WalletTransaction::create([
                        'wallet_id' => $wallet->id,
                        'type' => 'debit',
                        'amount_rials' => $total,
                        'balance_before' => $before,
                        'balance_after' => $after,
                        'reference_type' => 'store_order',
                        'reference_id' => $locked->id,
                        'description' => 'خرید فایل دیجیتال',
                        'idempotency_key' => 'store-order-'.$locked->id,
                    ]);
                }

                Payment::create([
                    'order_id' => $locked->id,
                    'gateway' => 'wallet',
                    'amount_rials' => $total,
                    'status' => 'paid',
                    'transaction_id' => 'STORE-WALLET-'.$locked->id.'-'.now()->timestamp,
                    'paid_at' => now(),
                ]);

                foreach ($validatedItems as [$item, $product, $file]) {
                    StoreLibraryItem::firstOrCreate(
                        ['user_id' => auth()->id(), 'product_id' => $product->id, 'order_id' => $locked->id],
                        [
                            'order_item_id' => $item->id,
                            'product_file_id' => $file->id,
                            'license_code' => null,
                            'granted_at' => now(),
                            'revoked_at' => null,
                        ]
                    );
                }

                $locked->update(['status' => 'paid', 'paid_at' => now()]);
                $paidOrder = $locked->fresh();
            });

            if ($paidOrder) {
                try { $emailService->sendOrderPaid(auth()->user(), $paidOrder); } catch (\Throwable) {}
            }

            return redirect()->route('store')->with('status', 'خرید با موفقیت ثبت شد؛ فایل‌ها در کتابخانه شما قرار گرفتند.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }
    }

    private function payDepositWithWallet(Request $request, Order $order)
    {
        try {
            DB::transaction(function () use ($order, $request) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'deposit_paid') return;
                abort_unless($locked->isDeposit() && $locked->status === 'deposit_pending', 409, 'وضعیت بیعانه معتبر نیست.');

                $snapshot = $locked->pricing_snapshot ?: [];
                $path = (string) ($snapshot['source_path'] ?? '');
                $hash = (string) ($snapshot['source_hash'] ?? '');
                abort_unless($path !== '' && Storage::disk('private')->exists($path), 404, 'فایل مربوط به این برآورد پیدا نشد.');
                abort_unless(hash_equals($hash, hash('sha256', Storage::disk('private')->get($path))), 409, 'فایل پس از برآورد تغییر کرده است.');

                $wallet = Wallet::firstOrCreate(['user_id' => auth()->id()], ['balance_rials' => 0]);
                $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
                $amount = (int) $locked->total_rials;

                if ($amount > $wallet->balance_rials) {
                    throw new \RuntimeException('موجودی کیف پول برای پرداخت این مبلغ کافی نیست.');
                }

                $before = (int) $wallet->balance_rials;
                $after = $before - $amount;
                if ($amount > 0) {
                    $wallet->update(['balance_rials' => $after]);
                    WalletTransaction::create([
                        'wallet_id' => $wallet->id,
                        'type' => 'debit',
                        'amount_rials' => $amount,
                        'balance_before' => $before,
                        'balance_after' => $after,
                        'reference_type' => 'typing_deposit',
                        'reference_id' => $locked->id,
                        'description' => 'پیش‌پرداخت خدمات تایپ',
                        'idempotency_key' => 'typing-deposit-'.$locked->id,
                    ]);
                }

                Payment::create([
                    'order_id' => $locked->id,
                    'gateway' => 'wallet',
                    'amount_rials' => $amount,
                    'status' => 'paid',
                    'transaction_id' => 'DEPOSIT-WALLET-'.$locked->id.'-'.now()->timestamp,
                    'paid_at' => now(),
                ]);

                $locked->update(['status' => 'deposit_paid', 'paid_at' => now()]);
                $request->session()->put('typing_preflight_deposit_order', $locked->id);
                $request->session()->put('typing_preflight_accepted', [
                    'path' => $path,
                    'hash' => $hash,
                    'pages' => (int) ($snapshot['estimated_pages'] ?? 1),
                    'accepted_at' => now()->toIso8601String(),
                    'deposit_order_id' => $locked->id,
                ]);
            });

            return redirect()->route('editor')->with('status', 'پیش‌پرداخت ثبت شد؛ فایل آماده ورود به ویرایشگر است.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }
    }

    private function paidDepositCredit(TypingDocument $document, bool $lock = false): int
    {
        $query = Order::where('user_id', $document->user_id)
            ->where('document_id', $document->id)
            ->where('status', 'deposit_paid');
        if ($lock) $query->lockForUpdate();

        return $query->get()->sum(function (Order $order) {
            if (! $order->isDeposit()) return 0;
            return max(0, (int) ($order->pricing_snapshot['deposit_credit_rials'] ?? $order->total_rials));
        });
    }

    private function totals(array $quote, int $freeApplied): array
    {
        $subtotal = max(0, (int) $quote['price_rials']);
        $discount = min($subtotal, max(0, $freeApplied * (int) $quote['free_page_value_rials']));
        $tax = $this->tax($subtotal - $discount);

        return [$subtotal, $discount, $tax, max(0, $subtotal - $discount + $tax)];
    }

    private function freePagesSetting(): int
    {
        return max(0, (int) SiteSetting::read('weekly_free_pages', 1));
    }

    private function taxRate(): float
    {
        return max(0, (float) SiteSetting::read('tax_rate_percent', 10));
    }

    private function tax(int $taxable): int
    {
        return filter_var(SiteSetting::read('tax_enabled', true), FILTER_VALIDATE_BOOLEAN)
            ? (int) round(max(0, $taxable) * $this->taxRate() / 100)
            : 0;
    }
}
