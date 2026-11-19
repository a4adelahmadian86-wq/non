# Pricing Engine Consolidation Audit

Baseline: `main` at `4120a55f8f64bc5e53db2cf46ec5d5fde1e179f4` (2026-11-19).

## Verified current structure

- `App\\Services\\PricingEngine` resolves effective, versioned `FarastPricingPolicyVersion` records and computes a quote containing the policy version, currency, included/billable quantity, subtotal, discount, fee, tax, total, and checksum.
- `CommerceAuthorizationService` uses `PricingEngine` for authorization, reservation, and charge snapshots. It derives included quantity from the actor's live entitlement and outstanding reservations; this is a runtime quota decision, not merely a policy default.
- `ProfessionalCommerceService` persists quote snapshots and idempotency context. It is an orchestration/quote-persistence layer and must not be replaced by a second calculator.
- `PricingService` still calculates through legacy `FarastPricingPolicy` / `PricingRule` paths in `calculate()`, `projectQuote()`, `quote()`, and `estimateByPages()`. Its private `ruleValue()` consults `PricingEngine::resolve()` first, but silently falls back to the legacy tables when no effective version exists.
- `TypingPreflightController::estimate()` and `accept()` both call `PricingService::estimateByPages()`. They independently apply free-page and deposit rules and persist an estimate snapshot into session/order data. This is a live customer-facing compatibility boundary.
- `tests/Unit/PricingPolicyTest.php` characterizes the legacy allowance/additional-usage/discount/fee calculation. `tests/Feature/CommercePhase3Test.php` covers the versioned engine, entitlement reservations, idempotency, wallet charging, and refunds, but does not establish parity between typing estimates and the versioned engine.

## Consolidation constraints

1. Keep `PricingEngine` as the only arithmetic engine. Keep authorization, entitlement reservation, promotions, quote persistence, and order orchestration in their existing services.
2. Do not equate `FarastPricingPolicyVersion.included_quantity` with a customer's remaining subscription quota. The latter is derived from entitlement quantity minus used and reserved quantity.
3. Preserve the public response keys and integer-rial rounding semantics consumed by typing preflight and existing views until each consumer is migrated deliberately.
4. Preserve historical order, deposit, charge, invoice, and quote snapshots. A policy change must affect new quotes only; it must not recalculate paid or pending historical records implicitly.
5. Do not silently use legacy prices when a policy version is missing in the eventual strict path. First provide an explicit compatibility/coverage check and a controlled cutover; fail closed only after every active policy has a valid version and all consumers are migrated.
6. Keep free-page allowance, deposit percentage/rounding, and typing-specific complexity estimation as explicit domain inputs/rules. They must not become hidden duplicate price arithmetic outside the engine.
7. Keep coupon/promotion adjustments trusted and server-derived. Preserve existing consent, margin, budget, redemption locking, and idempotency safeguards.

## Required implementation sequence

- Inventory every runtime caller of `PricingService` and document its expected response contract.
- Map each legacy key (`page_base`, typing page/minute keys, language/formula multipliers, PAYG multiplier) to a versioned policy code and verify active-version coverage. Do not invent prices or backfill from assumptions.
- Add parity/characterization tests for current customer-visible outputs, including zero/missing policy behavior, free-page application, deposit rounding, subscription quota exhaustion, PAYG, and historical snapshots.
- Add a compatibility adapter that delegates arithmetic to `PricingEngine` while preserving response shapes. Keep legacy fallback observable and temporary; do not remove tables or historical records during this phase.
- Migrate consumers incrementally, then remove fallback only after repository-wide call-site and data-coverage checks pass.
- Run focused Laravel tests, full Laravel suite, Phase 3 commerce workflow, and relevant browser tests on the exact PR head before merge.

## Current decision

No runtime pricing behavior or schema is changed by this audit. A direct replacement of `PricingService` before policy coverage and consumer inventory are proven would risk changing customer quotes, deposits, and existing commercial flows.
