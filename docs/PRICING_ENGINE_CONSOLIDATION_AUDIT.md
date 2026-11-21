# Pricing Engine Consolidation Audit

Audit origin: `main` at `4120a55f8f64bc5e53db2cf46ec5d5fde1e179f4` (2026-11-19). Implementation and follow-up status below reflect the merged work through 2026-11-21.

## Verified current structure

- `App\\Services\\PricingEngine` resolves effective, versioned `FarastPricingPolicyVersion` records and computes a quote containing the policy version, currency, included/billable quantity, subtotal, discount, fee, tax, total, and checksum.
- `CommerceAuthorizationService` uses `PricingEngine` for authorization, reservation, and charge snapshots. It derives included quantity from the actor's live entitlement and outstanding reservations; this is a runtime quota decision, not merely a policy default.
- `ProfessionalCommerceService` persists quote snapshots and idempotency context. It is an orchestration/quote-persistence layer and must not be replaced by a second calculator.
- `PricingService::calculate()` delegates to `PricingEngine` whenever an effective version exists and maps the quote back to its legacy response shape; it retains a temporary legacy fallback only for policy codes without an effective version. `projectQuote()`, `quote()`, and `estimateByPages()` route versioned-policy arithmetic through `PricingEngine`, including PAYG, voice-minute, language/density, formula line adjustments, and free-page allowances; their legacy arithmetic remains only when the required policy version is absent. `initialTypingPrices()` resolves effective versioned values first. Typing preflight delegates free-page discount and deposit percentage/rounding arithmetic to the engine while retaining page-count deposit tier selection as a domain rule.
- Store cart line totals, percentage coupons, smart-discount percentage amounts, margin floors, checkout tax, and pro-rata order line allocations use `PricingEngine` arithmetic helpers. `StoreCouponRedemptionService` remains the transactional eligibility/budget/consent/idempotency guard and never recalculates paid order totals.
- `TypingPreflightController::estimate()` and `accept()` both call `PricingService::estimateByPages()`. They obtain free-page allowance discounts and deposit amount rounding from `PricingEngine`, while retaining page-count deposit tier selection as a domain rule; they persist an estimate snapshot into session/order data. This is a live customer-facing compatibility boundary.
- `tests/Unit/PricingPolicyTest.php` covers versioned engine delegation, typing estimates, language and formula charges, voice-minute/PAYG quotes, immutable admin price versioning, seeder idempotency, and legacy fallback. `tests/Feature/CommercePhase3Test.php` covers the versioned engine, entitlement reservations, idempotency, wallet charging, and refunds; typing preflight now exercises the shared free-page allowance and deposit-rounding paths; the full upload-to-deposit-to-editor browser journey is covered by PR #738.

## Consolidation constraints

1. Keep `PricingEngine` as the only arithmetic engine. Keep authorization, entitlement reservation, promotions, quote persistence, and order orchestration in their existing services.
2. Do not equate `FarastPricingPolicyVersion.included_quantity` with a customer's remaining subscription quota. The latter is derived from entitlement quantity minus used and reserved quantity.
3. Preserve the public response keys and integer-rial rounding semantics consumed by typing preflight and existing views until each consumer is migrated deliberately.
4. Preserve historical order, deposit, charge, invoice, and quote snapshots. A policy change must affect new quotes only; it must not recalculate paid or pending historical records implicitly.
5. Do not silently use legacy prices when a policy version is missing in the eventual strict path. First provide an explicit compatibility/coverage check and a controlled cutover; fail closed only after every active policy has a valid version and all consumers are migrated.
6. Keep free-page allowance, deposit percentage/rounding, and typing-specific complexity estimation as explicit domain inputs/rules. They must not become hidden duplicate price arithmetic outside the engine.
7. Keep coupon/promotion adjustments trusted and server-derived. Preserve existing consent, margin, budget, redemption locking, and idempotency safeguards.

## Implementation sequence and cutover gates

- Completed in PR #739: inventory runtime callers of `PricingService` and document their expected response contracts.
- Completed in PR #739: map the 13 active legacy pricing keys to versioned policy codes and verify active-version coverage. Values are preserved from configured sources; no prices are invented or backfilled from assumptions.
- Added regression coverage in PR #739 for customer-visible outputs, zero/missing policy behavior, free-page application, deposit rounding, subscription quota exhaustion, PAYG, and historical snapshots.
- Added the compatibility adapter and observable legacy-fallback telemetry in PR #739. Keep the fallback temporary; do not remove tables or historical records during this phase.
- Migrate consumers incrementally; the audited consumers and active-version coverage checks are in place. Removal of the fallback remains a separate controlled cutover after operational confirmation.
- Focused Laravel tests, full Laravel suite, Phase 3 commerce workflow, and relevant browser tests passed on the PR heads for the merged changes. The latest post-merge `main` CI run is tracked separately below.

## Implementation merged to main

- `PricingService::calculate()` uses the versioned engine first and exposes the immutable policy version ID in its compatibility response.
- Active typing/document `pricing_rules` values are copied as-is into missing immutable policy versions by a migration and fresh-install seeder; existing versions are never overwritten.
- Admin price edits append and activate immutable policy versions for allow-listed keys; repeated database seeding no longer overwrites configured prices.
- Typing estimates, voice/document quotes, free-page allowance, deposit rounding, store cart/coupon math, smart discount amounts, margin floors, checkout tax, and pro-rata line allocation delegate arithmetic to `PricingEngine` when their required inputs/policies are available.
- Regression tests cover engine precedence, compatibility fallback, quote adjustments, immutable admin versioning, seeder idempotency, and arithmetic rounding/allocation.
- No customer orders, payments, wallet balances, or historical snapshots were changed.

## Post-consolidation follow-up

- Runtime caller inventory, the 13-key active-version coverage check, and observable legacy-fallback telemetry were merged in PR #739.
- The upload-to-deposit-to-editor HTTP journey was merged in PR #738 after its checks passed.
- Subscription quota exhaustion coverage was merged in PR #739.
- Trusted fixed/percentage adjustments flow through PricingEngine for store campaign coupons and versioned commerce quotes; the coupon ledger records the coupon-only discount while wallet holds use the final engine total. This was merged in PR #739.
- Course resource-uploader and team-assigned product/course publisher acceptance regressions were merged in PR #741. Publisher price authority remains enforced; no prices are invented.
- The PR-head CI suites passed for PRs #739 and #741. Post-merge CI on the latest `main` commit is still running; confirm it completes successfully before treating this consolidation milestone as fully verified.
- Keep legacy fallback observable until every active policy is covered and a controlled cutover is explicitly approved. Do not remove legacy tables or rewrite historical prices/snapshots as part of this follow-up.
