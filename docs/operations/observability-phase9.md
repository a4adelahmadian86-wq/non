# FARAST — Phase 9: Observability, Operational Security and Incident Management

## Architecture and source of truth

This phase extends existing first-party assets; it does not introduce a parallel audit, notification, queue or storage subsystem.

- Global HTTP middleware: `App\\Http\\Middleware\\FarastObservability`, prepended before other global middleware so early failures receive trace IDs.
- Request and correlation headers: `X-Request-Id` and `X-Correlation-Id`.
- Runtime configuration: `config/observability.php`; environment access is kept inside config for Config Cache compatibility.
- Canonical append-only audit source: `AuditEventService` + `farast_audit_events` + `AuditIntegrityService`.
- Existing compatibility audit writer/table: `AuditLogService` + `audit_logs`; it is not promoted as a replacement for the append-only ledger.
- Existing notification source: `NotificationService` + `farast_notifications`.
- Existing queue tables: `jobs` and `failed_jobs` when the configured queue driver uses the database.
- Existing storage inventory: `storage_volumes` and `storage_managed_files`.
- Existing provider readiness: `ProductInfrastructureHealthService`; the dashboard reuses its cached, bounded probes.
- Existing admin authorization: `admin.section:audit` under the existing admin scope.

## Request and background-job tracing

The global middleware validates inbound trace headers (maximum 64 safe characters), generates UUIDs for invalid/missing values, returns both headers, logs structured JSON request completion/failure, and clears context after each request. Query strings and request bodies are intentionally excluded.

Queue payload metadata carries the originating request and correlation IDs. Worker lifecycle listeners restore the trace on job start, log success/attempt failure/terminal failure, and clear context after each job. Child jobs inherit the current correlation context through the existing queue payload hook.

The canonical `AuditEventService` enriches append-only ledger events with the active trace identifiers before calculating the payload checksum. Sensitive metadata keys and credential-like query parameters are redacted before persistence. This makes the trace metadata part of the verifiable audit chain.

## Dashboard probes

The admin page is available at `/admin/observability` and uses the existing global `audit` section permission. It is read-only, shows the latest 20 incident/recovery transitions and a bounded 24-hour trend (up to 500 events) from the canonical audit ledger, and reports:

- Database connectivity.
- Queue driver, pending jobs, oldest pending age and failed jobs; `sync` is explicitly marked as non-measurable for backlog.
- Private storage directory writability, volume inventory and pending/failed transfers.
- Log directory writability, configured daily retention, and a bounded count of recent JSON error records from today's daily log file (last 15 minutes, at most 1 MiB read). The count is null when the configured daily channel cannot be read; a partial tail is explicitly marked incomplete.
- Existing Product Supervisor and document-audio readiness, through the existing cached readiness service.

Missing optional tables and unconfigured external services are reported as warning/incomplete, not healthy. Provider probe failures do not break the admin page and do not expose exception messages, credentials or filesystem paths.

## Alerting and anomaly detection

The scheduled command `farast:observability:check` runs every minute with overlap protection. It evaluates the dashboard snapshot, emits severity-aware application logs, deduplicates active incident alerts through the shared cache, clears their fingerprint gates on recovery so a new recurrence is alertable, preserves one stable incident ID across reminders and recovery, writes incident-open/reminder/recovery events to the canonical append-only audit ledger, and sends in-app notifications through the existing `NotificationService`. When an operator configures an HTTPS webhook URL, it also sends a bounded JSON alert with an optional bearer token; redirects are disabled and the destination URL/response body are never logged.

Configure explicit in-app recipients with `FARAST_OBSERVABILITY_ALERT_USER_IDS=12,34`; no notification is broadcast to all administrators by default. Multi-instance deployments must use a shared cache backend for cross-instance anomaly counters and alert deduplication; per-node file/array caches are not cluster-wide. Configure the repeat interval with `FARAST_OBSERVABILITY_ALERT_COOLDOWN_MINUTES` (5–1440, default 30). For out-of-band delivery, set `FARAST_OBSERVABILITY_WEBHOOK_URL` to an HTTPS endpoint and optionally `FARAST_OBSERVABILITY_WEBHOOK_TOKEN` to its bearer token. Without recipient IDs or a webhook, durable application logs and audit events are still emitted when the database is available; no external destination is assumed.

Repeated failed authentications are counted by a keyed HMAC fingerprint of the source IP in the shared cache. The default thresholds are 5 failures (warning) and 10 (critical) within 10 minutes. Repeated HTTP 403 authorization denials are separately counted by a keyed HMAC fingerprint of the authenticated actor ID (or source IP for guests), with thresholds of 10 (warning) and 25 (critical) within 10 minutes. Raw IP addresses, actor IDs, attempted usernames, emails and credentials are not stored in anomaly records. Threshold crossings are written to structured logs and the canonical audit ledger.

External delivery is implemented but remains inactive until the operator configures an HTTPS destination and proves production delivery. Internal notifications cannot alert operators when the application database/cache itself is unavailable; the webhook is the independent path for that failure mode.

## Audit integrity and retention

Verify the existing append-only ledger with:

```bash
php artisan farast:audit-ledger:verify
```

The command exits non-zero and emits a critical, secret-free log event when the chain is invalid or cannot be verified. It does not repair or rewrite the ledger. Every append is serialized through a stable row in Laravel's existing `migrations` table for the ledger-creation migration; SQLite uses a no-op update to acquire its database writer lock, while MySQL/PostgreSQL use a transaction-held row lock. The chain head is read only after that lock, preventing concurrent first writers and normal concurrent appends from forking the checksum chain without introducing another audit table. New appends use checksum version 2, covering the event UUID and creation/update timestamps as well as the event payload; records without a version marker remain verifiable with the legacy version 1 algorithm. Verification streams in chunks and retains at most 100 error samples while reporting the total failure count.

### Cross-database checksum contract

Checksum version 2 canonicalizes associative metadata keys recursively before hashing and after database decoding; list order is preserved. Exact `created_at` and `updated_at` values are persisted without Eloquent's automatic timestamp rewrite. Legacy version 1 rows retain byte-level verification; failed historical checksums are integrity findings and are never silently rewritten. The Feedback Evidence schema migration is additive and idempotent; its required columns and composite indexes are asserted in `FarastFeedbackEvidenceSchemaTest`, which runs in the MySQL 8.4 audit job. The composite `feedback_id,status` index keeps `feedback_id` as its leftmost key for feedback-only lookups.

Operational daily logs retain the existing 14-day Monolog rotation policy. The audit ledger is not automatically pruned: no destructive retention period is assumed without an approved business/legal schedule. This phase does not claim physical secure erasure on SSDs, snapshots or backups. Any future deletion process must include the database, replicas, backups and encryption-key lifecycle and must be tested before activation.

## Incident response

For each incident, use [the incident report template](incident-report-template.md) and record: incident ID, first-seen time, severity, affected service/tenant scope, request/correlation IDs, impact, mitigation, root cause, recovery verification and follow-up actions. Do not include secrets or unnecessary personal data.

## Verification status and remaining production acceptance

Automated tests cover bounded trace headers, logging-failure isolation, queue trace restoration/cleanup, terminal job failure context, canonical audit enrichment/redaction/integrity, health readiness reuse, bounded recent-error counting, alert deduplication/recovery, and authentication anomaly thresholds.

The following still require production-like verification or operator configuration:

1. Run one real HTTP request that dispatches a real database-backed job; correlate its request, worker, child-job and audit-ledger records.
2. Verify long-running worker cleanup over multiple real jobs and a retry/terminal-failure sequence.
3. Verify database, queue, storage and log probes under controlled dependency failures.
4. Verify the dashboard is denied to non-global actors and scoped admin accounts without the existing audit permission.
5. Configure explicit alert recipients and prove in-app delivery; configure an external paging destination and prove out-of-band delivery.
6. Confirm the legal/business retention schedule for the append-only audit ledger and test the eventual deletion process against backups and replicas.
7. Run the audit-ledger verifier against a production-like dataset and confirm runtime/load bounds.
8. Trigger controlled HTTP 5xx, queue failure, storage transfer failure and authentication anomaly scenarios; verify incident records and recovery.

No production secret, external monitoring account or deployment setting is changed by this code. The branch must not be merged until CI and the remaining authorization/trace integration checks pass.
