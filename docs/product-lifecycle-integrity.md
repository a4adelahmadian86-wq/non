# Product lifecycle integrity

The admin product operations center combines read-only signals from the catalog, secure upload intake, document processing, course curriculum, and reader publication gates.

## Lifecycle contract

1. A product is created as a draft. Catalog metadata alone does not make its payload available.
2. Product files are staged and inspected before confirmation. The original payload remains on private storage; inspection records include a SHA-256 fingerprint.
3. A confirmed product file is processed asynchronously. Extraction, semantic review, reader page generation, and optional audio generation are separate persisted stages.
4. A reader-only product requires a clean active primary download file, a document bound to that exact file and product, matching source hashes, a completed/extracted document state, a ready reader marker, and persisted pages.
5. A course requires at least one publishable lesson. Lesson resources remain tied to their course lesson and are not interchangeable with the course's main product payload.
6. Purchase entitlements and historical order records are independent from catalog visibility. Archiving must not delete customer access history.

## Operations snapshot

ProductLifecycleHealthService is deliberately read-only. It reports:

- Published digital products without an active, clean, primary product_download file.
- Published courses without a publishable lesson.
- Published reader-only products whose primary document does not satisfy the reader readiness contract.
- Products marked published but with a null or future published_at timestamp.
- Upload inspections that have expired but remain in the inspected state.
- Document jobs stuck in pending for more than 30 minutes or processing for more than 15 minutes.
- Product and ingestion counts grouped by their persisted type/status values.

The existing operations panel separately reports queue backlog, failed Laravel jobs, private storage writability, queue driver, scheduled-worker configuration, and presence of supervisor/audio configuration.

A configured endpoint is not treated as a successful health check. The dashboard never displays credentials and never repairs, retries, publishes, deletes, or modifies customer entitlements automatically. Recovery remains an explicit, audited admin action.

## Scope boundaries

The snapshot is a diagnostic contract, not a substitute for the publication guard. Publication must continue to validate current product data and file/document fingerprints at the point of mutation. Metrics can expose inconsistencies, but must not silently make them publishable.
