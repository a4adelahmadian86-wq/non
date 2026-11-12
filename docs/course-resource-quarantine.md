# Course resource quarantine and confirmation

Course lesson resources use the same private first-party inspection boundary as digital product files:

1. The admin selects a supported file. It is written to a private quarantine path, not to the lesson's final resource list.
2. The PHP upload security service validates the extension, detected MIME, size, static structure, image dimensions, and bounded archive inventory. It does not execute uploaded content and is not a claim of full antivirus coverage.
3. The admin reviews the inspection summary, warnings, archive inventory, and SHA-256, then explicitly confirms or cancels.
4. Confirmation re-runs inspection against the staged bytes and requires the stored size and SHA-256 to match the first preflight.
5. Only then is a private lesson_resource record and its document record created, with an audit event. Processing is dispatched after commit.

Staging records are tied to the course lesson and admin who initiated them, expire after 30 minutes, and are cleaned by the existing product-ingestion pruning command. Confirmation cannot be replayed or redirected to another lesson.

The first-party upload inspection supports the same extension set as product ingestion. Files above the current 25 MiB supervisor limit can be stored safely after confirmation but remain awaiting_provider; they are not falsely reported as analyzed and cannot be manually retried until bounded large-file processing is implemented.
