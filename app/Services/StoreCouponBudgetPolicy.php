<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/**
 * Serializes first-party monthly coupon-budget decisions for offer issuance
 * and final redemption. Active, unexpired smart offers reserve their value
 * until they expire, are revoked, or are redeemed.
 */
class StoreCouponBudgetPolicy
{
    /**
     * Must be called inside the caller's database transaction.
     * The shared setting row is the lock that prevents concurrent issuance
     * and redemption from consuming the same remaining budget.
     */
    public function allows(int $candidateDiscountRials, ?int $excludeAssignmentId = null): bool
    {
        $setting = SiteSetting::query()->firstOrCreate(
            ['key' => 'store_coupon_monthly_budget_rials'],
            ['value' => '0', 'is_secret' => false]
        );
        $setting = SiteSetting::query()->whereKey($setting->id)->lockForUpdate()->first();
        $cap = max(0, (int) ($setting?->value ?? 0));

        if ($cap === 0) {
            return true;
        }

        $now = now();
        $monthStart = $now->copy()->startOfMonth();

        $spent = Order::query()
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $monthStart)
            ->whereNotNull('pricing_snapshot->coupon_redeemed_at')
            ->whereNotNull('pricing_snapshot->coupon_code')
            ->sum('discount_rials');

        $reserved = DB::table('store_coupon_assignments as assignments')
            ->join('store_coupons as coupons', 'coupons.id', '=', 'assignments.coupon_id')
            ->where('assignments.status', 'offered')
            ->where('assignments.expires_at', '>', $now)
            ->where('coupons.is_active', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('coupons.ends_at')->orWhere('coupons.ends_at', '>', $now);
            })
            ->when($excludeAssignmentId !== null, fn ($query) => $query->where('assignments.id', '!=', $excludeAssignmentId))
            ->sum('coupons.value');

        return (int) $spent + (int) $reserved + max(0, $candidateDiscountRials) <= $cap;
    }
}
