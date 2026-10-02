<?php

namespace App\Services;

use App\Models\FarastPricingPolicyVersion;
use InvalidArgumentException;

class PricingEngine
{
    public function resolve(string $policyCode, array $context = []): ?FarastPricingPolicyVersion
    {
        $query = FarastPricingPolicyVersion::query()->where('policy_code', $policyCode);
        if (!empty($context['region_code'])) {
            $region = (string) $context['region_code'];
            $query->where(function ($q) use ($region) { $q->where('region_code', $region)->orWhereNull('region_code'); });
            $query->orderByRaw('CASE WHEN region_code = ? THEN 0 ELSE 1 END', [$region]);
        }
        $now = now();
        $query->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $now))
            ->orderByDesc('version');

        return $query->first();
    }

    public function quote(string $policyCode, float $quantity, array $context = []): array
    {
        if ($quantity < 0) throw new InvalidArgumentException('quantity_must_be_non_negative');

        $policy = $this->resolve($policyCode, $context);
        if (!$policy) {
            return [
                'available'=>false,'policy_code'=>$policyCode,'policy_version'=>null,'currency'=>(string)($context['currency'] ?? 'IRR'),
                'unit'=>(string)($context['unit'] ?? 'unit'),'quantity'=>$quantity,
                'unit_price'=>0,'subtotal'=>0,'discount'=>0,'fee'=>0,'tax'=>0,'total'=>0,
            ];
        }

        $unitPrice = max(0, (int) $policy->unit_price);
        $additional = $policy->additional_unit_price === null ? $unitPrice : max(0, (int) $policy->additional_unit_price);
        $included = max(0.0, (float)($context['included_quantity'] ?? 0));
        $includedConsumed = min($quantity, $included);
        $billable = max(0.0, $quantity - $includedConsumed);
        $rawSubtotal = (int) round($billable * $additional);
        $multiplier = max(10000, (int) $policy->payg_multiplier_basis_points);
        $subtotal = (int) round($rawSubtotal * $multiplier / 10000);

        $discountBp = max(0, min(10000, (int) $policy->discount_basis_points));
        $trusted = is_array($context['trusted_adjustments'] ?? null) ? $context['trusted_adjustments'] : [];
        $extraDiscountBp = max(0, min(10000, (int)($trusted['discount_basis_points'] ?? 0)));
        $discount = (int) round($subtotal * ($discountBp + min(10000 - $discountBp, $extraDiscountBp)) / 10000);

        $fee = max(0, (int) $policy->fee_amount + (int)($trusted['fee_amount'] ?? 0));
        $taxBp = max(0, min(10000, (int) $policy->tax_basis_points));
        $taxable = max(0, $subtotal - $discount + $fee);
        $tax = (int) round($taxable * $taxBp / 10000);
        $total = max(0, $taxable + $tax);

        return [
            'available'=>true,
            'policy_code'=>$policy->policy_code,
            'policy_version'=>(int)$policy->version,
            'pricing_policy_version_id'=>$policy->id,
            'capability_code'=>$policy->capability_code,
            'currency'=>$policy->currency,
            'unit'=>$policy->unit,
            'quantity'=>$quantity,
            'included_quantity'=>$includedConsumed,
            'billable_quantity'=>$billable,
            'unit_price'=>$additional,
            'subtotal'=>$subtotal,
            'discount'=>$discount,
            'fee'=>$fee,
            'tax'=>$tax,
            'total'=>$total,
            'effective_from'=>$policy->effective_from?->toIso8601String(),
            'checksum'=>$policy->checksum,
        ];
    }
}