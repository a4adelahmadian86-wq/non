<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CrmAudienceService
{
    public function query(array $filters, ?array $visibleUserIds = null): Builder
    {
        $query = User::query()->where('is_blocked', false);

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
            $query->whereHas('orders', function ($orders) use ($filters) {
                $orders->select(DB::raw('1'))
                    ->groupBy('user_id')
                    ->havingRaw('COUNT(*) >= ?', [(int) $filters['min_orders']]);
            });
        }

        if (($filters['paid_customer'] ?? false) === true) {
            $query->whereHas('orders', fn ($orders) => $orders->where('status', 'paid'));
        }

        return $query->orderBy('users.id');
    }
}
