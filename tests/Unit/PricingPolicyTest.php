<?php
namespace Tests\Unit;

use App\Models\FarastPricingPolicy;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_calculation_supports_allowance_additional_usage_discount_and_fee(): void
    {
        FarastPricingPolicy::where('code','typing_manual_page')->update([
            'base_price_rials'=>100000,
            'subscription_allowance'=>2,
            'additional_price_rials'=>120000,
            'payg_multiplier_percent'=>100,
            'discount_percent'=>10,
            'fee_rials'=>5000,
            'active'=>true,
        ]);

        $result = app(PricingService::class)->calculate('typing_manual_page', 4, true);

        $this->assertSame(2, $result['included_units']);
        $this->assertSame(2, $result['billable_units']);
        $this->assertSame(216000, $result['additional_usage_rials']);
        $this->assertSame(5000, $result['fee_rials']);
        $this->assertSame(221000, $result['final_price_rials']);
    }
}
