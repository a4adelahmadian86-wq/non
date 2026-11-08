# FARAST Document Intelligence

## Upload lifecycle

1. The admin UI accepts up to ten files per intake. A page-wide drag/drop target forwards dropped files to the same intake form.
2. LocalFileInspector reads file signatures, MIME, size, PDF page count, image dimensions, Office package metadata and ZIP entry metadata. It does not invoke AI, execute files or extract archive entries to the filesystem.
3. The staged file is stored on the private disk for 30 minutes. The modal shows the local inspection report before the user confirms.
4. StaticFileSafetyScanner applies bounded static policy checks on confirmation: executable signatures/extensions, embedded PHP, PDF active-content markers, ZIP traversal paths, symlinks, expansion/ratio limits and Office macro containers.
5. The file is moved to the private product disk, hashed again and registered in store_document_records. AI review is queued only after the transaction commits.
6. ProcessDocumentKnowledgeJob extracts bounded text and stores source-anchored chunks and evidence-backed facts. PDF pages and DOCX converted to PDF can produce a separate sample PDF through a deterministic page-copy endpoint. The model only selects page numbers; it never creates the sample file.
7. GenerateDocumentAudioTrackJob creates one Persian WAV track from stored text chunks and stores timing-to-source anchors. The reader streams it only for an entitled reader_only product.

## Data model

- store_file_intakes: temporary upload sessions and preflight reports.
- store_document_records: versioned document identity, processing status and analysis metadata.
- store_document_chunks: extracted text with page/section anchors and content hashes.
- store_document_facts: short facts with evidence quotes, page references and confidence.
- store_document_samples: immutable sample artifact and AI-selected page list.
- store_document_audio_tracks / store_document_audio_segments: one generated audio asset and synchronized source segments.
- store_product_previews.document_id and source_sha256 bind reader artifacts to the exact source version.

DocumentKnowledgeContextBuilder is the shared Laravel context provider. Internal AI services can request /internal/store/products/{product}/document-knowledge with the existing Product Supervisor bearer token. It returns source filenames, hashes, chunks, facts and provenance. It is not a public browser API.

## Shared-host deployment

Use MySQL/MariaDB and Laravel's database queue; no Redis, Elasticsearch or vector database is required.

- Set QUEUE_CONNECTION=database.
- Keep FILESYSTEM_DISK=private (or the configured private disk).
- Run migrations with the normal deployment process.
- Configure the existing PRODUCT_SUPERVISOR_URL and PRODUCT_SUPERVISOR_TOKEN.
- Configure the existing FARAST_VOICE_TTS_URL and FARAST_VOICE_TTS_TOKEN for the Persian Piper endpoint.
- Configure GEMINI_API_KEY on the isolated Product Supervisor service.
- Only set GROQ_ZDR_CONFIRMED=true after the account owner verifies Zero Data Retention in the Groq console. Without this explicit confirmation, Groq image/audio calls remain disabled.
- Add a cPanel cron entry every five minutes to run: php artisan queue:work database --queue=document-analysis,document-audio --stop-when-empty --max-time=240 --timeout=240 --tries=3. The worker must be allowed to run long enough for a document and its audio job to finish.

## Security boundaries and current supported scope

The internal scanner is a lightweight static policy gate, not a signature-based antivirus and not a malware-free guarantee. It never executes uploaded content. Files with unsupported or ambiguous structures must be rejected or held for manual review.

Current UI allowlist: PDF, ZIP, DOCX, XLSX, PPTX, JPG/JPEG, PNG, WebP, GIF, TXT, Markdown, CSV, MP3, M4A, WAV, MP4 and WebM. RAR/7z and legacy macro-enabled Office formats are intentionally not accepted by this no-extra-parser design.

PDF and DOCX have page-aware sample generation. Other formats are indexed where their parser supports them, but their dedicated sample renderers and rich chart/table/footnote anchors remain follow-up work. Documents beyond extraction limits are marked partial, never represented as fully indexed.

Generated audio is private and streamed through an entitlement-checked reader route. controlsList=nodownload is only a UI hint; authorization and private storage are the actual access controls.
