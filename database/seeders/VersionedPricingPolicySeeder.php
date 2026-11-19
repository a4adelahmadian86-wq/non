<?php

namespace Database\\Seeders;

use Illuminate\\Database\\Seeder;
use Illuminate\\Support\\Facades\\DB;

class VersionedPricingPolicySeeder extends Seeder
{
    public function run(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('pricing_rules') || !DB::getSchemaBuilder()->hasTable('farast_pricing_policy_versions')) {
            return;
        }

        $definitions = [
            'page_base' => ['unit' => 'page', 'capability' => 'typing.manual'],
            'typing_manual_page' => ['unit' => 'page', 'capability' => 'typing.manual'],
            'typing_voice_page' => ['unit' => 'page', 'capability' => 'typing.voice'],
            'typing_printed_page' => ['unit' => 'page', 'capability' => 'typing.source_file'],
            'typing_handwritten_page' => ['unit' => 'page', 'capability' => 'typing.source_file'],
            'typing_mixed_page' => ['unit' => 'page', 'capability' => 'typing.source_file'],
            'typing_voice_minute' => ['unit' => 'minute', 'capability' => 'typing.voice'],
            'typing_payg_multiplier' => ['unit' => 'percentage', 'capability' => 'typing.payg'],
            'english_multiplier' => ['unit' => 'percentage', 'capability' => 'typing.manual'],
            'arabic_multiplier' => ['unit' => 'percentage', 'capability' => 'typing.manual'],
            'mixed_multiplier' => ['unit' => 'percentage', 'capability' => 'typing.manual'],
            'dense_page_multiplier' => ['unit' => 'percentage', 'capability' => 'typing.manual'],
            'formula_unit' => ['unit' => 'formula', 'capability' => 'typing.manual'],
        ];

        foreach ($definitions as $code => $definition) {
            $rule = DB::table('pricing_rules')->where('key', $code)->where('active', true)->first();
            if (!$rule || DB::table('farast_pricing_policy_versions')->where('policy_code', $code)->exists()) {
                continue;
            }

            $value = max(0, (int) $rule->value);
            $effectiveFrom = now();
            $payload = [
                'policy_code' => $code,
                'version' => ((int) DB::table('farast_pricing_policy_versions')->where('policy_code', $code)->max('version')) + 1,
                'capability_code' => $definition['capability'],
                'unit' => $definition['unit'],
                'currency' => 'IRR',
                'unit_price' => $code === 'typing_payg_multiplier' ? 0 : $value,
                'additional_unit_price' => null,
                'fee_amount' => 0,
                'discount_basis_points' => 0,
                'tax_basis_points' => 0,
                'payg_multiplier_basis_points' => $code === 'typing_payg_multiplier' ? max(10000, $value * 100) : 10000,
                'included_quantity' => 0,
                'region_code' => null,
                'effective_from' => $effectiveFrom,
                'effective_until' => null,
                'metadata' => json_encode([
                    'source' => 'pricing_rules',
                    'source_key' => $code,
                    'source_label' => $rule->label,
                    'migrated_without_price_change' => true,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            $payload['checksum'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $payload['created_at'] = $effectiveFrom;
            $payload['updated_at'] = $effectiveFrom;

            DB::table('farast_pricing_policy_versions')->insert($payload);
        }
    }
}
