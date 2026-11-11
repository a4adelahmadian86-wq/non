# FARAST Storage Core

## Application contract

Application modules must use `App\\Services\\Storage\\StorageManager`; they must not select a provider, region, bucket, or drive themselves. Storage Core, its routing/transfer/backup/health logic, the queue and scheduler are first-party FARAST code. The only remote storage dependency is the host API endpoint and credentials explicitly entered by the administrator.

- Critical files route only to `iran_primary`.
- Non-critical files route only to `foreign_primary`.
- If no healthy target is available, files are staged on the private local disk and registered as `pending_transfer`.
- `storage:transfer-pending` moves staged files after a healthy target is available.
- Backups are written to `foreign_backup`; `iran_backup` is an optional second destination.

## Initial module routing policy

| Application asset | Classification | Logical drive | Retention |
| --- | --- | --- | --- |
| Product downloads, product covers, and gallery images | critical | iran_primary | Until the owning product/file is removed |
| User editor files and persistent editor image assets | non_critical | foreign_primary | Indefinite |
| Ticket and support attachments | non_critical | foreign_primary | Indefinite unless explicitly removed with the owning record |
| Internal messenger attachments | non_critical | foreign_primary | Indefinite unless explicitly removed with the owning message |
| Weekly application/database archives | backup | foreign_backup | Retained; pruning is disabled |
| Optional second backup copy | backup | iran_backup | Enabled only by configuration |

Uploads that need security inspection may briefly enter the private local quarantine disk. This disk is internal staging on the FARAST application host, not an additional logical drive or a storage provider. The module then hands the file to StorageManager; only StorageManager chooses the drive and host. A staged file remains available locally until the transfer worker confirms the remote copy and updates its source reference. The staging disk is not used as a permanent success state.

## S3-compatible host connection JSON

```json
{
  "driver": "s3",
  "region": "eu-central-1",
  "endpoint": "https://s3.example.com",
  "bucket": "farast-files",
  "credentials": {
    "key": "ACCESS_KEY",
    "secret": "SECRET_KEY",
    "use_path_style_endpoint": true
  }
}
```

Use a separate API credential with only the required bucket permissions. The endpoint must use HTTPS and certificate verification remains enabled. FARAST sends S3-compatible API requests directly to this exact administrator-supplied endpoint; it does not proxy through a storage SaaS, external gateway, hosted file-management service, or another intermediary. The HTTP client is a PHP library running inside FARAST, not a remote service. The current built-in remote protocol is S3-compatible API; a host exposing a different proprietary API needs a first-party adapter implemented inside FARAST against that host's published API contract. Do not enter arbitrary API credentials and assume protocol compatibility.

## Required environment

- `FARAST_BACKUP_ENCRYPTION_KEY`: a dedicated high-entropy secret, kept outside the database and backup files.
- `FARAST_ENABLE_IRAN_BACKUP=true`: enable a second weekly copy in the Iranian backup drive.
- Configure at least one healthy volume in `foreign_backup` before enabling the weekly backup schedule.

## Operational commands

- `php artisan storage:transfer-pending --limit=100`
- `php artisan storage:migrate-legacy-product-assets --limit=100` (one-time, resumable migration of existing public product covers/gallery images to the Iranian managed drive)
- `php artisan storage:migrate-legacy-editor-assets --limit=100` (resumable migration of existing private editor assets to foreign storage, including document and version resource references)
- `php artisan storage:backup-weekly`
- `php artisan storage:check-volumes`
- `php artisan storage:cleanup-deferred-deletes`
- `php artisan storage:verify-backup <run-id>`
- `php artisan storage:restore-backup <run-id> --confirm` (destructive recovery; leaves the site in maintenance mode)
- `php artisan queue:work backups --queue=backups --timeout=14400 --tries=1` (optional dedicated long-running worker; the built-in scheduled worker also processes this database queue when `QUEUE_SCHEDULED_WORKER=true`)

The Laravel scheduler must be running. When `QUEUE_SCHEDULED_WORKER=true` (the default), FARAST launches a separate worker for the database-backed `backups` queue every minute; the worker exits when idle and supports long-running backup jobs. A dedicated continuously running worker may be used instead. The `backups` queue is a dedicated queue on FARAST's own database connection; no external queue service is required. Weekly backups are encrypted with ZIP AES-256, downloaded again after upload for SHA-256 verification, and retained; automatic pruning is intentionally disabled. A manual backup request is queued and appears in the backup history. The verification command checks the remote checksum, encrypted manifest, and database stream. Restore first verifies the archive, restores the database and local files, and re-stages remote managed objects so the transfer engine can place critical files back on Iran and non-critical files back abroad. It intentionally leaves maintenance mode enabled; inspect the recovered application, then run `php artisan up`. Keep the original Laravel `APP_KEY` and `FARAST_BACKUP_ENCRYPTION_KEY` safely available for disaster recovery.

## International outage readiness

Iranian file storage protects file reads and writes only when the application can still reach that Iranian endpoint. To keep the whole site available during an international outage, the application runtime, primary database, session/cache/queue services, DNS resolution, authentication dependencies, and any required CDN or payment integrations must also be reachable through domestic infrastructure or have a tested local fallback. Storage Core does not by itself make an overseas application server or an external dependency used by another application module available.

## Safety notes

Do not delete source files until the destination upload, checksum validation, and database reference update have succeeded. Do not route critical files to foreign storage as a fallback. Provider capacity and object-count limits are enforced when the provider reports them.
