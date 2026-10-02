<?php

namespace App\Contracts\Commerce;

use JsonSerializable;

final class AuthorizationDecision implements JsonSerializable
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?int $entitlementId,
        public readonly string $mode,
        public readonly ?float $remaining,
        public readonly string $unit,
        public readonly ?int $pricingPolicyVersionId,
        public readonly array $pricingPolicy,
        public readonly ?string $reservationId,
        public readonly array $limits,
        public readonly ?string $denialReason,
    ) {}

    public function toArray(): array
    {
        return [
            'allowed'=>$this->allowed,
            'entitlement'=>$this->entitlementId,
            'mode'=>$this->mode,
            'remaining'=>$this->remaining,
            'unit'=>$this->unit,
            'pricing_policy'=>$this->pricingPolicy,
            'reservation'=>$this->reservationId,
            'limits'=>$this->limits,
            'denial_reason'=>$this->denialReason,
        ];
    }

    public function jsonSerialize(): array { return $this->toArray(); }
}