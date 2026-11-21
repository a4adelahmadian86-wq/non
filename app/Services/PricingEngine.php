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

    public function totals(int $subtotal, int $discount = 0, int $fee = 0, int $taxBasisPoints = 0): array
    {
        $subtotal = max(0, $subtotal);
        $discount = min($subtotal, max(0, $discount));
        $fee = max(0, $fee);
        $taxBasisPoints = max(0, min(10000, $taxBasisPoints));
        $taxable = max(0, $subtotal - $discount + $fee);
        $tax = $taxable > 0
            ? $this->percentageAmount($taxable, $taxBasisPoints, 1, 'nearest')
            : 0;

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'fee' => $fee,
            'taxable' => $taxable,
            'tax' => $tax,
            'total' => $taxable + $tax,
        ];
    }

    public function allocateProRata(int $amount, int $part, int $whole, bool $last = false): int
    {
        $amount = max(0, $amount);
        $part = max(0, $part);
        $whole = max(0, $whole);
        if ($last) {
            return $amount;
        }
        if ($amount === 0 || $part === 0 || $whole === 0) {
            return 0;
        }

        return min($amount, (int) floor($amount * min($part, $whole) / $whole));
    }

    public function lineAmount(int $unitPrice, int $quantity): int
    {
        return max(0, $unitPrice) * max(0, $quantity);
    }

    /**
     * Calculate a percentage amount using integer basis points and an optional
     * currency rounding increment (for example, deposit amounts rounded up
     * to the next 1,000 rials).
     */
    public function percentageAmount(int $baseAmount, int $basisPoints, int $roundingIncrement = 1, string $rounding = 'nearest'): int
    {
        $baseAmount = max(0, $baseAmount);
        $basisPoints = max(0, min(10000, $basisPoints));
        $roundingIncrement = max(1, $roundingIncrement);
        if ($baseAmount === 0 || $basisPoints === 0) {
            return 0;
        }

        $numerator = $baseAmount * $basisPoints;
        $denominator = 10000 * $roundingIncrement;
        $units = match ($rounding) {
            'up' => intdiv($numerator + $denominator - 1, $denominator),
            'down' => intdiv($numerator, $denominator),
            default => (int) round($numerator / $denominator),
        };

        return $units * $roundingIncrement;
    }

    /**
     * Apply server-derived discount adjustments to an arbitrary subtotal.
     * Callers must never pass client-supplied adjustment values directly.
     */
    public function trustedDiscountAmount(int $subtotal, array $adjustments = []): int
    {
        $subtotal = max(0, $subtotal);
        if ($subtotal === 0) {
            return 0;
        }

        $discountBasisPoints = max(0, min(10000, (int) ($adjustments['discount_basis_points'] ?? 0)));
        $percentDiscount = $this->percentageAmount($subtotal, $discountBasisPoints, 1, 'nearest');
        $fixedDiscount = max(0, (int) ($adjustments['fixed_discount_amount'] ?? 0));

        return min($subtotal, $percentDiscount + $fixedDiscount);
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
        $priceMultiplier = max(0, min(1000000, (int) ($context['price_multiplier_basis_points'] ?? 10000)));
        $effectiveUnitPrice = (int) round($additional * $priceMultiplier / 10000);
        $included = max(0.0, (float)($context['included_quantity'] ?? 0));
        $includedConsumed = min($quantity, $included);
        $billable = max(0.0, $quantity - $includedConsumed);
        $preMultiplierCharge = max(0, (int) ($context['pre_multiplier_additional_charge_amount'] ?? 0));
        $postMultiplierCharge = max(0, (int) ($context['additional_charge_amount'] ?? 0));
        $rawSubtotal = (int) round($billable * $effectiveUnitPrice) + $preMultiplierCharge;
        $multiplier = max(10000, (int) ($context['payg_multiplier_basis_points'] ?? $policy->payg_multiplier_basis_points));
        $subtotal = (int) round($rawSubtotal * $multiplier / 10000) + $postMultiplierCharge;

        $discountBp = max(0, min(10000, (int) $policy->discount_basis_points));
        $trusted = is_array($context['trusted_adjustments'] ?? null) ? $context['trusted_adjustments'] : [];
        $extraDiscountBp = max(0, min(10000, (int)($trusted['discount_basis_points'] ?? 0)));
        $discount = $this->trustedDiscountAmount($subtotal, [
            'discount_basis_points' => $discountBp + min(10000 - $discountBp, $extraDiscountBp),
            'fixed_discount_amount' => $trusted['fixed_discount_amount'] ?? 0,
        ]);

        $fee = $billable <= 0 ? 0 : max(0, (int) $policy->fee_amount + (int)($trusted['fee_amount'] ?? 0));
        $taxBp = max(0, min(10000, (int) $policy->tax_basis_points));
        $totals = $this->totals($subtotal, $discount, $fee, $billable <= 0 ? 0 : $taxBp);
        $taxable = $totals['taxable'];
        $tax = $totals['tax'];
        $total = $totals['total'];

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
            'unit_price'=>$effectiveUnitPrice,
            'base_unit_price'=>$additional,
            'price_multiplier_basis_points'=>$priceMultiplier,
            'payg_multiplier_basis_points'=>$multiplier,
            'additional_charge_amount'=>$postMultiplierCharge,
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