# Product document ingestion and shared-host operation

## Data model

- `store_product_ingestions`: short-lived, authenticated quarantine sessions. A staged file expires after 30 minutes unless explicitly confirmed.
- `store_product_documents`: one processing profile per immutable product-file version, keyed by the source SHA-256.
- `store_product_document_chunks`: extracted text with source locators (PDF page, spreadsheet sheet/row, presentation slide/element, or text line).
- `store_product_document_pages`: normalized reader pages/sections and their source locators.
- `store_product_document_artifacts`: versioned private derivatives such as reader assets and the single generated narration WAV.

Binary originals and derivatives stay on private storage disks. SQL stores metadata, text, hashes, locators and private storage paths; do not place binary payloads in JSON or LONGTEXT columns.

The schema uses ordinary indexed InnoDB-compatible relations and JSON/LONGTEXT columns. MySQL 8 or a supported MariaDB release is the intended shared-host database. SQLite remains suitable for local tests only.

## Queue on inexpensive shared hosting

The upload-confirm action writes a document row and dispatches `ProcessProductDocument` to the `documents` queue. After extraction and source-locator persistence, `GenerateProductDocumentAudio` is dispatched to the `default` queue. The database queue driver must be enabled and the standard `jobs` and `failed_jobs` tables must be migrated.

The application scheduler starts a bounded queue worker every minute for `documents,documents-audio,default` by default. It exits when the queues are empty, uses a 900-second worker/job timeout, and prevents overlapping scheduler launches for 20 minutes. The database connection's `retry_after` is 960 seconds, so a reserved job is not made visible to another worker before the maximum job timeout expires. Set `QUEUE_SCHEDULED_WORKER=false` when a persistent queue supervisor already consumes the queues; then use that supervisor as the only worker.

For cPanel or another cron-only host, the required cron entry is the Laravel scheduler (adjust the project path and PHP binary):

```sh
* * * * * cd /home/ACCOUNT/public_html && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Do not add a second cron-driven `queue:work` command when using the scheduler-managed worker. If a persistent queue supervisor is already configured, set `QUEUE_SCHEDULED_WORKER=false` before deploying so the scheduler does not launch another worker.

## Current processing and privacy boundaries

- The first-party PHP preflight performs bounded structural/static checks and never executes an uploaded file. It is not a full antivirus engine and does not promise detection of every unknown malware family.
- PDF text extraction currently has a 300-page ceiling; the supervisor rejects larger PDFs rather than silently presenting a partial document as complete. The extraction pipeline also caps extracted text at 120,000 characters and source chunks at 300. These are explicit safety limits, not a claim that arbitrarily large documents are fully processed; larger-document batch processing remains a required extension before removing those limits.
- Archive inspection is bounded by entry count, expanded size, compression ratio and member size. Unsupported or unsafe structures are rejected; nested archives are not recursively executed or extracted during preflight.
- The product supervisor extracts bounded source text and retains source locators. The current file-content text-analysis path is Groq-gated and must not run unless `GROQ_ZDR_CONFIRMED` is explicitly enabled; without that confirmation, external text analysis remains unavailable. Raw file content is not sent to Gemini. The separate product-metadata review endpoint is not a substitute for file inspection.
- AI review is advisory and evidence-backed. It never authorizes publication. A missing, incomplete or failed AI review must not be represented as a successful security scan.
- Keep the original upload, extracted text, page records and derived artifacts private. Reader and audio delivery must verify product ownership/entitlement, immutable source hash and document readiness before returning content.

## Reader readiness and AI review

Reader readiness depends on complete extraction, persisted source chunks and successfully built reader pages—not on semantic AI review. When those deterministic stages succeed but optional AI review is unavailable or incomplete, the document remains `extracted`; an entitled buyer can still read it and receive the one-time stored narration. Truncated extraction, missing chunks or missing reader pages remain `partial` and must not unlock full reader/audio access. Knowledge summaries and AI findings remain marked incomplete until their separate review batches finish.

## One-time document narration and granular reader playback

The reader audio is generated only by the background `GenerateProductDocumentAudio` job after extraction and source locators are stored. It uses the isolated `farast-document-audio` Piper service, never the live assistant/voice gateway.

- One private full-document WAV is stored per source hash and narration artifact version (`audio_full_wav`, current version 3). The job is unique per document and reuses an existing artifact when it is already present.
- The synthesis service sends bounded sentence-sized pieces in source order. The job joins their PCM frames into one WAV and persists sentence/word timing metadata and source locators alongside the artifact.
- Provider-supplied word alignment is retained only when its text and timing data validate against the synthesized sentence. Otherwise, word offsets are marked as estimated; they must not be presented as phoneme-perfect alignment.
- Word, sentence, paragraph, phrase-selection and page playback are ranges/seeks over the stored WAV. Reader clicks do not call a TTS provider, and selected text is not sent to a synthesis service at interaction time.
- A new source hash or explicit narration-version change may create a new artifact. Reopening the reader or clicking a text target does not regenerate the full-document audio.
- Configure Laravel with `FARAST_DOCUMENT_AUDIO_URL` (the dedicated HTTPS `/v1/synthesize` endpoint) and `FARAST_DOCUMENT_AUDIO_TOKEN`. Configure the isolated service with its separate `DOCUMENT_AUDIO_API_TOKEN`. Never commit either secret or expose it in client-side code or logs.
