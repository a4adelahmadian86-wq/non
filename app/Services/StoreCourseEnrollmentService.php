<?php

namespace App\Services;

use App\Models\StoreCourseEnrollment;
use App\Models\StoreLibraryItem;
use App\Models\StoreProduct;

class StoreCourseEnrollmentService
{
    public function grant(StoreLibraryItem $libraryItem, StoreProduct $product): ?StoreCourseEnrollment
    {
        if ($product->type !== 'course') {
            return null;
        }

        $course = $product->course;
        if (! $course) {
            throw new \RuntimeException('ساختار دوره برای تحویل دسترسی آماده نیست.');
        }

        $startsAt = $libraryItem->granted_at ?? now();
        $expiresAt = $course->access_duration_days
            ? $startsAt->copy()->addDays((int) $course->access_duration_days)
            : null;

        return StoreCourseEnrollment::firstOrCreate(
            ['library_item_id' => $libraryItem->id],
            [
                'course_id' => $course->id,
                'product_id' => $product->id,
                'user_id' => $libraryItem->user_id,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
            ]
        );
    }
}
