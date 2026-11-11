<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PaysWithZarinpal;
use App\Models\Order;
use App\Models\FarastProject;
use App\Models\Payment;
use App\Models\SiteSetting;
use App\Models\StoreLibraryItem;
use App\Models\TypingDocument;
use App\Models\Wallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\EmailService;
use App\Services\FreeQuotaService;
use App\Services\PricingService;
use App\Services\ProjectBillingService;
use App\Services\StoreCourseEnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{
    use PaysWithZarinpal;

    public function createForDocument(Request $request, TypingDocument $document, PricingService $pricing, FreeQuotaService $free)
    {
        abort_unless($document->user_id === auth()->id() && $document->status !== 'deleted', 404);
        $request->validate(['accept_terms' => ['accepted']]);

        $project = $document->project_id ? FarastProject::whereKey($document->project_id)->where('user_id',auth()->id())->first() : null;
        $quote = $project ? app(ProjectBillingService::class)->quote($project, max(1,(int)$document->page_count), (string)$document->content) : $pricing->quote((string) $document->content, max(1, (int) $document->page_count));
        $freePages = $free->availablePages(auth()->user(), $this->freePagesSetting());
        $freeApplied = min(1, $freePages, $quote['pages']);
        [$subtotal, $discount, $tax, $gross] = $this->totals($quote, $freeApplied);
        $depositCredit = $this->paidDepositCredit($document);
        $total = max(0, $gross - $depositCredit);
        $hash = hash('sha256', (string) $document->content);

        $idempotencyKey = 'typing-document-'.$request->user()->id.'-'.$document->id.'-'.$hash;
        $order = Order::firstOrCreate(['idempotency_key' => $idempotencyKey], [
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

        if ($order->isPaid()) {
            return redirect()->route('library')->with('status', 'این سند قبلاً تسویه شده است.');
        }

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

                $project = $document->project_id ? FarastProject::whereKey($document->project_id)->where('user_id',auth()->id())->first() : null;
                $quote = $project ? app(ProjectBillingService::class)->quote($project, max(1,(int)$document->page_count), (string)$document->content) : $pricing->quote((string) $document->content, max(1, (int) $document->page_count));
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

                // Serialize wallet creation and balance mutations across different orders of the same user.
                User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
                $wallet = Wallet::firstOrCreate(['user_id' => $locked->user_id], ['balance_rials' => 0]);
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

                Payment::firstOrCreate(['idempotency_key' => 'wallet-typing-final-'.$locked->id], [
                    'order_id' => $locked->id,
                    'gateway' => 'wallet',
                    'amount_rials' => $total,
                    'status' => 'paid',
                    'transaction_id' => 'WALLET-'.$locked->id.'-'.now()->timestamp,
                    'paid_at' => now(),
                ]);

                $locked->update(['status' => 'paid', 'paid_at' => now()]);
                $document->update(['status' => 'paid', 'price_rials' => $gross]);
                app(\App\Services\NotificationService::class)->push(
                    (int) $locked->user_id,
                    'order_paid',
                    'پرداخت سفارش خدمات تایپ تأیید شد',
                    'پرداخت سفارش شماره '.$locked->id.' با موفقیت ثبت شد. مبلغ: '.number_format((int) $total).' ریال.',
                    ['order_id' => $locked->id, 'amount_rials' => (int) $total, 'url' => route('dashboard')],
                    true,
                );
                if ($document->project_id) {
                    $project = FarastProject::whereKey($document->project_id)->where('user_id', auth()->id())->lockForUpdate()->first();
                    if ($project) $project->update(['paid_rials' => max((int)$project->paid_rials, (int)$total), 'billing_state' => array_merge($project->billing_state ?? [], ['status'=>'paid','amount_remaining'=>0]), 'output_state' => ['status'=>'unlocked','reason'=>'payment_verified']]);
                }
                $paidOrder = $locked->fresh();
            });

            if ($paidOrder) {
                try { $emailService->sendOrderPaid(auth()->user(), $paidOrder); } catch (\Throwable) {}
            }
            if ($paidOrder) $this->dispatchPaidOrderWorkflow($paidOrder);

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
                // A repeated wallet request for an already-paid order is a no-op.
                // Do not redispatch receipts, notifications, or post-payment workflows.
                if ($locked->isPaid()) {
                    return;
                }
                if ($locked->status !== 'pending') {
                    throw new \RuntimeException('وضعیت این سفارش اجازه پرداخت مجدد نمی‌دهد.');
                }

                $items = $locked->storeItems()->with(['product.files'])->lockForUpdate()->get();
                if ($items->isEmpty()) {
                    throw new \RuntimeException('سفارش فروشگاه فاقد محصول است.');
                }

                // The order totals and per-item prices are immutable checkout snapshots.
                // Repricing here would make wallet and gateway payments disagree.
                $validatedItems = [];
                foreach ($items as $item) {
                    $product = $item->product;
                    if (! $product || ! app(\App\Services\StoreCartService::class)->isPurchasable($product)) {
                        throw new \RuntimeException('یکی از محصولات دیگر قابل خرید نیست.');
                    }

                    $file = null;
                    if ($product->type === 'course') {
                        if (! $product->course || ! $product->course->sections()->whereHas('lessons', fn ($query) => $query->where('is_published', true))->exists()) {
                            throw new \RuntimeException('برنامه آموزشی این دوره هنوز برای تحویل آماده نیست.');
                        }
                    } else {
                        $snapshot = $item->product_snapshot ?? [];
                        $fileId = (int) ($snapshot['file_id'] ?? 0);
                        $file = $fileId > 0
                            ? \App\Models\StoreProductFile::query()
                                ->whereKey($fileId)
                                ->where('product_id', $product->id)
                                ->where('purpose', 'product_download')
                                ->first()
                            : $product->files()->where('purpose', 'product_download')
                                ->where('is_active', true)
                                ->whereIn('scan_status', ['clean', 'legacy'])
                                ->orderByDesc('is_primary')
                                ->first();

                        if (! $file || ! $file->is_active || ! in_array($file->scan_status, ['clean', 'legacy'], true)) {
                            throw new \RuntimeException('فایل اصلی محصول پس از اسکن امنیتی برای تحویل آماده نیست.');
                        }

                        $expectedHash = trim((string) ($snapshot['file_sha256'] ?? ''));
                        if ($expectedHash !== '' && ! hash_equals($expectedHash, (string) $file->sha256)) {
                            throw new \RuntimeException('نسخه فایل محصول پس از ثبت سفارش تغییر کرده است.');
                        }
                    }

                    $validatedItems[] = [$item, $product, $file];
                }

                // Charge exactly the amount confirmed on the checkout page.
                $total = (int) $locked->total_rials;
                if ($total < 0) {
                    throw new \RuntimeException('مبلغ سفارش معتبر نیست.');
                }

                // Serialize wallet creation and balance mutations across different orders of the same user.
                User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
                $wallet = Wallet::firstOrCreate(['user_id' => $locked->user_id], ['balance_rials' => 0]);
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

                app(\App\Services\StoreCouponRedemptionService::class)->redeemForPaidOrder($locked);

                Payment::firstOrCreate(['idempotency_key' => 'wallet-store-order-'.$locked->id], [
                    'order_id' => $locked->id,
                    'gateway' => 'wallet',
                    'amount_rials' => $total,
                    'status' => 'paid',
                    'transaction_id' => 'STORE-WALLET-'.$locked->id.'-'.now()->timestamp,
                    'paid_at' => now(),
                ]);

                foreach ($validatedItems as [$item, $product, $file]) {
                    $libraryItem = StoreLibraryItem::firstOrCreate(
                        ['user_id' => auth()->id(), 'product_id' => $product->id, 'order_id' => $locked->id],
                        [
                            'order_item_id' => $item->id,
                            'product_file_id' => $file?->id,
                            'license_code' => null,
                            'granted_at' => now(),
                            'revoked_at' => null,
                        ]
                    );
                    app(StoreCourseEnrollmentService::class)->grant($libraryItem, $product);
                }

                $locked->update(['status' => 'paid', 'paid_at' => now()]);
                app(\App\Services\NotificationService::class)->push(
                    (int) $locked->user_id,
                    'order_paid',
                    'خرید شما با موفقیت ثبت شد',
                    'سفارش فروشگاه شماره '.$locked->id.' پرداخت شد و محتوای خریداری‌شده در کتابخانه شما قرار گرفت.',
                    ['order_id' => $locked->id, 'amount_rials' => (int) $total, 'url' => route('dashboard')],
                    true,
                );
                $paidOrder = $locked->fresh();
            });

            if ($paidOrder) {
                try { $emailService->sendOrderPaid(auth()->user(), $paidOrder); } catch (\Throwable) {}
            }
            if ($paidOrder) $this->dispatchPaidOrderWorkflow($paidOrder);

            return redirect()->route('store')->with('status', 'خرید با موفقیت ثبت شد؛ فایل یا دوره در کتابخانه شما فعال شد.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }
    }

    private function payDepositWithWallet(Request $request, Order $order)
    {
        try {
            DB::transaction(function () use ($order, $request) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $snapshot = $locked->pricing_snapshot ?: [];
                if ($locked->status === 'deposit_paid') {
                    $path = (string) ($snapshot['source_path'] ?? '');
                    $hash = (string) ($snapshot['source_hash'] ?? '');
                    abort_unless($path !== '' && Storage::disk('private')->exists($path), 404, 'فایل مربوط به این برآورد پیدا نشد.');
                    abort_unless(hash_equals($hash, hash('sha256', Storage::disk('private')->get($path))), 409, 'فایل پس از برآورد تغییر کرده است.');
                    $request->session()->put('typing_preflight_deposit_order', $locked->id);
                    $request->session()->put('typing_preflight_accepted', [
                        'path' => $path,
                        'hash' => $hash,
                        'pages' => (int) ($snapshot['estimated_pages'] ?? 1),
                        'accepted_at' => now()->toIso8601String(),
                        'deposit_order_id' => $locked->id,
                        'project_id' => (int) ($snapshot['project_id'] ?? 0) ?: null,
                    ]);
                    return;
                }
                abort_unless($locked->isDeposit() && $locked->status === 'deposit_pending', 409, 'وضعیت بیعانه معتبر نیست.');

                $path = (string) ($snapshot['source_path'] ?? '');
                $hash = (string) ($snapshot['source_hash'] ?? '');
                abort_unless($path !== '' && Storage::disk('private')->exists($path), 404, 'فایل مربوط به این برآورد پیدا نشد.');
                abort_unless(hash_equals($hash, hash('sha256', Storage::disk('private')->get($path))), 409, 'فایل پس از برآورد تغییر کرده است.');

                // Serialize wallet creation and balance mutations across different orders of the same user.
                User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
                $wallet = Wallet::firstOrCreate(['user_id' => $locked->user_id], ['balance_rials' => 0]);
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

                Payment::firstOrCreate(['idempotency_key' => 'wallet-typing-deposit-'.$locked->id], [
                    'order_id' => $locked->id,
                    'gateway' => 'wallet',
                    'amount_rials' => $amount,
                    'status' => 'paid',
                    'transaction_id' => 'DEPOSIT-WALLET-'.$locked->id.'-'.now()->timestamp,
                    'paid_at' => now(),
                ]);

                $locked->update(['status' => 'deposit_paid', 'paid_at' => now()]);
                app(\App\Services\NotificationService::class)->push(
                    (int) $locked->user_id,
                    'order_deposit_paid',
                    'پیش‌پرداخت خدمات تایپ تأیید شد',
                    'پیش‌پرداخت سفارش شماره '.$locked->id.' با موفقیت ثبت شد. مبلغ: '.number_format((int) $amount).' ریال.',
                    ['order_id' => $locked->id, 'amount_rials' => (int) $amount, 'url' => route('dashboard')],
                    true,
                );
                $request->session()->put('typing_preflight_deposit_order', $locked->id);
                $request->session()->put('typing_preflight_accepted', [
                    'path' => $path,
                    'hash' => $hash,
                    'pages' => (int) ($snapshot['estimated_pages'] ?? 1),
                    'accepted_at' => now()->toIso8601String(),
                    'deposit_order_id' => $locked->id,
                    'project_id' => (int) ($snapshot['project_id'] ?? 0) ?: null,
                ]);
            });

            $paidDeposit = $order->fresh();
            $projectId = (int) data_get($paidDeposit->pricing_snapshot, 'project_id', 0);
            $project = $projectId > 0
                ? FarastProject::query()->whereKey($projectId)->where('user_id', auth()->id())->first()
                : null;
            $this->dispatchPaidOrderWorkflow($paidDeposit);

            return redirect()->route('editor', $project ? ['project' => $project->id] : [])
                ->with('status', 'پیش‌پرداخت ثبت شد؛ فایل آماده ورود به ویرایشگر است.');
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

    private function dispatchPaidOrderWorkflow(Order $order): void
    {
        try {
            $eventType = $order->status === 'deposit_paid' ? 'order.deposit_paid' : 'order.paid';
            app(\App\Services\WorkflowEngine::class)->dispatch(
                $eventType,
                [
                    'user_id' => (int) $order->user_id,
                    'customer_id' => (int) $order->user_id,
                    'order_id' => (int) $order->id,
                    'amount_rials' => (int) $order->total_rials,
                    'order_kind' => (string) ($order->pricing_snapshot['kind'] ?? 'unknown'),
                ],
                'order:'.$order->id.':'.$eventType,
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }


}
