# FARAST Storage Core

## Application contract

Application modules must use `App\\Services\\Storage\\StorageManager`; they must not select a provider, region, bucket, or drive themselves.

- Critical files route only to `iran_primary`.
- Non-critical files route only to `foreign_primary`.
- If no healthy target is available, files are staged on the private local disk and registered as `pending_transfer`.
- `storage:transfer-pending` moves staged files after a healthy target is available.
- Backups are written to `foreign_backup`; `iran_backup` is an optional second destination.

## S3-compatible host connection JSON

```json
{
  "driver": "s3",
  "name": "Primary object storage",
  "provider": "Provider name",
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

Use a separate API credential with only the required bucket permissions. The endpoint must use HTTPS and certificate verification remains enabled.

## Required environment

- `FARAST_BACKUP_ENCRYPTION_KEY`: a dedicated high-entropy secret, kept outside the database and backup files.
- `FARAST_ENABLE_IRAN_BACKUP=true`: enable a second weekly copy in the Iranian backup drive.
- Configure at least one healthy volume in `foreign_backup` before enabling the weekly backup schedule.

## Operational commands

- `php artisan storage:transfer-pending --limit=100`
- `php artisan storage:migrate-legacy-product-assets --limit=100`
- `php artisan storage:migrate-legacy-editor-assets --limit=100` (resumable migration of existing private editor assets to foreign storage, including document and version resource references) (one-time, resumable migration of existing public product covers/gallery images to the Iranian managed drive)
- `php artisan storage:backup-weekly`
- `php artisan storage:check-volumes`
- `php artisan storage:cleanup-deferred-deletes`
- `php artisan storage:verify-backup <run-id>`
- `php artisan storage:restore-backup <run-id> --confirm` (destructive recovery; leaves the site in maintenance mode)
- `php artisan queue:work backups --queue=backups --timeout=14400 --tries=1` (required for the manual backup button)

The Laravel scheduler and queue worker must be running. Weekly backups are encrypted with ZIP AES-256, downloaded again after upload for SHA-256 verification, and retained; automatic pruning is intentionally disabled. A manual backup request is queued and appears in the backup history. The verification command checks the remote checksum, encrypted manifest, and database stream. Restore first verifies the archive, restores the database and local files, and re-stages remote managed objects so the transfer engine can place critical files back on Iran and non-critical files back abroad. It intentionally leaves maintenance mode enabled; inspect the recovered application, then run `php artisan up`. Keep the original Laravel `APP_KEY` and `FARAST_BACKUP_ENCRYPTION_KEY` safely available for disaster recovery.

## Safety notes

Do not delete source files until the destination upload, checksum validation, and database reference update have succeeded. Do not route critical files to foreign storage as a fallback. Provider capacity and object-count limits are enforced when the provider reports them.
