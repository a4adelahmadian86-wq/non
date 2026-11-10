# Product publication supervision workflow

## Lifecycle

- Saving an ordinary edit never publishes a product.
- A final submission moves the product to the private `pending_review` state and creates an immutable publication-review ledger row.
- The queued supervisor job waits for active product-download documents to finish their source processing, verifies source hashes through the existing knowledge service, and submits a bounded snapshot to the existing Product Supervisor service.
- An `approved` verdict can auto-publish only when the submitting account has `store.manage`, the product explicitly opted into auto-publication, there are no critical findings, and deterministic publication-readiness checks pass.
- Otherwise the product remains private and waits for a manager with `store.manage` to approve.
- A `needs_changes` or `blocked` verdict may apply only small editorial corrections to title, descriptions, and SEO fields. It cannot alter price, category, SKU, file, access policy, or publication state. Any correction always forces manual manager approval.
- If no evidence-safe correction is returned, the item remains in `needs_correction`; a manager must edit the product and submit a fresh review.
- Manager approval re-computes the same source-grounded snapshot and compares its fingerprint before publication. Any intervening edit makes the review stale.

## Security boundaries

- The file pipeline's structural scan remains separate from optional antivirus scanning and from AI editorial review.
- The supervisor receives a bounded product snapshot and persisted document-knowledge context; original file bytes are not sent by the product-publication job.
- AI output is treated as untrusted. Only five editorial fields are accepted, each with server-side length bounds.
- The AI service response always states that it cannot publish. Publication is performed only by the Laravel workflow after server-side authorization and readiness checks.
- Provider outages fail closed: products remain private and the review can be retried.

## Upload destination

The final upload-provider selection is intentionally not changed here. The existing storage-management work is being developed independently; this workflow only consumes already-persisted product files and their canonical document records. The provider-routing contract will be connected after the storage section's requirements are finalized.
