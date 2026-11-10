<?php

namespace App\Http\Controllers;

use App\Models\StoreCoupon;
use App\Models\StoreCouponAssignment;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\StoreProduct;
use App\Services\AdminConsoleService;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminStoreCouponController extends Controller
{
    private function visibleUserIds(Request $request, AdminConsoleService $console): ?array
    {
        $console->authorizeAction($request->user(), 'marketing');
        $ids = $console->visibleUserIds($request->user());

        return $ids === null ? null : array_values(array_unique(array_merge($ids, [(int) $request->user()->id])));
    }

    public function index(Request $request, AdminConsoleService $console)
    {
        $visibleIds = $this->visibleUserIds($request, $console);
        $assignments = StoreCouponAssignment::query()
            ->with(['coupon', 'product:id,title,slug', 'user:id,name,mobile'])
            ->when($visibleIds !== null, fn ($query) => $query->whereIn('user_id', $visibleIds));

        $status = $request->query('status');
        if (in_array($status, ['offered', 'redeemed', 'revoked', 'expired'], true)) {
            if ($status === 'expired') {
                $assignments->where('status', 'offered')->where('expires_at', '<=', now());
            } else {
                $assignments->where('status', $status);
                if ($status === 'offered') {
                    $assignments->where('expires_at', '>', now());
                }
            }
        }

        if ($request->filled('q')) {
            $term = mb_substr(trim((string) $request->query('q')), 0, 100);
            $assignments->where(function ($query) use ($term) {
                $query->whereHas('coupon', fn ($coupon) => $coupon->where('code', 'like', '%'.$term.'%'))
                    ->orWhereHas('product', fn ($product) => $product->where('title', 'like', '%'.$term.'%'))
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', '%'.$term.'%')->orWhere('mobile', 'like', '%'.$term.'%'));
            });
        }

        $base = StoreCouponAssignment::query()
            ->when($visibleIds !== null, fn ($query) => $query->whereIn('user_id', $visibleIds));
        $totalOffers = (clone $base)->count();
        $redeemed = (clone $base)->where('status', 'redeemed')->count();
        $offered = (clone $base)->where('status', 'offered')->where('expires_at', '>', now())->count();
        $expired = (clone $base)->where('status', 'offered')->where('expires_at', '<=', now())->count();
        $revoked = (clone $base)->where('status', 'revoked')->count();
        // Smart assignments target one product. Attribute only that product's
        // immutable order-item totals; never credit the full mixed-cart order.
        $attributedOrders = DB::table('store_coupon_assignments')
            ->join('orders', 'orders.id', '=', 'store_coupon_assignments.redeemed_order_id')
            ->join('store_order_items', function ($join) {
                $join->on('store_order_items.order_id', '=', 'orders.id')
                    ->on('store_order_items.product_id', '=', 'store_coupon_assignments.product_id');
            })
            ->when($visibleIds !== null, fn ($query) => $query->whereIn('store_coupon_assignments.user_id', $visibleIds))
            ->where('store_coupon_assignments.status', 'redeemed')
            ->where('orders.status', 'paid')
            ->whereNotNull('orders.paid_at');
        $attributedOrderCount = (clone $attributedOrders)->distinct()->count('orders.id');
        $attributedRevenue = 0;
        $redeemedDiscountCost = 0;
        $knownGrossProfit = 0;
        $itemsMissingHistoricalCost = 0;

        // Process in bounded batches so a large coupon history cannot exhaust
        // memory while the dashboard is calculating historical profit.
        (clone $attributedOrders)
            ->select([
                'store_order_items.id as order_item_id',
                'orders.discount_rials as order_discount_rials',
                'store_order_items.total_rials',
                'store_order_items.discount_rials',
                'store_order_items.quantity',
                'store_order_items.product_snapshot',
            ])
            ->orderBy('store_order_items.id')
            ->chunkById(500, function ($items) use (&$attributedRevenue, &$redeemedDiscountCost, &$knownGrossProfit, &$itemsMissingHistoricalCost): void {
                foreach ($items as $item) {
                    $snapshot = is_array($item->product_snapshot)
                        ? $item->product_snapshot
                        : (json_decode((string) $item->product_snapshot, true) ?: []);
                    $hasFinancialSnapshot = ! empty($snapshot['cost_snapshot_captured_at']);
                    $itemDiscount = $hasFinancialSnapshot
                        ? (int) $item->discount_rials
                        : (int) $item->order_discount_rials;
                    $itemRevenue = max(0, (int) $item->total_rials - ($hasFinancialSnapshot ? 0 : $itemDiscount));

                    $attributedRevenue += $itemRevenue;
                    $redeemedDiscountCost += $itemDiscount;

                    $cost = $snapshot['cost_price_rials'] ?? null;
                    if (! array_key_exists('cost_price_rials', $snapshot) || $cost === null || ! is_numeric($cost) || (int) $cost < 0) {
                        $itemsMissingHistoricalCost++;
                        continue;
                    }

                    $knownGrossProfit += $itemRevenue - ((int) $cost * (int) $item->quantity);
                }
            }, 'store_order_items.id', 'order_item_id');

        $canManageGlobalCoupons = $visibleIds === null;
        $monthlyBudgetCap = max(0, (int) SiteSetting::read('store_coupon_monthly_budget_rials', 0));
        $offerViews = DB::table('farast_workflow_events')
            ->where('event_type', 'marketing.offer_viewed')
            ->when($visibleIds !== null, fn ($query) => $query->whereIn('user_id', $visibleIds))
            ->count();
        $monthlyBudgetSpent = Order::query()
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', now()->startOfMonth())
            ->whereNotNull('pricing_snapshot->coupon_redeemed_at')
            ->whereNotNull('pricing_snapshot->coupon_code')
            ->sum('discount_rials');
        $genericCoupons = $canManageGlobalCoupons
            ? StoreCoupon::query()
                ->whereNotIn('id', StoreCouponAssignment::query()->select('coupon_id'))
                ->latest('id')
                ->limit(20)
                ->get()
            : collect();

        return view('admin.store-coupons.index', [
            'assignments' => $assignments->latest('offered_at')->paginate(25)->withQueryString(),
            'genericCoupons' => $genericCoupons,
            'canManageGlobalCoupons' => $canManageGlobalCoupons,
            'filters' => ['status' => $status, 'q' => $request->query('q')],
            'stats' => [
                'total' => $totalOffers,\n                'offer_views' => (int) $offerViews,\n                'view_to_redemption_rate' => $offerViews > 0 ? round($redeemed * 100 / $offerViews, 1) : 0,
                'redeemed' => $redeemed,
                'offered' => $offered,
                'expired' => $expired,
                'revoked' => $revoked,
                'conversion_rate' => $totalOffers > 0 ? round($redeemed * 100 / $totalOffers, 1) : 0,
                'discount_cost_rials' => $redeemedDiscountCost,
                'attributed_revenue_rials' => $attributedRevenue,
                'attributed_orders' => $attributedOrderCount,
                'known_gross_profit_rials' => $knownGrossProfit,
                'items_missing_historical_cost' => $itemsMissingHistoricalCost,
                'products_missing_cost' => StoreProduct::query()->published()->whereNull('cost_price_rials')->count(),
                'monthly_budget_spent_rials' => (int) $monthlyBudgetSpent,
                'monthly_budget_cap_rials' => $monthlyBudgetCap,
                'monthly_budget_remaining_rials' => $monthlyBudgetCap > 0 ? max(0, $monthlyBudgetCap - (int) $monthlyBudgetSpent) : null,
            ],
            'settings' => [
                'score_threshold' => (int) SiteSetting::read('smart_discount_score_threshold', 5),
                'signal_window_days' => (int) SiteSetting::read('smart_discount_signal_window_days', 14),
                'cooldown_days' => (int) SiteSetting::read('smart_discount_cooldown_days', 7),
                'offer_valid_hours' => (int) SiteSetting::read('smart_discount_offer_valid_hours', 48),
                'max_discount_percent' => (int) SiteSetting::read('smart_discount_max_discount_percent', 10),
                'abandonment_hours' => (int) SiteSetting::read('smart_discount_abandonment_hours', 2),
                'retention_days' => (int) SiteSetting::read('marketing_signal_retention_days', 90),
                'monthly_budget_rials' => $monthlyBudgetCap,
            ],
        ]);
    }

    public function saveSettings(Request $request, AdminConsoleService $console)
    {
        $this->visibleUserIds($request, $console);
        $this->assertGlobalCouponManagement($request);
        $data = $request->validate([
            'score_threshold' => ['required', 'integer', 'min:1', 'max:12'],
            'signal_window_days' => ['required', 'integer', 'min:1', 'max:60'],
            'cooldown_days' => ['required', 'integer', 'min:1', 'max:90'],
            'offer_valid_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'max_discount_percent' => ['required', 'integer', 'min:1', 'max:50'],
            'abandonment_hours' => ['required', 'integer', 'min:1', 'max:72'],
            'retention_days' => ['required', 'integer', 'min:7', 'max:180'],
            'monthly_budget_rials' => ['required', 'integer', 'min:0', 'max:999999999999999'],
        ]);
        if ((int) $data['retention_days'] < (int) $data['signal_window_days']) {
            return back()->withErrors([
                'retention_days' => 'مدت نگهداری سیگنال‌ها نمی‌تواند از پنجره تحلیل کوتاه‌تر باشد.',
            ])->withInput();
        }
        DB::transaction(function () use ($data): void {
            foreach ($data as $key => $value) {
                SiteSetting::write(match ($key) {
                    'score_threshold' => 'smart_discount_score_threshold',
                    'signal_window_days' => 'smart_discount_signal_window_days',
                    'cooldown_days' => 'smart_discount_cooldown_days',
                    'offer_valid_hours' => 'smart_discount_offer_valid_hours',
                    'max_discount_percent' => 'smart_discount_max_discount_percent',
                    'abandonment_hours' => 'smart_discount_abandonment_hours',
                    'retention_days' => 'marketing_signal_retention_days',
                    'monthly_budget_rials' => 'store_coupon_monthly_budget_rials',
                }, $value);
            }
        });
        app(AuditLogService::class)->record($request->user()->id, 'store.smart_coupon.settings_updated', $request->ip(), $data);

        return redirect()->route('admin.store-coupons.index')->with('status', 'قواعد موتور پیشنهاد هوشمند ذخیره شد.');
    }

    private function assertGlobalCouponManagement(Request $request): void
    {
        $scope = $request->attributes->get('farast.scope') ?? AdminConsoleService::scopeOf($request->user());
        abort_unless($scope === AdminConsoleService::SCOPE_GLOBAL, 403, 'مدیریت کدهای عمومی فقط در دامنه سراسری مجاز است.');
    }

    public function store(Request $request, AdminConsoleService $console)
    {
        $this->visibleUserIds($request, $console);
        $this->assertGlobalCouponManagement($request);
        $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('store_coupons', 'code')],
            'type' => ['required', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'min_subtotal_rials' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        if ($data['type'] === 'percent' && (int) $data['value'] > 100) {
            return back()->withErrors(['value' => 'درصد تخفیف نمی‌تواند بیشتر از ۱۰۰ باشد.'])->withInput();
        }

        $coupon = StoreCoupon::create($data + ['used_count' => 0, 'is_active' => true]);
        app(AuditLogService::class)->record($request->user()->id, 'store.coupon.created', $request->ip(), [
            'coupon_id' => $coupon->id, 'type' => $coupon->type, 'value' => $coupon->value,
        ]);

        return redirect()->route('admin.store-coupons.index')->with('status', 'کد تخفیف عمومی با موفقیت ایجاد شد.');
    }

    public function toggle(Request $request, int $coupon, AdminConsoleService $console)
    {
        $this->visibleUserIds($request, $console);
        $this->assertGlobalCouponManagement($request);
        $record = StoreCoupon::query()->whereKey($coupon)->firstOrFail();
        abort_if(StoreCouponAssignment::query()->where('coupon_id', $record->id)->exists(), 409, 'کدهای اختصاصی موتور هوشمند از این بخش قابل تغییر نیستند.');

        $record->update(['is_active' => ! $record->is_active]);
        app(AuditLogService::class)->record($request->user()->id, 'store.coupon.status_changed', $request->ip(), [
            'coupon_id' => $record->id, 'is_active' => $record->is_active,
        ]);

        return redirect()->route('admin.store-coupons.index')->with('status', $record->is_active ? 'کد تخفیف فعال شد.' : 'کد تخفیف غیرفعال شد.');
    }

    public function revokeAssignment(Request $request, int $assignment, AdminConsoleService $console)
    {
        $visibleIds = $this->visibleUserIds($request, $console);
        DB::transaction(function () use ($assignment, $visibleIds, $request) {
            $record = StoreCouponAssignment::query()->whereKey($assignment)->lockForUpdate()->firstOrFail();
            abort_if($visibleIds !== null && ! in_array((int) $record->user_id, $visibleIds, true), 404);
            abort_unless($record->status === 'offered', 409, 'فقط پیشنهادهای استفاده‌نشده قابل ابطال هستند.');
            $record->update(['status' => 'revoked']);
            StoreCoupon::query()->whereKey($record->coupon_id)->update(['is_active' => false, 'updated_at' => now()]);
        });

        app(AuditLogService::class)->record($request->user()->id, 'store.smart_coupon.revoked', $request->ip(), [
            'assignment_id' => $assignment,
        ]);

        return redirect()->route('admin.store-coupons.index')->with('status', 'پیشنهاد اختصاصی باطل شد و کد آن دیگر قابل استفاده نیست.');
    }
}
