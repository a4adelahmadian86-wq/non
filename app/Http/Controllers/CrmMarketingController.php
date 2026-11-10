<?php

namespace App\Http\Controllers;

use App\Services\AdminConsoleService;
use App\Services\AuditLogService;
use App\Services\CrmAudienceService;
use App\Models\Order;
use App\Models\StoreCoupon;
use App\Models\StoreCouponAssignment;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\StoreCouponMarginPolicy;
use App\Services\StoreCouponBudgetPolicy;
use App\Services\SmartDiscountMarketingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CrmMarketingController extends Controller
{
    public function dashboard(Request $request, AdminConsoleService $console)
    {
        $console->authorizeAction($request->user(), 'marketing');

        return view('admin.crm.marketing', [
            'products' => StoreProduct::query()->published()
                ->orderBy('title')
                ->get(['id', 'title', 'price_rials', 'cost_price_rials']),
        ]);
    }

    private function scope(Request $request, AdminConsoleService $console): ?array
    {
        $console->authorizeAction($request->user(), 'marketing');

        return $console->visibleUserIds($request->user());
    }

    private function ownerScope(Request $request, AdminConsoleService $console): ?array
    {
        $visibleIds = $this->scope($request, $console);

        if ($visibleIds === null) {
            return null;
        }

        // A scoped admin may not appear in visibleUserIds (organization members
        // intentionally exclude admin accounts), but must retain access to records
        // they created themselves.
        return array_values(array_unique(array_merge($visibleIds, [(int) $request->user()->id])));
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'role' => 'nullable|in:admin,organization_manager,team_supervisor,employee,observer,guest',
            'verification' => 'nullable|in:any,verified,unverified',
            'registered_after' => 'nullable|date',
            'registered_before' => 'nullable|date|after_or_equal:registered_after',
            'min_orders' => 'nullable|integer|min:1|max:1000',
            'paid_customer' => 'sometimes|boolean',
            'signal_event' => 'nullable|in:product_viewed,wishlist_added,cart_added,cart_abandoned,coupon_attempted',
            'signal_product_id' => 'nullable|integer|exists:store_products,id',
            'signal_window_days' => 'nullable|integer|min:1|max:90',
        ]);
    }

    public function segments(Request $request, AdminConsoleService $console, CrmAudienceService $audiences)
    {
        $ownerIds = $this->ownerScope($request, $console);
        $visibleIds = $console->visibleUserIds($request->user());
        $query = DB::table('crm_customer_segments');
        if ($ownerIds !== null) {
            $query->whereIn('created_by', $ownerIds);
        }

        $segments = $query->orderByDesc('created_at')->paginate(25)->through(function ($segment) use ($audiences, $visibleIds) {
            $filters = json_decode((string) $segment->filters, true) ?: [];
            $segment->audience_count = $audiences->query($filters, $visibleIds)->count();
            return $segment;
        });

        return response()->json(['data' => $segments]);
    }

    public function storeSegment(Request $request, AdminConsoleService $console)
    {
        $visibleIds = $this->scope($request, $console);
        $data = $request->validate([
            'name' => 'required|string|max:140',
            'description' => 'nullable|string|max:1000',
            'filters' => 'present|array',
        ]);
        $filters = validator($data['filters'], [
            'role' => 'nullable|in:admin,organization_manager,team_supervisor,employee,observer,guest',
            'verification' => 'nullable|in:any,verified,unverified',
            'registered_after' => 'nullable|date',
            'registered_before' => 'nullable|date|after_or_equal:registered_after',
            'min_orders' => 'nullable|integer|min:1|max:1000',
            'paid_customer' => 'sometimes|boolean',
            'signal_event' => 'nullable|in:product_viewed,wishlist_added,cart_added,cart_abandoned,coupon_attempted',
            'signal_product_id' => 'nullable|integer|exists:store_products,id',
            'signal_window_days' => 'nullable|integer|min:1|max:90',
        ])->validate();
        abort_unless(empty($filters['signal_product_id']) || ! empty($filters['signal_event']), 422, 'برای فیلتر محصول باید نوع سیگنال رفتاری نیز مشخص شود.');

        $id = DB::table('crm_customer_segments')->insertGetId([
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'filters' => json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = app(CrmAudienceService::class)->query($filters, $visibleIds)->count();
        app(AuditLogService::class)->record($request->user()->id, 'crm.segment.created', $request->ip(), [
            'segment_id' => $id, 'audience_count' => $count,
        ]);

        return response()->json(['ok' => true, 'id' => $id, 'audience_count' => $count], 201);
    }

    public function previewAudience(Request $request, AdminConsoleService $console, CrmAudienceService $audiences)
    {
        $visibleIds = $this->scope($request, $console);
        $filters = $this->filters($request);
        abort_unless(empty($filters['signal_product_id']) || ! empty($filters['signal_event']), 422, 'برای فیلتر محصول باید نوع سیگنال رفتاری نیز مشخص شود.');

        return response()->json([
            'audience_count' => $audiences->query($filters, $visibleIds)->count(),
            'filters' => $filters,
        ]);
    }

    public function campaigns(Request $request, AdminConsoleService $console)
    {
        $ownerIds = $this->ownerScope($request, $console);
        $query = DB::table('crm_marketing_campaigns')
            ->leftJoin('crm_customer_segments', 'crm_customer_segments.id', '=', 'crm_marketing_campaigns.segment_id');
        if ($ownerIds !== null) {
            $query->whereIn('crm_marketing_campaigns.created_by', $ownerIds);
        }

        $page = $query->orderByDesc('crm_marketing_campaigns.created_at')
            ->paginate(25, [
                'crm_marketing_campaigns.*',
                'crm_customer_segments.name as segment_name',
            ]);
        $campaignIds = $page->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->values();

        if ($campaignIds->isEmpty()) {
            return response()->json(['data' => $page]);
        }

        // Batch campaign metrics for the current page. Avoid a query per campaign
        // and scan the retained interaction ledger only once per dashboard request.
        $assignments = DB::table('store_coupon_assignments')
            ->whereIn('campaign_id', $campaignIds)
            ->get(['id', 'campaign_id', 'status']);
        $assignmentCampaignMap = $assignments->mapWithKeys(
            fn ($assignment) => [(int) $assignment->id => (int) $assignment->campaign_id]
        );

        $redeemedStats = DB::table('store_coupon_assignments as assignments')
            ->join('orders', 'orders.id', '=', 'assignments.redeemed_order_id')
            ->join('store_coupons', 'store_coupons.id', '=', 'assignments.coupon_id')
            ->whereIn('assignments.campaign_id', $campaignIds)
            ->where('assignments.status', 'redeemed')
            ->where('orders.status', 'paid')
            ->whereNotNull('orders.paid_at')
            ->groupBy('assignments.campaign_id')
            ->selectRaw('assignments.campaign_id, COUNT(*) as redeemed_offer_count, SUM(store_coupons.value) as redeemed_discount_rials')
            ->get()
            ->keyBy(fn ($row) => (int) $row->campaign_id);

        $reservedStats = DB::table('store_coupon_assignments as assignments')
            ->join('store_coupons', 'store_coupons.id', '=', 'assignments.coupon_id')
            ->whereIn('assignments.campaign_id', $campaignIds)
            ->where('assignments.status', 'offered')
            ->where('assignments.expires_at', '>', now())
            ->where('store_coupons.is_active', true)
            ->where(function ($query) {
                $query->whereNull('store_coupons.ends_at')->orWhere('store_coupons.ends_at', '>', now());
            })
            ->groupBy('assignments.campaign_id')
            ->selectRaw('assignments.campaign_id, SUM(store_coupons.value) as reserved_discount_rials')
            ->get()
            ->keyBy(fn ($row) => (int) $row->campaign_id);

        $revenueStats = DB::table('store_coupon_assignments as assignments')
            ->join('orders', 'orders.id', '=', 'assignments.redeemed_order_id')
            ->join('store_order_items', function ($join) {
                $join->on('store_order_items.order_id', '=', 'orders.id')
                    ->on('store_order_items.product_id', '=', 'assignments.product_id');
            })
            ->whereIn('assignments.campaign_id', $campaignIds)
            ->where('assignments.status', 'redeemed')
            ->where('orders.status', 'paid')
            ->whereNotNull('orders.paid_at')
            ->groupBy('assignments.campaign_id')
            ->selectRaw('assignments.campaign_id, SUM(store_order_items.total_rials) as attributed_revenue_rials')
            ->get()
            ->keyBy(fn ($row) => (int) $row->campaign_id);

        $viewedByCampaign = [];
        $clickedByCampaign = [];
        if ($assignmentCampaignMap->isNotEmpty()) {
            $events = DB::table('farast_workflow_events')
                ->whereIn('event_type', ['marketing.offer_viewed', 'marketing.offer_clicked'])
                ->get(['event_type', 'context']);
            foreach ($events as $event) {
                $context = is_array($event->context)
                    ? $event->context
                    : json_decode((string) $event->context, true);
                $assignmentId = (int) (is_array($context) ? ($context['assignment_id'] ?? 0) : 0);
                $campaignId = $assignmentCampaignMap->get($assignmentId);
                if (! $campaignId) {
                    continue;
                }

                if ($event->event_type === 'marketing.offer_viewed') {
                    $viewedByCampaign[$campaignId][$assignmentId] = true;
                } else {
                    $clickedByCampaign[$campaignId][$assignmentId] = true;
                }
            }
        }

        $page->getCollection()->transform(function ($campaign) use (
            $assignments,
            $redeemedStats,
            $reservedStats,
            $revenueStats,
            $viewedByCampaign,
            $clickedByCampaign
        ) {
            $campaignId = (int) $campaign->id;
            $campaignAssignments = $assignments->where('campaign_id', $campaignId);
            $redeemed = $redeemedStats->get($campaignId);
            $reserved = $reservedStats->get($campaignId);
            $revenue = $revenueStats->get($campaignId);
            $viewed = $viewedByCampaign[$campaignId] ?? [];
            $clicked = array_intersect_key($clickedByCampaign[$campaignId] ?? [], $viewed);

            $campaign->offer_count = $campaignAssignments->count();
            $campaign->redeemed_offer_count = (int) ($redeemed->redeemed_offer_count ?? 0);
            $campaign->redeemed_discount_rials = (int) ($redeemed->redeemed_discount_rials ?? 0);
            $campaign->reserved_discount_rials = (int) ($reserved->reserved_discount_rials ?? 0);
            $campaign->attributed_revenue_rials = (int) ($revenue->attributed_revenue_rials ?? 0);
            $campaign->budget_remaining_rials = (int) ($campaign->budget_cap_rials ?? 0) > 0
                ? max(0, (int) $campaign->budget_cap_rials - $campaign->redeemed_discount_rials - $campaign->reserved_discount_rials)
                : null;

            $campaign->offer_view_count = count($viewed);
            $campaign->offer_click_count = count($clicked);
            $viewedRedeemed = $campaignAssignments
                ->filter(fn ($assignment) => $assignment->status === 'redeemed' && isset($viewed[(int) $assignment->id]))
                ->count();
            $campaign->viewed_redeemed_offer_count = $viewedRedeemed;
            $campaign->view_to_click_rate = $campaign->offer_view_count > 0
                ? round($campaign->offer_click_count * 100 / $campaign->offer_view_count, 1) : 0;
            $campaign->view_to_redemption_rate = $campaign->offer_view_count > 0
                ? round($viewedRedeemed * 100 / $campaign->offer_view_count, 1) : 0;

            return $campaign;
        });

        return response()->json(['data' => $page]);
    }

    public function storeCampaign(Request $request, AdminConsoleService $console, CrmAudienceService $audiences)
    {
        $visibleIds = $this->scope($request, $console);
        $ownerIds = $this->ownerScope($request, $console);
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'segment_id' => 'required|integer|exists:crm_customer_segments,id',
            'title' => 'required|string|max:180',
            'body' => 'required|string|min:2|max:5000',
            'incentive_product_id' => 'nullable|integer|exists:store_products,id',
            'incentive_discount_percent' => 'nullable|integer|min:1|max:50',
            'incentive_valid_hours' => 'nullable|integer|min:1|max:168',
            'budget_cap_rials' => 'nullable|integer|min:1|max:999999999999999',
        ]);
        $incentiveProduct = null;
        if (! empty($data['incentive_product_id'])) {
            $incentiveProduct = StoreProduct::query()->published()->findOrFail($data['incentive_product_id']);
            abort_if($incentiveProduct->cost_price_rials === null, 422, 'برای محصول هدف باید بهای تمام‌شده ثبت شده باشد.');
            abort_unless(! empty($data['incentive_discount_percent']) && ! empty($data['budget_cap_rials']), 422, 'برای مشوق کمپین، درصد تخفیف و سقف بودجه الزامی است.');
        } else {
            abort_if(! empty($data['incentive_discount_percent']) || ! empty($data['budget_cap_rials']), 422, 'تنظیمات مشوق بدون انتخاب محصول هدف مجاز نیست.');
        }

        $segment = DB::table('crm_customer_segments')->where('id', $data['segment_id'])->first();
        abort_unless($segment && ($ownerIds === null || in_array((int) $segment->created_by, $ownerIds, true)), 404);
        $filters = json_decode($segment->filters, true) ?: [];
        $count = $audiences->query($filters, $visibleIds)->count();

        $id = DB::table('crm_marketing_campaigns')->insertGetId([
            'name' => trim($data['name']),
            'channel' => 'in_app',
            'status' => 'draft',
            'segment_id' => $segment->id,
            'incentive_product_id' => $incentiveProduct?->id,
            'incentive_discount_percent' => $data['incentive_discount_percent'] ?? null,
            'incentive_valid_hours' => $data['incentive_valid_hours'] ?? 48,
            'budget_cap_rials' => $data['budget_cap_rials'] ?? 0,
            'title' => trim($data['title']),
            'body' => trim($data['body']),
            'created_by' => $request->user()->id,
            'recipient_count' => $count,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(AuditLogService::class)->record($request->user()->id, 'crm.campaign.created', $request->ip(), [
            'campaign_id' => $id, 'audience_count' => $count,
        ]);

        return response()->json(['ok' => true, 'id' => $id, 'status' => 'draft', 'audience_count' => $count], 201);
    }

    public function sendCampaign(Request $request, int $campaign, AdminConsoleService $console, CrmAudienceService $audiences, NotificationService $notifications)
    {
        $visibleIds = $this->scope($request, $console);
        $ownerIds = $this->ownerScope($request, $console);

        $sentCount = DB::transaction(function () use ($campaign, $request, $visibleIds, $ownerIds, $audiences, $notifications) {
            $record = DB::table('crm_marketing_campaigns')->where('id', $campaign)->lockForUpdate()->first();
            abort_unless($record && ($ownerIds === null || in_array((int) $record->created_by, $ownerIds, true)), 404);
            abort_unless($record->status === 'draft', 409, 'این کمپین قبلاً ارسال یا پردازش شده است.');

            $segment = DB::table('crm_customer_segments')->where('id', $record->segment_id)->first();
            abort_unless($segment && ($ownerIds === null || in_array((int) $segment->created_by, $ownerIds, true)), 409);
            $filters = json_decode($segment->filters, true) ?: [];
            $audience = $audiences->query($filters, $visibleIds);
            $count = (clone $audience)->count();
            abort_if($count > 2000, 422, 'برای ارسال بیش از ۲۰۰۰ مخاطب، کمپین را به چند بخش کوچک‌تر تقسیم کنید.');

            $incentiveProduct = null;
            $budgetCommitted = 0;
            if (empty($record->incentive_product_id) && $record->incentive_discount_percent !== null) {
                abort(409, 'محصول هدف کمپین حذف شده یا دیگر در دسترس نیست.');
            }
            if (! empty($record->incentive_product_id)) {
                $incentiveProduct = StoreProduct::query()->published()
                    ->whereKey($record->incentive_product_id)->lockForUpdate()->first();
                abort_unless($incentiveProduct && $incentiveProduct->cost_price_rials !== null, 409, 'محصول هدف کمپین دیگر شرایط ارائه تخفیف امن را ندارد.');
                abort_unless((int) $record->budget_cap_rials > 0 && (int) $record->incentive_discount_percent > 0, 409, 'بودجه یا درصد مشوق کمپین معتبر نیست.');

                $spent = DB::table('store_coupon_assignments as assignments')
                    ->join('orders', 'orders.id', '=', 'assignments.redeemed_order_id')
                    ->join('store_coupons', 'store_coupons.id', '=', 'assignments.coupon_id')
                    ->where('assignments.campaign_id', $campaign)
                    ->where('assignments.status', 'redeemed')
                    ->where('orders.status', 'paid')
                    ->whereNotNull('orders.paid_at')
                    ->sum('store_coupons.value');
                $reserved = DB::table('store_coupon_assignments as assignments')
                    ->join('store_coupons', 'store_coupons.id', '=', 'assignments.coupon_id')
                    ->where('assignments.campaign_id', $campaign)
                    ->where('assignments.status', 'offered')
                    ->where('assignments.expires_at', '>', now())
                    ->sum('store_coupons.value');
                $budgetCommitted = (int) $spent + (int) $reserved;
            }

            DB::table('crm_marketing_campaigns')->where('id', $campaign)->update([
                'status' => 'sending', 'recipient_count' => $count, 'updated_at' => now(),
            ]);

            $sent = 0;
            $audience->chunkById(200, function ($users) use ($record, $campaign, &$sent, &$budgetCommitted, $incentiveProduct, $notifications) {
                foreach ($users as $user) {
                    // Consent is rechecked under a user-row lock at delivery time,
                    // not merely trusted from the earlier audience preview.
                    $recipient = User::query()->whereKey($user->id)->lockForUpdate()->first();
                    if (! $recipient || ! $recipient->marketing_consent || $recipient->is_blocked) {
                        continue;
                    }

                    $recipientId = DB::table('crm_campaign_recipients')->insertOrIgnore([
                        'campaign_id' => $campaign,
                        'user_id' => $user->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($recipientId === 0) {
                        continue;
                    }

                    $coupon = null;
                    $assignmentId = null;
                    if ($incentiveProduct) {
                        $alreadyPurchased = Order::query()->where('user_id', $user->id)->where('status', 'paid')
                            ->whereHas('storeItems', fn ($items) => $items->where('product_id', $incentiveProduct->id))->exists();
                        $overlappingOffer = StoreCouponAssignment::query()
                            ->where('user_id', $user->id)
                            ->where('product_id', $incentiveProduct->id)
                            ->where('status', 'offered')
                            ->where('expires_at', '>', now())
                            ->exists();
                        $recentOffer = StoreCouponAssignment::query()
                            ->where('user_id', $user->id)
                            ->where('product_id', $incentiveProduct->id)
                            ->where('offered_at', '>=', now()->subDays(7))
                            ->exists();
                        $effectivePercent = app(SmartDiscountMarketingService::class)
                            ->effectiveCampaignDiscountPercent($incentiveProduct->id, (int) $record->incentive_discount_percent);
                        $discount = min(
                            (int) floor((int) $incentiveProduct->price_rials * $effectivePercent / 100),
                            app(StoreCouponMarginPolicy::class)->maximumDiscount($incentiveProduct, (int) $incentiveProduct->price_rials, 1)
                        );

                        $monthlyBudgetAllows = $discount > 0
                            && app(StoreCouponBudgetPolicy::class)->allows($discount);

                        if (! $alreadyPurchased && ! $overlappingOffer && ! $recentOffer && $discount > 0
                            && $monthlyBudgetAllows
                            && $budgetCommitted + $discount <= (int) $record->budget_cap_rials) {
                            do {
                                $code = 'FARAST'.Str::upper(Str::random(12));
                            } while (StoreCoupon::query()->where('code', $code)->exists());

                            $expiresAt = now()->addHours(max(1, min(168, (int) $record->incentive_valid_hours)));
                            $coupon = StoreCoupon::create([
                                'code' => $code,
                                'type' => 'fixed',
                                'value' => $discount,
                                'max_uses' => 1,
                                'used_count' => 0,
                                'is_active' => true,
                                'starts_at' => now(),
                                'ends_at' => $expiresAt,
                                'description' => 'مشوق کمپین '.$record->name,
                            ]);
                            $assignmentId = DB::table('store_coupon_assignments')->insertGetId([
                                'coupon_id' => $coupon->id,
                                'campaign_id' => $campaign,
                                'user_id' => $user->id,
                                'product_id' => $incentiveProduct->id,
                                'status' => 'offered',
                                'score' => 0,
                                'reason_codes' => json_encode(['campaign_'.$campaign], JSON_UNESCAPED_UNICODE),
                                'offered_at' => now(),
                                'expires_at' => $expiresAt,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $budgetCommitted += $discount;
                        }
                    }

                    $message = $record->body;
                    $notificationData = [
                        'campaign_id' => $campaign,
                        'url' => route('platform.notifications', ['filter' => 'marketing']),
                    ];
                    if ($coupon && $assignmentId) {
                        $message .= "\n\nکد تخفیف اختصاصی شما: ".$coupon->code."\nمبلغ تخفیف: ".number_format($coupon->value)." ریال\nمهلت استفاده: ".$coupon->ends_at->format('Y-m-d H:i');
                        $notificationData['coupon_code'] = $coupon->code;
                        $notificationData['coupon_assignment_id'] = $assignmentId;
                        $notificationData['product_id'] = $incentiveProduct->id;
                        $notificationData['expires_at'] = $coupon->ends_at->toIso8601String();
                        $notificationData['url'] = route('store.product', $incentiveProduct->slug);
                    }

                    $notification = $notifications->push(
                        (int) $user->id,
                        'announcement_campaign',
                        $record->title,
                        $message,
                        $notificationData,
                        true,
                    );
                    if (! $notification) {
                        throw new \RuntimeException('ثبت اعلان کمپین بازاریابی انجام نشد.');
                    }

                    if ($assignmentId) {
                        DB::table('store_coupon_assignments')->where('id', $assignmentId)->update([
                            'notification_id' => $notification->id,
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('crm_campaign_recipients')->where('campaign_id', $campaign)
                        ->where('user_id', $user->id)->update([
                            'notification_id' => $notification->id,
                            'notified_at' => now(),
                            'updated_at' => now(),
                        ]);
                    $sent++;
                }
            }, 'users.id', 'id');

            DB::table('crm_marketing_campaigns')->where('id', $campaign)->update([
                'status' => 'sent', 'recipient_count' => $sent, 'sent_at' => now(), 'updated_at' => now(),
            ]);

            return $sent;
        });

        app(AuditLogService::class)->record($request->user()->id, 'crm.campaign.sent', $request->ip(), [
            'campaign_id' => $campaign, 'recipient_count' => $sentCount,
        ]);

        return response()->json(['ok' => true, 'status' => 'sent', 'recipient_count' => $sentCount]);
    }
}
