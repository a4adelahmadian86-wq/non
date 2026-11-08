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

## Deploy

The repository root `render.yaml` defines a separate Render web service named `farast-product-supervisor`. Render generates `SUPERVISOR_API_TOKEN`. Configure these secrets only in the Render service's Environment page:

- `GEMINI_API_KEY` — required only for product metadata and compact persisted-report review; never used for file text.
- `GROQ_API_KEY` — optional; enables file text, image and audio review only after the ZDR gate is satisfied.
- `GROQ_ZDR_CONFIRMED` — leave unset until account-level ZDR is actually enabled in Groq; then set to `true`.
- `GROQ_TEXT_MODEL` — non-secret text model selection.

Non-secret model settings are `GROQ_TEXT_MODEL`, `GROQ_VISION_MODEL` and `GROQ_AUDIO_MODEL`. The Gemini model is pinned in code. Do not commit keys, put them in frontend code, or send them through chat.

Free Render instances may sleep when idle and have usage/availability limits. A timeout or provider outage is a failed/incomplete review, never an approval.

## Activate the dedicated Render service

This service is intentionally separate from the site's existing AI and voice gateway.

1. Open the [dedicated Render service](https://dashboard.render.com/web/srv-dbo0dgbrjlhs73a2vong) and select **Environment**.
2. Create a dedicated key in [Google AI Studio API Keys](https://aistudio.google.com/app/apikey) and add it as `GEMINI_API_KEY`. It is used only for product metadata and compact persisted database reports, never raw file text or chunks.
3. Optionally add `GROQ_API_KEY` for file text, image understanding and audio transcription. First enable account-level ZDR in Groq; only then set `GROQ_ZDR_CONFIRMED=true`. Without both, all Groq file/media calls remain disabled.
4. Keep the generated `SUPERVISOR_API_TOKEN` value; do not replace it with the site's voice token or another AI key.
5. Save the environment changes and manually deploy the service. Automatic deploys are disabled for this service.
6. Confirm `/health` identifies Gemini's role as product metadata/database-report review and shows Groq's privacy gate. Then use the authenticated `/v1/ready` endpoint to verify the service token.

### Connect Laravel to this exact service

Set these values in the Laravel application's hosting environment (not in frontend JavaScript and not in Git):

```dotenv
FARAST_PRODUCT_SUPERVISOR_URL=https://farast-product-supervisor-dhp5.onrender.com
FARAST_PRODUCT_SUPERVISOR_TOKEN=<the exact SUPERVISOR_API_TOKEN from this Render service>
FARAST_PRODUCT_SUPERVISOR_TIMEOUT=40
```

The Laravel token must match the generated Render token exactly. Do not use `FARAST_VOICE_TOKEN`, `FARAST_AI_GATEWAY_URL`, `GEMINI_API_KEY`, or any secret from the existing AI/voice service for this connection. After changing Laravel environment values, clear/rebuild the Laravel configuration cache using the hosting provider's supported terminal/deploy workflow.

The authenticated readiness probe can be checked without sending a product file:

```bash
curl -i https://farast-product-supervisor-dhp5.onrender.com/v1/ready \
  -H "Authorization: Bearer <SUPERVISOR_API_TOKEN>"
```

A successful response confirms the Render token is accepted and reports whether Gemini product review and ZDR-gated Groq file review are configured. It does not make a paid provider request.

Never paste API keys or the supervisor token into this chat. Enter them only in the corresponding hosting Environment pages.

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
