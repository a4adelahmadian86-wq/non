# FARAST — Phase 9: Observability, Operational Security and Incident Management

## Current implementation

This phase extends existing first-party assets; it does not introduce a parallel audit or storage subsystem.

- Global HTTP middleware: `App\\Http\\Middleware\\FarastObservability`.
- Request and correlation headers: `X-Request-Id` and `X-Correlation-Id`.
- Runtime configuration: `config/observability.php`.
- Existing audit writer: `App\\Services\\AuditLogService` and existing `audit_logs` table.
- Existing queue tables: `jobs` and `failed_jobs` when the configured queue driver uses the database.
- Existing storage inventory: `storage_volumes` and `storage_managed_files`.
- Existing admin authorization: `admin.section:audit` (global scope).

## Dashboard probes

The admin observability page reports bounded, read-only probes for database connectivity, queue backlog/failures, private storage directory writability, storage volume inventory, pending transfers and log directory writability/retention configuration.

A missing optional table is reported as an incomplete capability, not silently treated as a healthy external service. Probe exceptions are reported to Laravel's existing exception handler and are not exposed to the dashboard.

## Existing audit-ledger integrity

The append-only `farast_audit_events` ledger and its existing `AuditIntegrityService` remain the integrity source; this phase does not create another audit store. The verification command is:

```bash
php artisan farast:audit-ledger:verify
```

It exits non-zero and emits a critical, secret-free application log event when verification fails. Scheduling this command should be enabled only after the production scheduler cadence and database load are validated.

## Operational security rules

- Never put passwords, bearer tokens, cookies, session identifiers, API keys, recovery codes, raw request bodies or uploaded file contents in logs.
- Do not log query strings by default; URLs can contain reset tokens and other credentials.
- Treat inbound trace headers as untrusted input. Accept only bounded, single-line identifiers; generate replacements for invalid values.
- Keep admin health and audit views behind existing authorization and scope enforcement.
- Do not expose exception messages, database DSNs, filesystem paths or provider credentials to browser responses.
- Keep logs outside the public web root and use the configured daily log retention.
- Audit retention is not automatically pruned in this change: a retention schedule must be approved against business, legal and incident-response requirements before destructive deletion is enabled.

## Alerting and incident response — remaining operational integration

The application dashboard is not an external alerting system. Production still needs an operator-owned alert destination and thresholds for:
- sustained HTTP 5xx rate and latency;
- queue backlog age and failed-job growth;
- database connection failures;
- storage provider errors and transfer backlog;
- repeated authentication/authorization failures.

For each incident, use [the incident report template](incident-report-template.md) and record: incident ID, first-seen time, severity, affected service/tenant scope, request/correlation IDs, impact, mitigation, root cause, recovery verification and follow-up actions. Do not include secrets or unnecessary personal data.

## Acceptance tests still required

1. Trace one HTTP request through a queued job and verify the same correlation ID is present in request and worker logs.
2. Verify invalid and oversized trace headers are replaced.
3. Verify log context is cleared between consecutive requests and jobs in a long-running worker.
4. Verify logging failure does not fail the user request.
5. Verify database, queue, storage and log probes report degraded states when their dependencies are unavailable.
6. Verify only an actor with the existing global audit permission can access the dashboard.
7. Verify configured log retention and an approved audit retention/deletion process in a production-like environment.
8. Trigger a controlled 5xx and queue failure and verify an external alert is delivered.

No production service, secret, external monitoring account or deployment configuration is modified by this code change.
