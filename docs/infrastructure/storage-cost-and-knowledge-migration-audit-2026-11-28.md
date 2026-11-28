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

The open PR #761 adds a configurable 5–1,440 minute interval for healthy volumes, with a documented 24-hour Arvan value. That reduces routine checks but does not remove the 3-operation probe, the initial forced probe/inventory, or repeated retries while a volume is unhealthy/unverified.

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
| #761 | Internal storage quota caps and health interval | Open; earlier head CI passed (FARAST CI #7729, Phase 3 #4430, Phase 4 #6911). Follow-up head `915bf00f...` adds scheduled inventory suppression and exponential backoff; its new CI was queued at the last refresh. | Useful partial guard only. Follow-up reduces inventory/retry costs but does not solve atomic reservation, full-download checksum cost, the 3-operation probe, weekly backup egress, monthly egress or operation metering. Do not call it a no-overage guarantee. |
| #762 | Arvan free-tier documentation | Open; all observed required jobs on head `35f9154c...` completed successfully (FARAST CI #7748 and Phase 4 #6929); voice-model smoke was skipped by its branch condition. | Keep open; documentation-only. |
| #759 | Netlify AI gateway fallback | Open; FARAST CI #7726, Netlify contract tests #9, Phase 4 #6908 passed. | Not deployed; it does not establish a domestic fallback or bypass provider eligibility restrictions. |
| #763 | Reader toolbar icon regression | Open; FARAST CI #7741 and Phase 3 #4431 passed; an earlier duplicate FARAST CI run #7740 was cancelled; Phase 4 #6922 passed. | Unrelated to storage; leave untouched. |
| #753 | Broad AI gateway/infrastructure change | Open; deployment prerequisites are documented in that PR. | Do not merge wholesale; its gateway deployment/configuration prerequisite remains a separate decision. |

Workflow observations are based on the workflow runs returned for each exact PR head SHA. Empty combined-status arrays are not interpreted as success. A workflow still marked `in_progress` is not a pass.

## 4. Safe implementation sequence

### Gate A — before any Arvan connection
- Review and correct PR #761's guard and use the daily 24-hour interval for Arvan Base; even an hourly probe would consume about 2,160 operations/month at three operations per check.
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


## 7. Consumer audit: SQL knowledge dependencies confirmed

A second read-only pass traced the current direct consumers that must be preserved during any bundle migration:

- `app/Http/Controllers/StoreController.php`: the product reader loads document `pages`, `chunks` and `artifacts`; reader audio timing is joined to chunk indexes; the page endpoint reads a page row and returns `content_text`, content blocks, locator and content hash.
- `app/Services/ProductContentGenerationService.php`: uses `ProductKnowledgeService::contentGenerationContext()`, consumes verified fact IDs/source IDs and includes them in its source-grounded content-generation workflow.
- `app/Jobs/GenerateProductDocumentAudio.php`: loads SQL chunks and sends their text/locators to the dedicated document-audio service; its timing metadata maps back to chunk indexes.
- `app/Services/StoreProductDocumentPageBuilder.php`: builds reader-ready page HTML/text and chunk-to-reader-page locator mappings from extracted chunks; the resulting page records are persisted by the ingestion job.
- `app/Services/ProductKnowledgeService.php`: reads SQL chunks and facts for assistant context and content-generation readiness.

This expands the migration surface beyond the assistant context service. A safe abstraction must preserve:
1. reader page retrieval and private no-store response behavior;
2. reader audio chunk-index/timing mapping;
3. content-generation fact IDs, evidence and source fingerprints;
4. page-builder output and reader-page locators;
5. existing incomplete/partial document safeguards.

Do not simply replace the Eloquent relation in `ProductKnowledgeService`. First introduce one verified document-knowledge repository/reader abstraction and route all five consumers through it with legacy SQL fallback. The ingestion writer must publish the immutable bundle and its manifest atomically from the application's perspective before the SQL status can advertise the new bundle as ready. The old SQL rows remain the rollback path until every reader/audio/content-generation regression test and production reconciliation is complete.

### Additional audit boundary

The repository tree was inspected for the named application services/jobs/controllers, and the files above were read. This was a targeted consumer trace, not a formal static-analysis proof that no other code path reads those tables. Before schema changes or SQL row deletion, run a repository-wide search against the exact table/model/relation names and review every result, including tests, admin tools and reporting queries.


### 7.1 Additional operational and publication consumers

The targeted pass also found these SQL dependencies that must be included in the repository-wide pre-migration search:

- `app/Http/Controllers/AdminStoreController.php`: admin product-document status/summary views read the document's evidence-backed fact count, audio state and reader page counts; upload/replacement flows lock document rows and call content-generation provenance checks.
- `app/Http/Controllers/AdminProductOperationsController.php`: operations dashboard queries document processing states and retries failed/stale/awaiting-provider documents. It relies on the existing document row and retry service.
- `app/Services/ProductPublicationReadiness.php`: publication readiness checks the existence/count of SQL reader page rows and validates source/document IDs and source hashes.
- `app/Services/ProductLifecycleHealthService.php`: product lifecycle health checks stale document-processing rows and reader readiness.
- `app/Console/Commands/RecoverStaleProductDocuments.php` and `RetryOfflineProductDocuments.php`: recovery/retry commands operate on existing document rows and statuses; their behavior must remain compatible with a bundle that is pending, unavailable or corrupt.
- `app/Console/Commands/VerifyStorageBackup.php` and `RestoreStorageBackup.php`: backup verification/restore use the current storage manager and encrypted archive; preserve these paths and test a knowledge bundle round-trip as part of backup/restore verification.

The knowledge bundle should not remove the SQL document row or its status machine. Publication readiness and admin monitoring should gain explicit bundle state fields (e.g. pending, verified, unavailable, schema_mismatch) without treating a missing/corrupt bundle as complete. A bundle read failure must preserve the legacy fallback when the SQL payload is still present; after any future SQL-retention change, it must fail closed rather than publishing partial knowledge.


## 8. Critical backup traffic finding — weekly full-volume reads

Files:
- `routes/console.php`
- `app/Services/Storage/StorageBackupService.php`
- `app/Console/Commands/RunStorageBackup.php`

A further audit of the backup path found a significant recurring egress cost:

- Laravel schedules `storage:backup-weekly` every Sunday at 02:00.
- `StorageBackupService::createArchive()` iterates all managed files in `active` or `delete_pending` state with a `storage_volume_id`.
- It downloads each remote object from its storage volume into a local temporary workspace and adds it to the encrypted backup archive.
- The archive is then uploaded to each configured backup target.
- After upload, the service downloads the entire backup archive again, recalculates SHA-256 and fully opens/verifies its encrypted entries.

Therefore, if Arvan is configured as `iran_primary`, a weekly backup can read approximately the entire stored Arvan object set each week. At 5 GB of stored objects, four weekly full backups could read roughly 20 GB/month from Arvan before counting routine user reads, upload verification GETs, health probes, inventory listings or transfer verification. Compression may reduce backup upload size, but it does not reduce the initial source-object GET bytes because the files are read before archive creation. This can consume the full published 20 GB monthly traffic allowance by itself.

**Required correction before Arvan use:**
1. Do not assume the current weekly full backup is affordable for the Arvan Base volume.
2. Add an explicit per-volume backup policy. Arvan Base should not silently enter the full weekly backup source set.
3. Design incremental/version-aware backup or a separate encrypted knowledge-bundle replication strategy with known traffic bounds; retain a tested full restore path.
4. Before enabling any backup to/from Arvan, calculate worst-case monthly source reads, backup upload, archive verification download, routine upload verification, scheduled probes and application reads against the provider's actual traffic meter.
5. Do not simply disable backups globally: keep the existing backup path for other volumes. The Arvan-specific exclusion/policy must be explicit, visible in admin status, and accompanied by a documented recovery/retention plan.

The current weekly backup job's presence does not prove that an Arvan volume has been configured as a backup target. This finding concerns the behavior if an Arvan volume is configured as a managed source volume.


### 8.1 Follow-up PR #761 code change (CI pending at audit refresh)

The existing quota-guard PR was extended in place on its original branch; no parallel storage implementation was created.

- Scheduled checks now call `StorageConnectionService::verify(..., skipInventory: true)`, avoiding automatic S3 ListObjectsV2 inventory scans.
- Initial volume registration also skips full inventory. A deliberate admin verification remains available and may enumerate the bucket.
- Failed health checks now persist a retry timestamp and exponential backoff based on the configured interval, capped at 24 hours; a successful scheduled check clears the retry state.
- The UI/docs explain that a full manual verification may use additional API operations and inventory listing.

Exact updated head at this refresh: `5c33abf5418ce94dbdee6c0e5f264aa9d19f2e5d`. The latest workflow checks were queued/in progress; do not claim these follow-up changes have passed CI until their exact head runs complete.

Still open before Arvan Base:
- S3 probe remains PUT+GET+DELETE (three operations).
- Routine upload verification still downloads the full object.
- Capacity caps are not atomic reservations.
- Monthly traffic and operation consumption are not metered.
- Weekly full backup can read every managed object from the Arvan volume.


## 9. Knowledge bundle data contract — implementation proposal

The schema below is a proposal for the future implementation, not an existing deployed format. It reflects the current model fields that were inspected.

### 9.1 Bundle envelope

```json
{
  "schema": "farast.product-knowledge",
  "schema_version": 1,
  "document_id": 123,
  "product_id": 45,
  "product_file_id": 678,
  "source_sha256": "<64-char-source-sha256>",
  "extraction_version": 1,
  "generated_at": "ISO-8601",
  "processing": {
    "status": "completed",
    "detected_type": "pdf",
    "page_count": 12,
    "word_count": 4200,
    "character_count": 26000,
    "completeness": {},
    "inspection": {},
    "ai_review": {}
  },
  "chunks": [
    {
      "id": 1001,
      "index": 0,
      "type": "paragraph",
      "locator": {},
      "content": "source-grounded text",
      "sha256": "<chunk-sha256>",
      "metadata": {}
    }
  ],
  "facts": [
    {
      "id": 2001,
      "source_sha256": "<64-char-source-sha256>",
      "type": "definition",
      "key": "example",
      "value": "example",
      "evidence": "verbatim evidence",
      "locator": {},
      "verification_status": "evidence_backed"
    }
  ],
  "reader_pages": [
    {
      "page_number": 1,
      "page_kind": "pdf_page",
      "locator_key": "page:1",
      "locator": {},
      "content_text": "page text",
      "content_html": "sanitized server-generated HTML",
      "content_sha256": "<page-sha256>",
      "has_extracted_text": true
    }
  ]
}
```

The envelope must include stable original SQL IDs for facts/chunks if any consumer, provenance report or generated-content citation refers to those IDs. IDs must not be regenerated during retries or re-serialization.

### 9.2 SQL manifest proposal

The SQL document row remains the stable relational identity and state machine. A separate manifest record or carefully versioned columns should record:
- bundle status: `pending`, `ready`, `unavailable`, `checksum_mismatch`, `schema_unsupported`;
- bundle schema version;
- storage managed-file ID and storage disk/path;
- compressed and uncompressed byte sizes;
- bundle SHA-256 and source SHA-256;
- chunk/fact/page counts;
- created/verified timestamps and last error code.

A bundle is not `ready` until the object has been written, read back/verified under the approved verification policy, and the manifest is committed. The manifest must never point at an unverified object.

### 9.3 Compression and identity

- Use deterministic JSON serialization (stable key ordering, UTF-8, unescaped Unicode/slashes) followed by gzip.
- Hash the exact compressed bytes for the stored-object checksum; separately preserve each source/chunk/page hash.
- Make retries idempotent by locking the document row and comparing source SHA-256 + extraction version + bundle schema version before creating a new object.
- Keep application-level immutable versions. Do not enable provider Versioning just to simulate application versioning on the 5 GB Base plan.
- Do not assume an ETag is SHA-256. Use the approved storage verification policy and keep explicit strict readback for migration/restore.
- Keep private ACL and `critical` classification.

### 9.4 Data that must remain outside this bundle

- `user_knowledge` rows and inferred behavior/personality/audience profiles;
- private conversation contents and per-user interaction history;
- unpublished/rejected customer reviews or unverified user experiences;
- any access token, provider credential, signed URL or environment value.

Approved user reviews/experiences, if ever included in a separate recommendation corpus, need their own approval/provenance state and must not be mixed into the immutable source-file extraction bundle.


## 10. Additional per-object API costs and transfer verification

Files:
- `app/Services/Storage/StorageManager.php`
- `app/Services/Storage/S3CompatibleAdapter.php`
- `app/Services/Storage/StorageTransferService.php`
- `app/Http/Controllers/StoreController.php`

Further request-path findings:
- `StorageManager::exists()` delegates to S3 `HEAD`; `size()` also uses `HEAD`. These are small-response requests, but they still consume provider operations when called for remote objects.
- Reader and preview paths call `exists()` before serving some managed objects, so a page view can generate metadata requests in addition to the actual range/full GET.
- `StorageTransferService::transferPending()` uploads each staged file to the target and then downloads the complete destination object again to calculate/compare SHA-256. This is another full-object egress read from the target volume for every transferred object.
- If a transfer attempt fails after a remote write, cleanup can issue DELETE as well.

A simplified per-file cost model for an S3 target is therefore:
- ordinary upload: PUT + full-object GET for verification;
- scheduled health check: PUT + GET + DELETE;
- manual full verification: the three probe operations plus one or more LIST operations for inventory;
- staging transfer: destination PUT + full-object destination GET for verification;
- ordinary object access: one or more HEAD metadata requests plus actual GET/Range GET bytes;
- weekly backup: source GET for every managed object, then backup-target PUT and full backup-target GET for verification.

These paths must all be included in the monthly operation/egress budget. Counting only application user downloads or only the scheduled health-check interval will substantially understate consumption.


### 10.1 Atomic reservation must cover every S3 writer

The quota race is not confined to `StorageManager::storeUploadedFile()`:

- `StorageTransferService::transferPending()` independently selects a target using `capacity_bytes - used_bytes`, uploads directly through `S3CompatibleAdapter::putFile()`, then updates the counters after transfer.
- `StorageBackupService` independently selects backup targets using the same pre-upload capacity check, writes the archive through `uploadBackup()`, then increments the counters after the remote write.

A correct reservation primitive must be shared by the primary upload, pending transfer and backup-target write paths. Fixing only `StorageManager` would leave two concurrent write paths able to overrun the same configured internal cap. Reservation recovery must be conservative: a crashed request must not release capacity for an object that may already exist remotely. Failed cleanup must retain or reconcile the reservation rather than silently undercounting storage.


### 10.2 Recommended Arvan Base health interval

For the current three-operation S3 probe:
- 5-minute interval: about 25,920 probe operations per 30-day month — more than the documented 10,000-request allowance.
- 60-minute interval: about 2,160 operations/month — about 21.6% of the 10,000-request allowance.
- 1,440-minute (24-hour) interval: about 90 operations/month — about 0.9% of the allowance.

These are estimates assuming every PUT, GET and DELETE is counted and the volume remains active/healthy for the full month. They exclude initial/manual verification, inventory LIST requests, normal HEAD/GET/PUT operations, transfers and backups.

**Recommended Arvan Base configuration: `health_check_interval_minutes=1440` (24 hours), not 60.** The default for generic volumes remains unchanged; this is an explicit setting for a very limited free-tier bucket. A manual full verification remains an intentional operator action and should be used only when needed.
