# Product document ingestion and shared-host operation

## Data model

- `store_product_ingestions`: short-lived, authenticated quarantine sessions. A staged file expires after 30 minutes unless explicitly confirmed.
- `store_product_documents`: one processing profile per immutable product-file version, keyed by the source SHA-256.
- `store_product_document_chunks`: extracted text with source locators (PDF page, spreadsheet sheet/row, presentation slide/element, or text line).
- `store_product_document_artifacts`: versioned private derivatives such as sample files, reader assets and audio segments.

Binary originals and derivatives stay on private storage disks. SQL stores metadata, text, hashes, locators and private storage paths; do not place binary payloads in JSON or LONGTEXT columns.

The schema uses ordinary indexed InnoDB-compatible relations and JSON/LONGTEXT columns. MySQL 8 or a supported MariaDB release is the intended shared-host database. SQLite remains suitable for local tests only.

## Queue on inexpensive shared hosting

The upload-confirm action writes a document row and dispatches `ProcessProductDocument` to the `documents` queue. After successful semantic study, a separate `GenerateProductDocumentAudio` job creates a single WAV asset and stores per-chunk time offsets and source locators for reader synchronization. Configure Laravel's database queue driver and ensure the standard `jobs` and `failed_jobs` tables are migrated.

For cPanel or another cron-only host, run a short worker every minute (adjust the project path and PHP binary):

```sh
cd /home/ACCOUNT/public_html && /usr/local/bin/php artisan queue:work database --queue=documents,documents-audio,default --stop-when-empty --max-time=50 --tries=3
```

Do not keep a long-running worker alive on a host that terminates background processes. The stop-when-empty worker exits after the available batch and can be safely invoked again by cron.

Also register Laravel's scheduler every minute so expired quarantine files are removed hourly:

```sh
* * * * * cd /home/ACCOUNT/public_html && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```


## Current processing boundaries

- The first-party PHP gate performs bounded static checks and never executes an uploaded file. It is not a full antivirus engine and does not promise detection of every unknown malware family.
- ZIP members are listed from the central directory; archive contents are not extracted or executed during preflight.
- The AI service currently accepts up to 25 MiB per source file and extracts up to 120,000 characters into source-located chunks and runs sequential 24,000-character Gemini review batches. Files larger than that remain stored with an explicit awaiting-provider state rather than repeatedly submitting a request that cannot succeed.
- AI review is advisory. It never authorizes publication.
- AI-selected PDF sample pages (including DOCX documents converted once through the existing PHPWord/Dompdf renderer) are rendered once by pypdf and stored as private versioned artifacts. TTS generation uses the configured FARAST voice TTS endpoint after semantic review; the combined WAV and segment-to-source offsets are stored once. DOCX conversion fidelity checks, audio/reader synchronization UI, multi-pass analysis for long documents, and full-content reader access controls are follow-on stages. The reader must consume stored artifacts and must not create them.
