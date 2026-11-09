<?php

namespace App\Console\Commands;

use App\Services\StoreCouponRedemptionService;
use Illuminate\Console\Command;

class ReleaseExpiredStoreCouponReservations extends Command
{
    protected $signature = 'farast:release-expired-store-coupon-reservations {--limit=100}';
    protected $description = 'Release expired store coupon reservations and expire their unpaid orders.';

    public function handle(StoreCouponRedemptionService $redemptions): int
    {
        $released = $redemptions->releaseExpired((int) $this->option('limit'));
        $this->info("Released {$released} expired store coupon reservation(s).");

        return self::SUCCESS;
    }
}
