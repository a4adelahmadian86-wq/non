<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CrmAudienceService
{
    public function query(array $filters, ?array $visibleUserIds = null): Builder
    {
        $query = User::query()->where('is_blocked', false)->where('marketing_consent', true);

        if ($visibleUserIds !== null) {
            $query->whereIn('users.id', $visibleUserIds);
        }

        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (($filters['verification'] ?? 'any') === 'verified') {
            $query->where('is_verified', true);
        } elseif (($filters['verification'] ?? 'any') === 'unverified') {
            $query->where('is_verified', false);
        }

        if (! empty($filters['registered_after'])) {
            $query->whereDate('created_at', '>=', $filters['registered_after']);
        }
        if (! empty($filters['registered_before'])) {
            $query->whereDate('created_at', '<=', $filters['registered_before']);
        }

        if (array_key_exists('min_orders', $filters) && $filters['min_orders'] !== null) {
            $query->whereHas('orders', null, '>=', (int) $filters['min_orders']);
        }

        if (($filters['paid_customer'] ?? false) === true) {
            $query->whereHas('orders', fn ($orders) => $orders->where('status', 'paid'));
        }

        $signalEvent = $filters['signal_event'] ?? null;
        $supportedSignals = ['product_viewed', 'wishlist_added', 'cart_added', 'cart_abandoned', 'coupon_attempted'];
        if (in_array($signalEvent, $supportedSignals, true)) {
            $signalWindowDays = max(1, min(90, (int) ($filters['signal_window_days'] ?? 14)));
            $signalProductId = (int) ($filters['signal_product_id'] ?? 0);
            $query->whereExists(function ($signals) use ($signalEvent, $signalWindowDays, $signalProductId) {
                $signals->select(DB::raw('1'))
                    ->from('farast_workflow_events')
                    ->whereColumn('farast_workflow_events.user_id', 'users.id')
                    ->where('farast_workflow_events.event_type', 'marketing.'.$signalEvent)
                    ->where('farast_workflow_events.created_at', '>=', now()->subDays($signalWindowDays))
                    ->when($signalProductId > 0, fn ($events) => $events->whereJsonContains('farast_workflow_events.context->product_id', $signalProductId));
            });
        }

        return $query->orderBy('users.id');
    }
}
