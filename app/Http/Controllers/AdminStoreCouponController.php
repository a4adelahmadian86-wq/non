<?php

namespace App\Http\Controllers;

use App\Models\StoreCoupon;
use App\Models\StoreCouponAssignment;
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
        $attributedOrders = DB::table('store_coupon_assignments')
            ->join('orders', 'orders.id', '=', 'store_coupon_assignments.redeemed_order_id')
            ->when($visibleIds !== null, fn ($query) => $query->whereIn('store_coupon_assignments.user_id', $visibleIds))
            ->where('store_coupon_assignments.status', 'redeemed')
            ->where('orders.status', 'paid');
        $attributedOrderCount = (clone $attributedOrders)->count();
        $attributedRevenue = (int) (clone $attributedOrders)->sum('orders.total_rials');
        $redeemedDiscountCost = (int) (clone $attributedOrders)->sum('orders.discount_rials');

        $genericCoupons = StoreCoupon::query()
            ->whereNotIn('id', StoreCouponAssignment::query()->select('coupon_id'))
            ->latest('id')
            ->limit(20)
            ->get();

        return view('admin.store-coupons.index', [
            'assignments' => $assignments->latest('offered_at')->paginate(25)->withQueryString(),
            'genericCoupons' => $genericCoupons,
            'filters' => ['status' => $status, 'q' => $request->query('q')],
            'stats' => [
                'total' => $totalOffers,
                'redeemed' => $redeemed,
                'offered' => $offered,
                'expired' => $expired,
                'revoked' => $revoked,
                'conversion_rate' => $totalOffers > 0 ? round($redeemed * 100 / $totalOffers, 1) : 0,
                'discount_cost_rials' => $redeemedDiscountCost,\n                'attributed_revenue_rials' => $attributedRevenue,\n                'attributed_orders' => $attributedOrderCount,
            ],
        ]);
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
