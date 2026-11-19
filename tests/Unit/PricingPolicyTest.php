<?php
namespace Tests\Unit;

use App\Models\FarastPricingPolicy;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_uses_versioned_engine_and_preserves_legacy_response_contract(): void
    {
        $version = FarastPricingPolicyVersion::create([
            'policy_code' => 'typing_manual_page',
            'version' => 1,
            'capability_code' => 'typing.manual',
            'unit' => 'page',
            'currency' => 'IRR',
            'unit_price' => 100000,
            'additional_unit_price' => 120000,
            'fee_amount' => 5000,
            'discount_basis_points' => 1000,
            'tax_basis_points' => 0,
            'payg_multiplier_basis_points' => 10000,
            'included_quantity' => 2,
            'effective_from' => now()->subMinute(),
            'checksum' => hash('sha256', 'typing_manual_page|1'),
        ]);

        $result = app(PricingService::class)->calculate('typing_manual_page', 4, true);

        $this->assertSame(2, $result['included_units']);
        $this->assertSame(2, $result['billable_units']);
        $this->assertSame(240000, $result['additional_usage_rials']);
        $this->assertSame(24000, $result['discount_rials']);
        $this->assertSame(5000, $result['fee_rials']);
        $this->assertSame(221000, $result['final_price_rials']);
        $this->assertSame($version->id, $result['pricing_policy_version_id']);
        $this->assertSame('IRR', $result['currency']);
    }

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
        $this->assertSame(240000, $result['additional_usage_rials']);
        $this->assertSame(5000, $result['fee_rials']);
        $this->assertSame(221000, $result['final_price_rials']);
    }
}
