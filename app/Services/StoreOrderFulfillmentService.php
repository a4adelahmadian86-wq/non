<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreCourse;
use App\Models\StoreLibraryItem;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Services\Storage\StorageManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class StoreOrderFulfillmentService
{
    public function __construct(private readonly StorageManager $storage)
    {
    }

    public function assertReady(Order $order, bool $requirePurchasable = false): void
    {
        if ($order->status !== 'pending' || data_get($order->pricing_snapshot, 'kind') !== 'store') {
            throw new RuntimeException('فقط سفارش فروشگاه در انتظار پرداخت قابل پیش‌بررسی است.');
        }

        $this->resolveDeliverables($order, $requirePurchasable);
    }

    /**
     * Grant the exact product entitlements represented by a settled order.
     * This method is idempotent and uses the checkout file snapshot, not the
     * product's current default file, whenever that snapshot is available.
     */
    public function fulfill(Order $order): int
    {
        return DB::transaction(function () use ($order): int {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $lockedOrder->isPaid() || ! $lockedOrder->user_id || data_get($lockedOrder->pricing_snapshot, 'kind') !== 'store') {
                throw new RuntimeException('تحویل فقط برای سفارش فروشگاهِ تسویه‌شده و دارای مالک مجاز است.');
            }

            $payments = Payment::query()
                ->where('order_id', $lockedOrder->id)
                ->where('status', 'paid')
                ->whereNotNull('paid_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($payments->isEmpty() || (int) $payments->sum('amount_rials') !== (int) $lockedOrder->total_rials) {
                throw new RuntimeException('سوابق پرداخت موفق با مبلغ سفارش تطبیق ندارد؛ تحویل انجام نشد.');
            }

            $deliverables = $this->resolveDeliverables($lockedOrder, false);
            $processed = 0;

            foreach ($deliverables as [$item, $product, $file]) {
                $identity = [
                    'user_id' => (int) $lockedOrder->user_id,
                    'product_id' => (int) $product->id,
                    'order_id' => (int) $lockedOrder->id,
                ];

                $libraryItem = StoreLibraryItem::query()
                    ->where($identity)
                    ->lockForUpdate()
                    ->first();

                if (! $libraryItem) {
                    $libraryItem = StoreLibraryItem::create($identity + [
                        'order_item_id' => (int) $item->id,
                        'product_file_id' => $file?->id,
                        'granted_at' => now(),
                        'revoked_at' => null,
                    ]);
                } else {
                    if ($libraryItem->revoked_at !== null) {
                        throw new RuntimeException('مجوز قبلی این محصول باطل شده و تحویل خودکار مجاز نیست.');
                    }

                    if ($libraryItem->order_item_id && (int) $libraryItem->order_item_id !== (int) $item->id) {
                        throw new RuntimeException('مجوز کتابخانه به قلم سفارش دیگری متصل است.');
                    }

                    if ($file && $libraryItem->product_file_id && (int) $libraryItem->product_file_id !== (int) $file->id) {
                        throw new RuntimeException('مجوز کتابخانه به نسخه فایل دیگری متصل است.');
                    }

                    $backfill = [];
                    if (! $libraryItem->order_item_id) {
                        $backfill['order_item_id'] = (int) $item->id;
                    }
                    if ($file && ! $libraryItem->product_file_id) {
                        $backfill['product_file_id'] = (int) $file->id;
                    }
                    if ($backfill !== []) {
                        $libraryItem->forceFill($backfill)->save();
                    }
                }

                app(StoreCourseEnrollmentService::class)->grant($libraryItem, $product);
                $processed++;
            }

            return $processed;
        }, 3);
    }

    /**
     * Mark a captured gateway payment for durable fulfillment recovery.
     * The financial settlement is not rolled back when delivery is unavailable.
     */
    public function markReconciliationRequired(Payment $payment, Throwable $exception): void
    {
        $payment->refresh();
        $gatewayResponse = $payment->gateway_response ?? [];
        $marker = $gatewayResponse['fulfillment_reconciliation'] ?? [];
        $marker = is_array($marker) ? $marker : [];
        $marker['status'] = 'required';
        $marker['attempts'] = (int) ($marker['attempts'] ?? 0);
        $marker['reason_code'] = 'post_capture_fulfillment_failed';
        $marker['last_error'] = mb_substr($exception->getMessage(), 0, 180);
        $marker['recorded_at'] = $marker['recorded_at'] ?? now()->toIso8601String();
        $marker['last_attempt_at'] = now()->toIso8601String();
        $gatewayResponse['fulfillment_reconciliation'] = $marker;

        $payment->forceFill(['gateway_response' => $gatewayResponse])->save();
    }

    /**
     * Retry only explicitly marked post-capture failures. Historical paid orders
     * are never silently backfilled or granted new entitlements.
     */
    public function retryPendingFulfillments(int $limit = 50): int
    {
        if (! Schema::hasTable('payments') || ! Schema::hasTable('orders')) {
            return 0;
        }

        $payments = Payment::query()
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->where('gateway_response->fulfillment_reconciliation->status', 'required')
            ->orderBy('id')
            ->limit(max(1, min($limit, 200)))
            ->get();

        $completed = 0;
        foreach ($payments as $payment) {
            try {
                $order = Order::query()->whereKey($payment->order_id)->first();
                if (! $order) {
                    throw new RuntimeException('سفارش مربوط به پرداخت بازیابی‌پذیر نیست.');
                }

                $this->fulfill($order);
                $this->markReconciled($payment);
                try {
                    app(NotificationService::class)->push(
                        (int) $order->user_id,
                        'order_fulfilled',
                        'محتوای خرید شما آماده است',
                        'تحویل سفارش شماره '.$order->id.' با موفقیت تکمیل شد و محتوا در کتابخانه شما قرار دارد.',
                        ['order_id' => $order->id, 'url' => route('library')],
                        true,
                        'order:'.$order->id.':fulfilled:user:'.$order->user_id,
                    );
                } catch (Throwable $notificationException) {
                    report($notificationException);
                }
                $completed++;
            } catch (Throwable $exception) {
                $this->recordRetryFailure($payment, $exception);
            }
        }

        return $completed;
    }

    /**
     * @return array<int, array{0:\App\Models\StoreOrderItem,1:StoreProduct,2:?StoreProductFile}>
     */
    private function resolveDeliverables(Order $order, bool $requirePurchasable): array
    {
        if (! $order->user_id || data_get($order->pricing_snapshot, 'kind') !== 'store') {
            throw new RuntimeException('مرجع مالک یا نوع سفارش برای تحویل معتبر نیست.');
        }

        $items = $order->storeItems()->orderBy('id')->lockForUpdate()->get();
        if ($items->isEmpty()) {
            throw new RuntimeException('سفارش فروشگاه فاقد قلم قابل تحویل است.');
        }

        $itemsTotal = (int) $items->sum(
            fn ($item): int => (int) $item->total_rials + (int) $item->tax_rials,
        );
        if ($itemsTotal !== (int) $order->total_rials) {
            throw new RuntimeException('جمع اقلام سفارش با مبلغ نهایی سفارش مطابقت ندارد.');
        }

        $deliverables = [];
        foreach ($items as $item) {
            $product = StoreProduct::query()->whereKey($item->product_id)->lockForUpdate()->first();
            if (! $product) {
                throw new RuntimeException('محصول مرجع یکی از اقلام سفارش پیدا نشد.');
            }

            if ($requirePurchasable && ! app(StoreCartService::class)->isPurchasable($product)) {
                throw new RuntimeException('یکی از محصولات دیگر برای خرید جدید فعال نیست.');
            }

            $file = null;
            if ($product->type === 'course') {
                $course = StoreCourse::query()->where('product_id', $product->id)->lockForUpdate()->first();
                if (! $course || ! $course->sections()->whereHas('lessons', fn ($query) => $query->where('is_published', true))->exists()) {
                    throw new RuntimeException('ساختار آموزشی دوره برای تحویل آماده نیست.');
                }
            } elseif ($product->type === 'digital_file') {
                $snapshot = $item->product_snapshot ?? [];
                $fileId = (int) ($snapshot['file_id'] ?? 0);
                $fileQuery = StoreProductFile::query()
                    ->where('product_id', $product->id)
                    ->where('purpose', 'product_download');

                $file = $fileId > 0
                    ? $fileQuery->whereKey($fileId)->lockForUpdate()->first()
                    : $fileQuery->where('is_active', true)
                        ->whereIn('scan_status', ['clean', 'legacy'])
                        ->orderByDesc('is_primary')
                        ->lockForUpdate()
                        ->first();

                if (! $file || ! $file->is_active || ! in_array($file->scan_status, ['clean', 'legacy'], true)) {
                    throw new RuntimeException('فایل دقیق ثبت‌شده در سفارش برای تحویل امن آماده نیست.');
                }

                $expectedHash = trim((string) ($snapshot['file_sha256'] ?? ''));
                if ($expectedHash !== '' && ! hash_equals($expectedHash, (string) $file->sha256)) {
                    throw new RuntimeException('نسخه فایل محصول با snapshot سفارش مطابقت ندارد.');
                }

                $disk = $file->disk ?: 'private';
                if (! $this->storage->exists($disk, $file->path)) {
                    throw new RuntimeException('فایل مرجع سفارش در هسته ذخیره‌سازی قابل بازیابی نیست.');
                }

                if (filled($file->sha256)) {
                    $actualHash = hash('sha256', $this->storage->get($disk, $file->path));
                    if (! hash_equals((string) $file->sha256, $actualHash)) {
                        throw new RuntimeException('محتوای فایل در هسته ذخیره‌سازی با checksum ثبت‌شده مطابقت ندارد.');
                    }
                }
            } else {
                throw new RuntimeException('نوع محصول برای تحویل دیجیتال پشتیبانی نمی‌شود.');
            }

            $deliverables[] = [$item, $product, $file];
        }

        return $deliverables;
    }

    private function markReconciled(Payment $payment): void
    {
        $payment->refresh();
        $gatewayResponse = $payment->gateway_response ?? [];
        $marker = $gatewayResponse['fulfillment_reconciliation'] ?? [];
        if (! is_array($marker) || ($marker['status'] ?? null) !== 'required') {
            return;
        }

        $marker['status'] = 'completed';
        $marker['completed_at'] = now()->toIso8601String();
        unset($marker['last_error']);
        $gatewayResponse['fulfillment_reconciliation'] = $marker;
        $payment->forceFill(['gateway_response' => $gatewayResponse])->save();
    }

    private function recordRetryFailure(Payment $payment, Throwable $exception): void
    {
        $payment->refresh();
        $gatewayResponse = $payment->gateway_response ?? [];
        $marker = $gatewayResponse['fulfillment_reconciliation'] ?? [];
        if (! is_array($marker)) {
            $marker = [];
        }

        $attempts = (int) ($marker['attempts'] ?? 0) + 1;
        $marker['attempts'] = $attempts;
        $marker['status'] = $attempts >= 10 ? 'manual_review' : 'required';
        $marker['last_error'] = mb_substr($exception->getMessage(), 0, 180);
        $marker['last_attempt_at'] = now()->toIso8601String();
        $gatewayResponse['fulfillment_reconciliation'] = $marker;
        $payment->forceFill(['gateway_response' => $gatewayResponse])->save();
    }
}
