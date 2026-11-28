# FARAST incremental external backup architecture

Date: 2026-11-28
Base: `main` at `19871c971388f337e3363532271d76dbcf69f5ff`
Status: implementation plan; no provider account, bucket, credentials, or production data touched.

## Decisions

1. **Do not use Arvan as a backup destination.** It remains the proposed Iran-resident primary only. PR #766 is merged and the service rejects Arvan endpoints for backup targets.
2. **Cloudflare R2 is not the current choice.** Its 10 GB-month Standard allowance, 1M Class A, 10M Class B, and free egress are attractive, but the current R2 activation guide requires completing a subscription checkout, and Cloudflare's billing guide says a primary payment method is required to purchase Cloudflare products. This conflicts with the no-foreign-card constraint. Sources: https://developers.cloudflare.com/r2/get-started/ and https://developers.cloudflare.com/billing/get-started/create-billing-profile/
3. **Tigris Standard is the selected external backup target** because it is S3-compatible, explicitly says no card to start, includes 5 GB Standard storage, 10,000 Class A (PUT/COPY/POST/LIST), 100,000 Class B (GET/SELECT/other), and zero egress. It is not unlimited: storage and operations beyond the free allowance are metered at published rates. FARAST must enforce lower local ceilings and stop before any overage. Source: https://www.tigrisdata.com/pricing/
4. **Upload.am is a possible secondary cold-copy option, not the primary object store.** It advertises 25 GB free, 5 GB per file, no card, and a public REST API with presigned upload, download, delete, and quota endpoints. It is not S3-compatible; a separate adapter is required. Its retention wording differs across current pricing/FAQ pages, and it has API rate limits. It must not be treated as durable backup until retention behavior is confirmed. Sources: https://www.upload.am/en/price, https://upload.am/en/faq, https://upload.am/docs/api
5. No provider account or API key is created by this code change. No credentials go into Git.

## Current backup behavior and why it is blocked

- The existing `StorageBackupService::createArchive()` downloads every active managed object into a full encrypted ZIP every run.
- It then uploads the ZIP, downloads the entire ZIP again, and verifies the archive. This can consume the whole Arvan 20 GB monthly traffic allowance if the source volume holds 5 GB and the backup runs weekly.
- PR #766 now blocks full archive creation when `iran_primary` sources exist unless a dangerous override is explicitly configured. That is a safety gate, not incremental backup.
- Existing `StorageManagedFile.checksum` and `size_bytes` provide a source identity without downloading the object again. This metadata must be validated before it is trusted.

## Target design: immutable content-addressed objects + encrypted snapshot manifests

### Data model

- `storage_backup_objects`: one row per immutable encrypted blob per destination volume; fields include destination volume, plaintext SHA-256, plaintext size, encrypted object key, encrypted size, status, verified timestamp, and metadata. Unique key: (destination volume, plaintext SHA-256).
- `storage_backup_run_objects`: pivot linking each completed snapshot run to the exact set of blobs referenced by its manifest.
- Existing `storage_backup_runs` remains the run/audit record. Its `object_key` points to the encrypted manifest, not a full ZIP, for format v3.
- Never infer that a blob exists solely because a row exists. New blobs are read back and authenticated before publication; retained blobs are reconciled by HEAD at least every 30 days. If a required object is missing, scheduled jobs fail closed rather than unexpectedly downloading Iran-primary sources.

### Snapshot algorithm

1. Acquire the existing global backup lock and preflight a healthy, non-Arvan S3-compatible external destination.
2. Create a consistent local database dump using the existing supported driver-specific tools. Collect local private/public/legacy files.
3. For each managed file, use its persisted SHA-256 and size. If a verified content-addressed blob already exists on the external target, reuse it without reading the source object. If it is new, download that source object exactly once, verify SHA-256 and size against the SQL metadata, encrypt it as a streaming object, and upload it. A missing/invalid checksum is a hard failure; never silently fall back to reading the entire Iran volume.
4. Encrypt local files and the database dump using the same streaming encryption format; deduplicate by SHA-256.
5. Build an authenticated encrypted manifest mapping every restore path to a blob key, checksum, size, and source metadata. The manifest includes database driver, schema/format version, snapshot time, object count and inventory HMAC.
6. Upload the manifest last. Read it back and verify checksum, authentication, and structure. Only then mark the run completed and attach the blob-reference pivot rows.
7. Retain the latest verified weekly snapshot and latest verified manual snapshot (configurable, bounded). After the new manifest is verified, remove old manifests and garbage-collect only blobs that have no remaining run references. Delete remote blobs before deleting their DB rows/counters.
8. Restore downloads the selected encrypted manifest and its referenced blobs, authenticates/decrypts each blob into the existing safe staging paths, then reuses the current database restore and remote-object staging flow. Existing ZIP v1/v2 restore remains supported for old backups.

### Encryption

- Use PHP Sodium `secretstream_xchacha20poly1305` for bounded-memory streaming encryption/decryption. Never read a multi-GB file into a PHP string.
- Derive separate encryption/authentication keys from `FARAST_BACKUP_ENCRYPTION_KEY` using domain-separated HMAC-SHA-256 derivation.
- Each new blob uses a fresh secretstream header. Reused content reuses the already encrypted blob; no deterministic nonce reuse.
- The manifest is authenticated and encrypted independently. Object keys are HMAC-derived opaque identifiers, not plaintext paths, user IDs, or raw SHA-256 hashes.
- Fail closed if sodium is unavailable or the backup key is missing/weak. Do not introduce a plaintext fallback.

### Cost and traffic controls

- Tigris local caps must be below its published free tier, with reserved headroom: default maximum 4 GiB stored, 8,000 Class A and 80,000 Class B operations per monthly period. These are conservative application limits, not provider-enforced guarantees.
- Maintain one active Tigris target per account and per-destination monthly operation counters transactionally. Count failed/uncertain requests conservatively. Local limits are 4 GiB storage, 8,000 Class A, and 80,000 Class B; a separate account-wide source ledger caps Iran-primary reads at 1 GiB per run, 5 GiB per month, and 7,000 source GETs/month. If counters are missing, stale, or cannot be reconciled, pause uploads.
- Do not enable Infrequent Access or Archive tiers; retrieval/minimum-retention charges and restore delay are unnecessary for the free Standard tier.
- Do not run periodic full-object source verification. Verify every new blob once by remote readback and authenticated decryption; verify the manifest every run; HEAD-reconcile reused blobs at most once per 30 days; full restore drills remain explicit.
- Initial baseline is a one-time read of current source objects. Make it resumable, byte-budgeted, and explicitly initiated after the user has configured the provider. Subsequent runs read only changed/new objects.
- A changed file is a new blob; unchanged files are not downloaded from Iran again. Removed files disappear from the next manifest and their old blobs are garbage-collected only after all retained snapshots stop referencing them.
- The current 5 GB Arvan storage allowance does not imply the entire 5 GB can be used for a one-shot baseline: preserve traffic headroom for normal site use and stop at the configured daily/monthly budget.

## Implementation sequence and current status

1. **Implemented on PR #767:** schema/models for content-addressed blobs, run references, and monthly usage ledger; no provider calls.
2. **Implemented on PR #767:** streaming Sodium secretstream encryption/decryption, authenticated chunks, partial-file cleanup, and tamper/wrong-key tests. Large-file memory is bounded to 1 MiB chunks.
3. **Implemented on PR #767:** format-v3 manifest writer alongside legacy ZIP v1/v2; feature remains disabled by default.
4. **Implemented on PR #767:** atomic monthly operation/source-read reservations, Tigris-only target validation, one-active-target guard, and 4 GiB storage ceiling.
5. **Implemented on PR #767:** v3 restore materialization into existing safe staging paths; legacy ZIP restore remains supported.
6. **Implemented on PR #767:** reference-aware retention, orphan cleanup, and monthly HEAD reconciliation. Further failure-injection tests are still required.
7. **Still required before enablement:** integration tests for baseline, unchanged second run (zero Iran source reads), one changed object (exactly one source read), new/removed file, missing blob, missing checksum, interrupted upload, manifest tampering, retention failure, restore after DB recreation, and legacy ZIP restore.
8. Run all required CI and fix every failure. Keep PR #767 draft and `FARAST_BACKUP_INCREMENTAL=false` until all checks pass.
9. Only after the user has created a Tigris account and supplied credentials through the protected admin configuration, perform one tiny non-sensitive test and verify quotas. The initial baseline is resumable and capped at 1 GiB/run and 5 GiB/month; never enable production backup automatically merely because a volume exists.

## Non-actions

- No Arvan API call, bucket creation, upload, download, listing, deletion, or credential use.
- No Cloudflare R2 subscription or payment setup.
- No Tigris or Upload.am account/API token creation.
- No live data migration, deletion, or production environment change.
- No override of `FARAST_BACKUP_ALLOW_IRAN_PRIMARY_FULL_READS`.
