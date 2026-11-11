<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreCourse;
use App\Models\StoreCourseEnrollment;
use App\Models\StoreLibraryItem;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StoreCourseEnrollmentService
{
    public function grant(StoreLibraryItem $libraryItem, StoreProduct $product): ?StoreCourseEnrollment
    {
        if ($product->type !== 'course') {
            return null;
        }

        return DB::transaction(function () use ($libraryItem, $product): StoreCourseEnrollment {
            // Keep the global lock order consistent with checkout/callback paths:
            // Order → Library entitlement → Payment → Course.
            $order = Order::query()
                ->whereKey($libraryItem->order_id)
                ->lockForUpdate()
                ->first();

            $lockedLibraryItem = StoreLibraryItem::query()
                ->whereKey($libraryItem->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                ! $order
                || (int) $lockedLibraryItem->order_id !== (int) $order->id
                || (int) $lockedLibraryItem->product_id !== (int) $product->id
                || ! $lockedLibraryItem->user_id
                || $lockedLibraryItem->revoked_at !== null
            ) {
                throw new RuntimeException('مجوز کتابخانه برای اعطای دسترسی دوره معتبر یا فعال نیست.');
            }

            if (
                (int) $order->user_id !== (int) $lockedLibraryItem->user_id
                || $order->status !== 'paid'
                || ! $order->paid_at
                || data_get($order->pricing_snapshot, 'kind') !== 'store'
            ) {
                throw new RuntimeException('دسترسی دوره فقط پس از تسویه معتبر همان سفارش قابل اعطا است.');
            }

            $orderItem = StoreOrderItem::query()
                ->whereKey($lockedLibraryItem->order_item_id)
                ->where('order_id', $order->id)
                ->where('product_id', $product->id)
                ->first();

            if (! $orderItem || (int) $orderItem->quantity < 1) {
                throw new RuntimeException('قلم سفارش با محصول دوره و مجوز کتابخانه مطابقت ندارد.');
            }

            $payments = Payment::query()
                ->where('order_id', $order->id)
                ->where('status', 'paid')
                ->whereNotNull('paid_at')
                ->lockForUpdate()
                ->get();

            if ($payments->isEmpty() || (int) $payments->sum('amount_rials') !== (int) $order->total_rials) {
                throw new RuntimeException('سوابق پرداخت موفق با مبلغ نهایی سفارش تطبیق ندارد؛ دسترسی دوره اعطا نشد.');
            }

            $course = StoreCourse::query()
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if (! $course) {
                throw new RuntimeException('ساختار دوره برای تحویل دسترسی آماده نیست.');
            }

            $existing = StoreCourseEnrollment::query()
                ->where('library_item_id', $lockedLibraryItem->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    (int) $existing->course_id !== (int) $course->id
                    || (int) $existing->product_id !== (int) $product->id
                    || (int) $existing->user_id !== (int) $lockedLibraryItem->user_id
                ) {
                    throw new RuntimeException('ثبت‌نام موجود با مجوز کتابخانه و محصول دوره سازگار نیست.');
                }

                return $existing;
            }

            $startsAt = $lockedLibraryItem->granted_at ?? now();
            $expiresAt = $course->access_duration_days
                ? $startsAt->copy()->addDays((int) $course->access_duration_days)
                : null;

            return StoreCourseEnrollment::create([
                'course_id' => $course->id,
                'product_id' => $product->id,
                'user_id' => $lockedLibraryItem->user_id,
                'library_item_id' => $lockedLibraryItem->id,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
            ]);
        });
    }
}
