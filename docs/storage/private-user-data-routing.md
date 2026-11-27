# Private user data storage routing

This policy reuses the existing `StorageManager`, `StorageVolume`, `StorageManagedFile` and transfer scheduler. It does not introduce another storage service, database or queue.

## Routing policy

- Private user uploads, editor assets, support-ticket attachments and legacy copies of those files are classified as `critical`.
- `critical` content is routed only to the existing `iran_primary` drive.
- If no healthy Iranian volume is configured, the existing storage manager keeps the file in private local staging with `pending_transfer`; it must not spill a private file to `foreign_primary`.
- `non_critical` is reserved for content that is intentionally public or otherwise approved for foreign storage, such as public product assets.

The default classification for `adoptExistingPrivate` is now `critical`, so a new private-file caller cannot accidentally select foreign storage by omitting the classification.

## Covered paths

- User/editor file uploads through `UserFileController`
- Inline editor assets through `EditorController`
- Existing editor assets migrated by `MigrateLegacyEditorAssets`
- Support-ticket attachments created by `SupportTicketService`
- Legacy support attachments migrated by `SecureTicketAttachments`

## Existing files

Changing upload classification affects new uploads and files processed again by the legacy migration commands. It does **not** automatically move already-active `StorageManagedFile` records from `foreign_primary` to `iran_primary`.

Those existing records require a separate, checksum-verified migration after a healthy Iranian primary volume is configured. The migration must copy and verify the destination before updating references or deleting the source. This PR deliberately does not move or delete existing objects.

## Operational behavior

The existing `storage:transfer-pending` scheduler continues to move staged critical files to `iran_primary` when that volume becomes healthy. The existing storage metadata and source references remain authoritative; no parallel routing table is added.
