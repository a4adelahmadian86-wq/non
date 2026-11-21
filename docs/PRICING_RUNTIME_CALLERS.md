# Pricing Runtime Caller Inventory

This inventory is based on the current application call sites and the compatibility contracts consumed by controllers and services. It deliberately records response fields rather than duplicating pricing formulas.

## PricingService call sites

| Caller | Method | PricingService API | Consumed contract |
|---|---|---|---|
| ProjectController::create | Project creation view | initialTypingPrices() | manual, voice, printed, handwritten, mixed, payg_multiplier_percent, voice_minute |
| ProjectBillingService::quote | Project quote | projectQuote() | pages, unit_price_rials, free_page_value_rials, price_rials, currency, workflow/source/payg/audio metadata, breakdown, policy version ID and tax when versioned |
| ProjectBillingService::entitlement | Subscription allowance display | projectQuote() | unit_price_rials is multiplied by the covered page count; entitlement quantity itself comes from live subscription counters |
| EditorController::analyze | OCR result and document creation | quote() | pages, chargeable_pages, word_count, price_rials, free_page_value_rials, pricing_policy_version_id, tax_rials, breakdown |
| TypingPreflightController::estimate and accept | Upload preflight | estimateByPages() | pages, price_rials, discount_rials, payable_price_rials, included_pages, free_page_value_rials, policy version ID, tax, estimate marker and breakdown |
| PaymentController::createForDocument | Final typing checkout | quote() or ProjectBillingService::quote() | Quote snapshot, page count, price, tax, and free-page value; the order stores the quote and the deposit credit separately |
| PaymentController::payWithWallet | Wallet checkout recalculation | quote() or ProjectBillingService::quote() | Same quote contract as order creation; the order's immutable source hash and idempotency key guard reuse |

PricingService::calculate() has no direct application call site in the current controller/service inventory. It remains a compatibility API and is covered by unit tests for both versioned and legacy policy paths.

## Versioned policy catalog

PricingService::versionedPolicyCodes() is the canonical runtime catalog and must remain aligned with VersionedPricingPolicySeeder:

- page_base
- typing_manual_page
- typing_voice_page
- typing_printed_page
- typing_handwritten_page
- typing_mixed_page
- typing_voice_minute
- typing_payg_multiplier
- english_multiplier
- arabic_multiplier
- mixed_multiplier
- dense_page_multiplier
- formula_unit

The migration and seeder copy active pricing_rules values without changing them. The coverage regression runs the seeder and asserts that every catalog key has an active legacy rule and an effective versioned policy, with the PAYG multiplier represented as basis points.

## Direct engine consumers

- CommerceAuthorizationService: authoritative quote, entitlement allowance, wallet reservation, charge and invoice snapshots.
- TypingPreflightController: deposit percentage and upward rounding to the configured rial increment.
- StoreCartService: line totals, trusted coupon/campaign discounts and taxable totals.
- PromotionService: server-derived coupon/promotion adjustment metadata; the engine computes the discount and the ledger stores the coupon-only amount.
- StoreCouponMarginPolicy: centralized percentage and line arithmetic for margin-safe campaign offers.
- PricingPolicyAdminService: immutable policy version creation and effective-date controls.

## Compatibility and cutover

- Response shapes above are compatibility contracts. Do not remove keys or change their meaning during the fallback period.
- Missing effective versions still use a legacy fallback where an active legacy record exists. That path now emits pricing.legacy_fallback_used with the policy code and source.
- A missing version with no active legacy record remains unavailable/zero; no replacement price is invented.
- The coverage test checks the 13 explicitly mapped keys. It does not authorize removing fallback for unrelated policy codes.
- Do not rewrite historical orders, deposits, charges, invoices or quote snapshots.
- Remove legacy fallback only in a separate controlled cutover after production data coverage and all consumers have been verified.
