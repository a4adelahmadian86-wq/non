# FARAST storage cost and knowledge migration audit

Date: 2026-11-28  
Audited base: `main` at `89a3c956cee6e15c121ddaf2c2c895d86af760dd`  
Scope: read-only audit of existing Laravel storage, product-document knowledge, and the open infrastructure pull requests. No Arvan credentials or bucket were used.

## Executive decision

**Do not connect the Arvan Base bucket to FARAST yet. Do not migrate or delete existing knowledge rows.**

The existing storage engine is reusable, but its current S3 health and upload-verification paths can consume substantial free-tier traffic and operations. Its application-side capacity counter is not a hard concurrent quota reservation and does not track monthly egress or API operations. The product-document knowledge itself is still stored and read from MySQL; adding an Object Storage bucket alone would not move the knowledge base.

The next implementation must strengthen the existing `StorageManager`, `S3CompatibleAdapter`, `StorageConnectionService`, `ProductKnowledgeService` and existing queue. Do not add a second storage engine, a second knowledge service, Qdrant, or a new queue.

## 1. Confirmed code findings on main

### 1.1 Full-object download after every upload

File: `app/Services/Storage/StorageManager.php`

- `storeUploadedFile()` calculates a local SHA-256, writes the object, then calls `verifyWrittenObject()`.
- `verifyWrittenObject()` downloads the entire just-uploaded object to a temporary file and hashes it again.
- This means each successful upload adds approximately one full object-size download in addition to the upload. For a 100 MB object, the verification path can add about 100 MB of egress.
- The check is valuable for integrity, especially during migration, but applying it unconditionally to every routine upload is too expensive for a 5 GB / 20 GB monthly / 10,000-operation Base allowance.

**Required correction before Arvan use:** retain strong end-to-end checksum verification for explicit migration/restore and periodic audit workflows, but make routine-upload verification use a provider-supported metadata/checksum/HEAD strategy only after that exact behavior is proven against Arvan. Do not trust ETag as SHA-256 without provider-specific evidence. If the provider cannot prove an object checksum without a full GET, fail closed or use an explicitly selected strict mode rather than silently claiming checksum verification.

### 1.2 S3 health check writes, reads and deletes a probe object

Files:
- `app/Services/StorageConnectionService.php`
- `app/Services/Storage/S3CompatibleAdapter.php`
- `app/Console/Commands/CheckStorageVolumes.php`

- `StorageConnectionService::verify()` calls `S3CompatibleAdapter::probe()` for S3 volumes.
- `probe()` performs PUT, GET and DELETE of a temporary object. This is three provider operations and includes a small egress read.
- Inventory refresh calls S3 ListObjectsV2 and paginates up to 10,000 pages of 1,000 objects each.
- Inventory is attempted at least once and then at six-hour intervals; creating a volume forces verification and inventory.
- On main, the scheduled command has no per-volume interval guard. An unsuccessful volume is retried on every scheduled run.

The open PR #761 adds a configurable 5–1,440 minute interval for healthy volumes, with a documented 60-minute Arvan value. That reduces routine checks but does not remove the 3-operation probe, the initial forced probe/inventory, or repeated retries while a volume is unhealthy/unverified.

**Required correction before Arvan use:** split connectivity/authentication checks from write/read/delete capability tests; default scheduled checks must be non-mutating and low-operation. Make inventory an explicit, separately throttled/manual action for small free-tier buckets. Add bounded exponential retry/circuit-breaker behavior for unhealthy volumes instead of retrying every scheduler tick. Preserve an explicit admin-only full capability test for providers where write/read/delete validation is needed.

### 1.3 Capacity guard is not a hard quota reservation

File: `app/Services/Storage/StorageManager.php`

- `selectVolumes()` reads `capacity_bytes - used_bytes`, `file_limit`, and `max_file_bytes`.
- The remote upload occurs before `register()` increments `used_bytes` and `object_count`.
- Two concurrent uploads can both select the same apparent remaining capacity and both upload before either counter increment is committed.
- Counters can be stale if objects are uploaded outside FARAST or if a previous request stops between remote upload and database registration.
- S3 APIs do not generally expose the provider's commercial plan quota. The counter is therefore a local estimate, not a provider-enforced limit.

PR #761 adds configurable internal capacity/object/per-file caps; it does not make them atomic and does not track monthly egress or operation consumption.

**Required correction before Arvan use:** add an atomic reservation under a database transaction/row lock before the provider write; reserve both bytes and object count, release on every failed write/cleanup path, and reconcile only through an explicitly controlled inventory operation. Keep a safety margin below the provider's advertised limit. Do not describe this as a guarantee against all charges: monthly traffic and provider billing usage still need provider-side monitoring and a hard account budget/disable procedure.

### 1.4 Product knowledge remains in MySQL

Files:
- `app/Jobs/ProcessProductDocument.php`
- `app/Services/ProductKnowledgeService.php`
- `app/Models/StoreProductDocument.php`
- `app/Models/StoreProductDocumentChunk.php`
- `app/Models/StoreProductDocumentFact.php`
- `app/Models/StoreProductDocumentPage.php`

Confirmed behavior:
- `ProcessProductDocument` deletes and recreates `chunks`, `facts`, and `pages` as relational rows after processing.
- Each extracted chunk's text is persisted in `store_product_document_chunks.content_text`.
- Verified facts are persisted in `store_product_document_facts` with evidence, locator and `evidence_backed` status.
- Reader pages are persisted in `store_product_document_pages`.
- `ProductKnowledgeService::contextForProduct()` eager-loads and reads `files.document.chunks` and `files.document.facts` directly from Eloquent relationships.
- `contentGenerationContext()` also reads document facts and product/course relations from SQL.
- `GenerateProductDocumentAudio` reads SQL chunks to create the audio artifact.

Therefore the knowledge base is **not yet stored independently in Object Storage**. Existing SQL rows are still the live source of truth for these consumers.

**Required migration shape:**
1. Keep current document IDs, source SHA-256, processing state, approval/review state and relational references.
2. Build a deterministic, compressed, versioned JSON knowledge bundle per immutable source hash. Include schema version, document/source IDs, source SHA-256, extraction version, chunk text + locators + chunk hashes, evidence-backed facts, page/reader locators, completeness flags and approved review summary/findings. Do not include private user profiles in the product bundle.
3. Upload the bundle via the existing StorageManager using `critical` classification and private visibility. The bundle must be immutable/versioned at the application level.
4. Store only a compact manifest/reference in SQL: storage disk/path, bundle schema version, byte length, SHA-256, source SHA-256, status and timestamps.
5. Add a reader abstraction used by every consumer. It should prefer a verified bundle and fall back to SQL for legacy records. Validate source hash, bundle hash, schema version and completeness before returning knowledge.
6. Migrate in batches with resumability and idempotency. Verify destination bytes/checksum and sample actual context/audio/reader behavior before marking a record migrated.
7. Keep SQL rows until every consumer has moved, the whole migration is reconciled, and restore tests pass. Only then consider a separate retention/deletion proposal; no automatic cleanup in this phase.

### 1.5 User memory is separate and private

File: `app/Services/UserKnowledgeService.php`

- Explicit/confirmed and inferred knowledge are persisted in the existing `user_knowledge` model/table.
- Inferred entries have a 90-day expiry; confirmed entries are retained.
- Project-scoped entries are separate from global entries.
- Do not mix this data into public product indexes, public buckets, public sample documents, or product knowledge bundles.
- Internal inferred behavior/personality profiles must never be presented as a user-visible profile. Any future external AI use needs a separate privacy review and explicit data minimization.

## 2. Arvan account and provider constraints already documented

See PR #762: [ArvanCloud Base free-tier account snapshot](https://github.com/a4adelahmadian86-wq/non/pull/762).

Current documented allowance is 5 GB storage, 20 GB monthly traffic and 10,000 read/write operations, with PAYG overage risk. The bucket must be private, objects private, Object Lock off, Versioning off initially, Multi-zone off, CORS unset, no Lifecycle auto-delete and no Replication.

The exact Simin endpoint `s3.ir-thr-at1.arvanstorage.ir` was reported as provisioning/collecting. Do not substitute the generic endpoint or send an S3 probe until the bucket details/Access Management confirms the exact endpoint is active. No bucket creation, credential use, S3 probe, upload, listing, download or deletion was performed during this audit.

## 3. Open pull requests — refreshed status

All items below were checked against GitHub on 2026-11-28. None was merged by this audit.

| PR | Scope | Current state / CI | Decision |
|---|---|---|---|
| #757 | Route private user files to `iran_primary` | Open; all three observed workflows passed (FARAST CI #7716, Phase 3 #4427, Phase 4 #6898). Branch base is older commit `d938dffd...`, not current main. | Keep open; rebase/update and re-review against current main before merge. Does not migrate already-active foreign objects. |
| #760 | Safer Runflare recreation command | Open; FARAST CI #7730, Product Supervisor #1350, Phase 4 #6912 passed. | Keep separate; no Runflare provisioning or deployment performed. |
| #761 | Internal storage quota caps and health interval | Open; FARAST CI #7729, Phase 3 #4430, Phase 4 #6911 passed. | Useful partial guard only. It does not solve atomic reservation, full-download checksum cost, inventory cost, monthly egress or operation metering. Do not call it a no-overage guarantee. |
| #762 | Arvan free-tier documentation | Open; Phase 4 #6929 passed; FARAST CI #7748 was still in progress at the last check. | Keep open; documentation-only. |
| #759 | Netlify AI gateway fallback | Open; FARAST CI #7726, Netlify contract tests #9, Phase 4 #6908 passed. | Not deployed; it does not establish a domestic fallback or bypass provider eligibility restrictions. |
| #763 | Reader toolbar icon regression | Open; FARAST CI #7741 and Phase 3 #4431 passed; an earlier duplicate FARAST CI run #7740 was cancelled; Phase 4 #6922 passed. | Unrelated to storage; leave untouched. |
| #753 | Broad AI gateway/infrastructure change | Open; deployment prerequisites are documented in that PR. | Do not merge wholesale; its gateway deployment/configuration prerequisite remains a separate decision. |

Workflow observations are based on the workflow runs returned for each exact PR head SHA. Empty combined-status arrays are not interpreted as success. A workflow still marked `in_progress` is not a pass.

## 4. Safe implementation sequence

### Gate A — before any Arvan connection
- Review and correct PR #761's guard rather than treating its current hourly interval as sufficient.
- Add atomic storage reservations and a failure-safe release path.
- Change default S3 health checks to a non-mutating, bounded check; move inventory and write/read/delete capability tests to explicit manual operations.
- Add tests for concurrent reservations, failed PUT cleanup, unhealthy-volume backoff, no automatic inventory, and no routine full-object GET after upload.
- Validate the chosen checksum/metadata behavior against the exact Arvan S3 endpoint only after the user confirms it is active. Until then, use mocked S3 adapter tests only.

### Gate B — knowledge bundle implementation
- Audit every consumer of chunks/facts/pages, including reader, audio, preview, content-generation, product assistant and admin views.
- Add the versioned bundle writer and verified reader abstraction on a separate branch.
- Keep legacy SQL reads as fallback.
- Add round-trip, checksum mismatch, wrong source hash, unknown schema version, partial bundle, retry/idempotency and old-record compatibility tests.
- Run full Laravel CI and focused regression tests. Do not migrate production data until a real Arvan volume has been configured and verified.

### Gate C — controlled migration and user action
- Only after the code PRs pass and exact endpoint readiness is confirmed, the user creates the private Standard bucket with the documented settings and supplies credentials through the existing admin UI (never Git/chat).
- Perform one small, non-sensitive test object only after explicit confirmation, verify private ACL and readback, then stop and inspect actual usage/operation counters.
- Do not run bulk migration until the test proves cost and restore behavior.
- The user must perform the Arvan panel steps and provide the active endpoint/bucket details. No action is currently required from the user until the code-side gates are complete.

## 5. Explicit non-actions

- No changes to `main`, Production, the live Laravel database, .env files, or service environment variables.
- No bucket was created; no Arvan Access Key/Secret Key was used or stored.
- No S3 API operation was sent.
- No user/product knowledge rows or existing remote objects were migrated, overwritten or deleted.
- No PR was merged, no infrastructure provisioned and no paid service enabled.
- No test suite was executed in a local runtime by this audit. CI states above are the observed GitHub Actions results only.

## 6. Re-audit triggers

Repeat the audit against the then-current `main` before any implementation branch is merged. Re-check exact Arvan endpoint readiness, account quota/usage, PR base/head SHAs, CI runs and all consumers of product document chunks/facts/pages.
