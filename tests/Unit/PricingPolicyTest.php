<?php
namespace Tests\Unit;

use App\Models\FarastPricingPolicy;
use App\Models\FarastPricingPolicyVersion;
use App\Models\PricingRule;
use App\Services\PricingService;
use App\Services\PricingPolicyAdminService;
use App\Services\PricingEngine;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\VersionedPricingPolicySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_uses_versioned_engine_and_preserves_legacy_response_contract(): void
    {
        FarastPricingPolicy::create([
            'code' => 'typing.manual.versioned.test',
            'capability_code' => 'typing.manual',
            'unit' => 'page',
            'base_price_rials' => 1,
            'subscription_allowance' => 0,
            'additional_price_rials' => 1,
            'payg_multiplier_percent' => 100,
            'discount_percent' => 0,
            'fee_rials' => 0,
            'active' => true,
        ]);

        $version = FarastPricingPolicyVersion::create([
            'policy_code' => 'typing.manual.versioned.test',
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
            'checksum' => hash('sha256', 'typing.manual.versioned.test|1'),
        ]);

        $result = app(PricingService::class)->calculate('typing.manual.versioned.test', 4, true);

        $this->assertSame(2, $result['included_units']);
        $this->assertSame(2, $result['billable_units']);
        $this->assertSame(240000, $result['additional_usage_rials']);
        $this->assertSame(24000, $result['discount_rials']);
        $this->assertSame(5000, $result['fee_rials']);
        $this->assertSame(221000, $result['final_price_rials']);
        $this->assertSame($version->id, $result['pricing_policy_version_id']);
        $this->assertSame('IRR', $result['currency']);

        $payg = app(PricingService::class)->calculate('typing.manual.versioned.test', 4, false);
        $this->assertSame(0, $payg['included_units']);
        $this->assertSame(4, $payg['billable_units']);
        $this->assertSame(480000, $payg['additional_usage_rials']);
        $this->assertSame(437000, $payg['final_price_rials']);
    }

    public function test_typing_price_reads_versioned_policy_before_legacy_rule_values(): void
    {
        PricingRule::updateOrCreate(['key' => 'page_base'], [
            'value' => 77777,
            'label' => 'legacy estimate test value',
            'active' => true,
        ]);
        PricingRule::updateOrCreate(['key' => 'typing_manual_page'], [
            'value' => 999999,
            'label' => 'legacy test value',
            'active' => true,
        ]);
        PricingRule::updateOrCreate(['key' => 'typing_payg_multiplier'], [
            'value' => 200,
            'label' => 'legacy test multiplier',
            'active' => true,
        ]);

        FarastPricingPolicyVersion::create([
            'policy_code' => 'page_base',
            'version' => ((int) FarastPricingPolicyVersion::where('policy_code', 'page_base')->max('version')) + 1,
            'capability_code' => 'typing.manual',
            'unit' => 'page',
            'currency' => 'IRR',
            'unit_price' => 11111,
            'additional_unit_price' => null,
            'fee_amount' => 0,
            'discount_basis_points' => 0,
            'tax_basis_points' => 0,
            'payg_multiplier_basis_points' => 10000,
            'included_quantity' => 0,
            'effective_from' => now()->subMinute(),
            'checksum' => hash('sha256', 'page_base|1'),
        ]);
        FarastPricingPolicyVersion::create([
            'policy_code' => 'typing_manual_page',
            'version' => ((int) FarastPricingPolicyVersion::where('policy_code', 'typing_manual_page')->max('version')) + 1,
            'capability_code' => 'typing.manual',
            'unit' => 'page',
            'currency' => 'IRR',
            'unit_price' => 12345,
            'additional_unit_price' => null,
            'fee_amount' => 0,
            'discount_basis_points' => 0,
            'tax_basis_points' => 0,
            'payg_multiplier_basis_points' => 10000,
            'included_quantity' => 0,
            'effective_from' => now()->subMinute(),
            'checksum' => hash('sha256', 'typing_manual_page|1'),
        ]);
        FarastPricingPolicyVersion::create([
            'policy_code' => 'typing_payg_multiplier',
            'version' => ((int) FarastPricingPolicyVersion::where('policy_code', 'typing_payg_multiplier')->max('version')) + 1,
            'capability_code' => 'typing.payg',
            'unit' => 'percentage',
            'currency' => 'IRR',
            'unit_price' => 0,
            'additional_unit_price' => null,
            'fee_amount' => 0,
            'discount_basis_points' => 0,
            'tax_basis_points' => 0,
            'payg_multiplier_basis_points' => 12500,
            'included_quantity' => 0,
            'effective_from' => now()->subMinute(),
            'checksum' => hash('sha256', 'typing_payg_multiplier|1'),
        ]);

        $pricing = app(PricingService::class);
        $this->assertSame(12345, $pricing->initialTypingPrices()['manual']);
        $this->assertSame(22222, $pricing->estimateByPages(2)['price_rials']);
        $this->assertSame('page_base', FarastPricingPolicyVersion::find($pricing->estimateByPages(2)['pricing_policy_version_id'])->policy_code);
        $withFreePage = $pricing->estimateByPages(2, 1);
        $this->assertSame(22222, $withFreePage['price_rials']);
        $this->assertSame(11111, $withFreePage['discount_rials']);
        $this->assertSame(11111, $withFreePage['payable_price_rials']);

        $subscription = $pricing->projectQuote('manual', '', 2, '', false);
        $this->assertSame(12345, $subscription['unit_price_rials']);
        $this->assertSame(24690, $subscription['price_rials']);

        $payg = $pricing->projectQuote('manual', '', 2, '', true);
        $this->assertSame(30863, $payg['price_rials']);
        $this->assertNotNull($payg['pricing_policy_version_id']);
    }

    public function test_document_quote_uses_versioned_base_and_language_multiplier(): void
    {
        foreach ([
            ['page_base', 10000],
            ['english_multiplier', 150],
            ['formula_unit', 5000],
        ] as [$key, $value]) {
            PricingRule::updateOrCreate(['key' => $key], [
                'value' => $value + 1,
                'label' => 'legacy '.$key,
                'active' => true,
            ]);

            FarastPricingPolicyVersion::create([
                'policy_code' => $key,
                'version' => ((int) FarastPricingPolicyVersion::where('policy_code', $key)->max('version')) + 1,
                'capability_code' => 'typing.manual',
                'unit' => $key === 'page_base' ? 'page' : 'percentage',
                'currency' => 'IRR',
                'unit_price' => $value,
                'additional_unit_price' => null,
                'fee_amount' => 0,
                'discount_basis_points' => 0,
                'tax_basis_points' => 0,
                'payg_multiplier_basis_points' => 10000,
                'included_quantity' => 0,
                'effective_from' => now()->subMinute(),
                'checksum' => hash('sha256', $key.'|1'),
            ]);
        }

        $quote = app(PricingService::class)->quote('alpha beta gamma', 1);

        $this->assertSame(15000, $quote['price_rials']);
        $this->assertNotNull($quote['pricing_policy_version_id']);
        $this->assertSame(15000, $quote['free_page_value_rials']);
        $this->assertSame(1.5, $quote['breakdown']['language_factor']);

        $formulaQuote = app(PricingService::class)->quote('x = y', 1);
        $this->assertSame(20000, $formulaQuote['price_rials']);
        $this->assertSame(5000, $formulaQuote['breakdown']['formula_cost']);
    }

    public function test_versioned_pricing_seeder_is_idempotent_and_copies_existing_values(): void
    {
        DB::table('farast_pricing_policy_versions')->where('policy_code', 'page_base')->delete();

        PricingRule::updateOrCreate(['key' => 'page_base'], [
            'value' => 35000,
            'label' => 'configured page base',
            'active' => true,
        ]);

        $seeder = app(VersionedPricingPolicySeeder::class);
        $seeder->run();
        $seeder->run();

        $versions = FarastPricingPolicyVersion::where('policy_code', 'page_base')->get();
        $this->assertCount(1, $versions);
        $this->assertSame(35000, (int) $versions->first()->unit_price);
        $this->assertSame('pricing_rules', $versions->first()->metadata['source']);
    }

    public function test_voice_project_quote_uses_versioned_page_and_minute_rates(): void
    {
        foreach ([
            ['typing_voice_page', 'typing.voice', 'page', 5000],
            ['typing_voice_minute', 'typing.voice', 'minute', 2000],
            ['typing_payg_multiplier', 'typing.payg', 'percentage', 0],
        ] as [$code, $capability, $unit, $price]) {
            FarastPricingPolicyVersion::create([
                'policy_code' => $code,
                'version' => ((int) FarastPricingPolicyVersion::where('policy_code', $code)->max('version')) + 1,
                'capability_code' => $capability,
                'unit' => $unit,
                'currency' => 'IRR',
                'unit_price' => $price,
                'additional_unit_price' => null,
                'fee_amount' => 0,
                'discount_basis_points' => 0,
                'tax_basis_points' => 0,
                'payg_multiplier_basis_points' => $code === 'typing_payg_multiplier' ? 15000 : 10000,
                'included_quantity' => 0,
                'effective_from' => now()->subMinute(),
                'checksum' => hash('sha256', $code.'|voice-test'),
            ]);
        }

        $quote = app(PricingService::class)->projectQuote('voice', '', 2, '', true, 3);

        $this->assertSame(5000, $quote['unit_price_rials']);
        $this->assertSame(6000, $quote['audio_cost_rials']);
        $this->assertSame(24000, $quote['price_rials']);
        $this->assertSame(150, $quote['breakdown']['multiplier']);
    }

    public function test_database_seeder_preserves_existing_admin_price_values(): void
    {
        PricingRule::updateOrCreate(['key' => 'page_base'], [
            'value' => 87654,
            'label' => 'configured live price',
            'active' => true,
        ]);

        app(DatabaseSeeder::class)->run();

        $this->assertSame(87654, (int) PricingRule::where('key', 'page_base')->value('value'));
    }

    public function test_admin_price_edit_does_not_shadow_a_scheduled_future_policy(): void
    {
        $admin = app(PricingPolicyAdminService::class);
        $admin->createVersion([
            'policy_code' => 'page_base',
            'unit' => 'page',
            'unit_price' => 55555,
            'effective_from' => now()->addDay(),
        ]);
        $before = FarastPricingPolicyVersion::where('policy_code', 'page_base')->count();

        try {
            $admin->createVersionFromPricingRule('page_base', 66666, 'conflicting edit');
            $this->fail('An admin edit must not silently shadow a scheduled future policy.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('future_pricing_policy_version_exists', $exception->getMessage());
        }

        $this->assertSame($before, FarastPricingPolicyVersion::where('policy_code', 'page_base')->count());
    }

    public function test_percentage_amount_uses_central_integer_rounding(): void
    {
        $engine = app(PricingEngine::class);

        $this->assertSame(6000, $engine->percentageAmount(100001, 500, 1000, 'up'));
        $this->assertSame(5000, $engine->percentageAmount(100000, 500, 1000, 'up'));
        $this->assertSame(0, $engine->percentageAmount(0, 500, 1000, 'up'));
        $this->assertSame(5000, $engine->percentageAmount(100001, 500, 1000, 'down'));
        $this->assertSame(111, $engine->allocateProRata(333, 1, 3));
        $this->assertSame(222, $engine->allocateProRata(333, 2, 3));
        $this->assertSame(333, $engine->allocateProRata(333, 0, 0, true));
        $this->assertSame(['subtotal' => 100000, 'discount' => 10000, 'fee' => 5000, 'taxable' => 95000, 'tax' => 9500, 'total' => 104500], $engine->totals(100000, 10000, 5000, 1000));
    }

    public function test_admin_price_edit_creates_a_new_immutable_version(): void
    {
        $previous = app(PricingEngine::class)->resolve('page_base');
        $previousPrice = $previous ? (int) $previous->unit_price : null;
        $previousChecksum = $previous?->checksum;
        $newVersion = app(PricingPolicyAdminService::class)->createVersionFromPricingRule(
            'page_base',
            45678,
            'قیمت جدید هر صفحه'
        );

        $this->assertSame(45678, (int) $newVersion->unit_price);
        $this->assertSame('admin_pricing_rule', $newVersion->metadata['source']);
        $this->assertSame(($previous?->version ?? 0) + 1, (int) $newVersion->version);

        $this->assertSame($newVersion->id, app(PricingEngine::class)->resolve('page_base')->id);
        if ($previous) {
            $this->assertSame($previousPrice, (int) $previous->fresh()->unit_price);
            $this->assertNotNull($previous->fresh()->effective_until);
            $this->assertNotSame($previousChecksum, $previous->fresh()->checksum);
        }
    }

    public function test_policy_calculation_supports_allowance_additional_usage_discount_and_fee(): void
    {
        FarastPricingPolicy::create([
            'code' => 'legacy.compat.test',
            'capability_code' => 'typing.manual',
            'unit' => 'page',
            'base_price_rials' => 100000,
            'subscription_allowance' => 2,
            'additional_price_rials' => 120000,
            'payg_multiplier_percent' => 100,
            'discount_percent' => 10,
            'fee_rials' => 5000,
            'active' => true,
        ]);

        $result = app(PricingService::class)->calculate('legacy.compat.test', 4, true);

        $this->assertSame(2, $result['included_units']);
        $this->assertSame(2, $result['billable_units']);
        $this->assertSame(240000, $result['additional_usage_rials']);
        $this->assertSame(5000, $result['fee_rials']);
        $this->assertSame(221000, $result['final_price_rials']);
    }

    public function test_trusted_fixed_and_percentage_adjustments_share_engine_caps(): void
    {
        $engine = app(PricingEngine::class);

        $this->assertSame(700, $engine->trustedDiscountAmount(1000, [
            'discount_basis_points' => 2000,
            'fixed_discount_amount' => 500,
        ]));
        $this->assertSame(100, $engine->trustedDiscountAmount(100, [
            'fixed_discount_amount' => 150,
        ]));
        $this->assertSame(0, $engine->trustedDiscountAmount(100, [
            'discount_basis_points' => -100,
            'fixed_discount_amount' => -50,
        ]));
    }

}
