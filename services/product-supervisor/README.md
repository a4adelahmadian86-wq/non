# FARAST Product Supervisor

An isolated, stateless product-quality review service. It is separate from the existing voice/AI gateway and never publishes a product.

## Provider routing

| Task | Provider | Data sent |
|---|---|---|
| Product metadata, SEO and review of persisted database reports | Gemini | Product metadata plus bounded DB summaries, findings and short evidence-backed facts; never raw document chunks |
| Extracted file text and content quality | Groq Text | Only when `GROQ_ZDR_CONFIRMED=true` and the Groq account's ZDR is actually enabled; otherwise no file text is sent |

| Product image understanding | Groq Vision | Only when the same explicit ZDR gate is enabled; otherwise no image is sent |
| Audio transcription | Groq Whisper | Only when the same explicit ZDR gate is enabled; otherwise no audio is sent |
| Optional audio provider | Gladia | Disabled; no Gladia request is made |

Gemini never receives the original file, extracted full text, or raw document chunks. File-text review is a separate Groq path and is fail-closed unless `GROQ_ZDR_CONFIRMED=true` is set after account-level Zero Data Retention is enabled in Groq. Legacy binary Office formats (.doc/.xls/.ppt), encrypted documents and unsupported file types are rejected. ZIP files are inspected as archives; their entries are not extracted or executed. MP4/MOV, WebM and AVI video containers are recognized. When bundled FFmpeg can decode them, only a short audio track and one resized frame are extracted locally; the original video is never sent to a provider. Scanned PDFs without an extractable text layer receive a warning rather than a fabricated review.

## File safety and privacy

- Uploads are handled as request-scoped temporary data and are not persisted by this service. The ASGI upload spool and the optional ClamAV temporary file are closed/removed after processing.
- Maximum upload size is 25 MiB. Request bodies are bounded. File type is determined from content signatures, not just the supplied extension or MIME type.
- Executables and scripts are rejected. ZIP archives have entry-count, expanded-size, compression-ratio, encryption and path-traversal checks. DOCX, XLSX and PPTX are parsed only after archive checks; spreadsheet and slide extraction are capped. PDF page count and extracted text are bounded. Images are decoded, dimension-checked and resized before provider submission.
- Common private keys, bearer tokens, API keys, email addresses and phone-like values are redacted from extracted text before any permitted Groq file-review request. Redaction is best-effort. Redaction is best-effort and is not a guarantee that all personal or confidential information is removed.
- Laravel invokes this endpoint only after its primary upload scanner has marked the asset clean. If the optional secondary ClamAV scanner in this service is unavailable, the response says `upstream_scan_verified` and separately reports `antivirus_status: not_configured`; it does not pretend a second scan ran. An infected result from the secondary scanner is rejected.
- A successful AI review is advisory only. The endpoint always returns `publication_authorized: false` and `automated_publication: false`.
- Gemini is restricted to product metadata and compact database reports. Groq file processing is disabled unless the operator has independently enabled account-level ZDR and then explicitly sets `GROQ_ZDR_CONFIRMED=true`. That variable is only an operator assertion, not proof supplied by Groq. Never assume a free tier is confidential.
- Gladia remains disabled because its free tier/data-retention terms are not appropriate as a default path for sensitive product uploads.

## Deployment model

The service is stateless. Uploaded files are inspected in request-scoped temporary storage and removed after processing; no product files or customer data are kept on the container filesystem. The Laravel queue remains the durable source of truth and retries when this worker is unavailable.

A Dockerfile is provided for a no-card free-container pilot on Back4App:

- Published free limits: 0.25 vCPU, 256 MiB RAM and 100 GB outbound transfer.
- The free tier is intended for testing and learning, not a production SLA.
- CI builds the image and has successfully inspected a synthetic 24 MiB text upload with Docker constrained to 256 MiB RAM and 0.25 vCPU.
- This test does not prove every file format will fit those limits. The service's existing 25 MiB upload ceiling remains; 2 GiB processing is not claimed or enabled.
- File-review requests are serialized to reduce memory spikes. A busy worker returns HTTP 429 so Laravel can retry instead of losing the job.

### Back4App pilot deployment

1. Create a Back4App Containers application and connect the public repository `a4adelahmadian86-wq/non`.
2. Select the `main` branch, set the application directory/build context to `services/product-supervisor`, and use its `Dockerfile`.
3. Expose port `8000`. Do not attach a persistent disk; this service must remain stateless.
4. Add secrets only in the Back4App environment settings:
   - `SUPERVISOR_API_TOKEN`: a new, dedicated random secret.
   - `GEMINI_API_KEY`: optional; used only for product metadata and compact persisted-report review, never raw file text.
   - `GROQ_API_KEY`: optional; file text, image and audio review stay disabled unless account-level ZDR is enabled and `GROQ_ZDR_CONFIRMED=true` is explicitly set.
5. Deploy and verify `/health`, then verify authenticated `/v1/ready`.
6. In the Laravel hosting environment, set `FARAST_PRODUCT_SUPERVISOR_URL` to the Back4App HTTPS service URL and `FARAST_PRODUCT_SUPERVISOR_TOKEN` to the exact dedicated token. Rebuild Laravel's configuration cache through the hosting provider's supported workflow.

Do not change the site's existing production endpoint until the Back4App deployment has passed the live health/readiness checks and a real test product has completed successfully. No service is deployed by this repository change.

## Provider secrets

The service never needs a frontend key. Keep all provider keys and the dedicated supervisor token in the worker's secret environment only. Do not commit keys, put them in frontend code, or send them through chat.

## API

- `GET /health`: public liveness/configuration status; does not disclose secrets.
- `POST /v1/reviews/products`: authenticated product metadata review.
- `POST /v1/reviews/files`: authenticated multipart file inspection. Fields: `file`, `product_id`, `product_title`, `product_type` (`digital_file` or `course`), `primary_scan_status=clean` (asserted by the authenticated Laravel caller after its upload scanner succeeds), optional `category_name`, `tags_json`, and `description`.

Both review endpoints require `Authorization: Bearer <SUPERVISOR_API_TOKEN>`. File review returns detected type, extraction counts, provider status, warnings, antivirus status, and structured findings. It never authorizes publication.

## Local run

Render and local deployments use Python 3.12.11, pinned in `.python-version` to ensure prebuilt Pydantic Core wheels are available.

```bash
python -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
export SUPERVISOR_API_TOKEN='local-only-secret'
export GEMINI_API_KEY='your-own-key'
export GROQ_API_KEY='your-own-key'
# Set only after enabling account-level Groq ZDR:
# export GROQ_ZDR_CONFIRMED=true
uvicorn main:app --reload --port 8091
```

Run unit tests with `python -m unittest discover -s tests`.
