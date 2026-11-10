# Product pipeline operations

The admin operations center is available at `/admin/store/operations`.

## What it reports

- Product-document processing counts by persisted status.
- Laravel database queue and failed-job counts.
- Queue driver and whether the scheduler-managed worker is enabled.
- Private-storage directory writability.
- Presence of the product-supervisor and document-audio configuration.

A configured provider is not the same as a live health check. The page deliberately reports configuration presence only and never displays credentials.

## Manual retry safety

Only documents in `failed`, `stale`, or `awaiting_provider` can be manually retried. The operation verifies that:

- The source file belongs to the same product and document.
- The file is active, clean according to the first-party static upload inspection, and has an allowed product/lesson purpose.
- The source exists on the private disk and is no larger than the current 25 MiB supervisor request ceiling. Larger files remain visible for diagnosis but cannot be retried until bounded batch processing is implemented.
- The file bytes, file record SHA-256, and document source SHA-256 all match.

The document and file rows are locked and rechecked before the status is changed. The audit event is recorded in the same transaction, and dispatch happens only after commit. Pending and processing jobs are not manually duplicated. Automatic recovery remains limited to expired processing leases and is handled by `farast:recover-product-documents`.

This retry is an operational recovery action, not a security approval or a publication action. It does not publish a product, change customer entitlements, or delete existing reader artifacts.
