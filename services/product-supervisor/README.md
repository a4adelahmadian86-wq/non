# FARAST Product Supervisor

An isolated, stateless product-quality review service. It is separate from the existing voice/AI gateway and never publishes a product.

## Provider routing

| Task | Provider | Data sent |
|---|---|---|
| Product copy, extracted PDF/DOCX/TXT content, SEO and content quality | Gemini | Minimized metadata and locally extracted, redacted text only |
| Product image understanding | Groq Vision | Resized JPEG derivative, not the original image bytes |
| Audio transcription | Groq Whisper | Uploaded audio bytes |
| Optional audio provider | Gladia | Disabled; no Gladia request is made |

The original PDF, DOCX, TXT or ZIP is never uploaded to Gemini. ZIP files are inspected as archives; their entries are not extracted or executed. MP4/MOV, WebM and AVI video containers are recognized. When bundled FFmpeg can decode them, only a short audio track and one resized frame are extracted locally; the original video is never sent to a provider. Scanned PDFs without an extractable text layer receive a warning rather than a fabricated review.

## File safety and privacy

- Uploads are processed in memory and are not persisted by this service. A temporary file is used only when a local ClamAV binary is available; it is removed when scanning completes.
- Maximum upload size is 25 MiB. Request bodies are bounded. File type is determined from content signatures, not just the supplied extension or MIME type.
- Executables and scripts are rejected. ZIP archives have entry-count, expanded-size, compression-ratio, encryption and path-traversal checks. DOCX is parsed only after archive checks. PDF page count and extracted text are bounded. Images are decoded, dimension-checked and resized before provider submission.
- Common private keys, bearer tokens, API keys, email addresses and phone-like values are redacted from extracted text before Gemini. Redaction is best-effort and is not a guarantee that all personal or confidential information is removed.
- If ClamAV is not installed or available, the response says `manual_scan_required`; the file is never described as malware-clean. Configure a maintained scanner in the runtime before treating file uploads as security-cleared.
- A successful AI review is advisory only. The endpoint always returns `publication_authorized: false` and `automated_publication: false`.
- AI providers may process data according to the user's account tier and provider settings. For confidential content, use a Gemini API tier whose terms exclude training on submitted data, and enable Groq Zero Data Retention (ZDR) in the Groq console before adding its key. Never assume that a free tier is confidential.
- Gladia remains disabled because its free tier/data-retention terms are not appropriate as a default path for sensitive product uploads.

## Deploy

The repository root `render.yaml` defines a separate Render web service named `farast-product-supervisor`. Render generates `SUPERVISOR_API_TOKEN`. Configure these secrets only in the Render service's Environment page:

- `GEMINI_API_KEY` — required for text review.
- `GROQ_API_KEY` — optional; enables image review and audio transcription. Enable ZDR in Groq first.

Non-secret model settings are `GEMINI_MODEL`, `GROQ_VISION_MODEL` and `GROQ_AUDIO_MODEL`. Do not commit keys, put them in frontend code, or send them through chat.

Free Render instances may sleep when idle and have usage/availability limits. A timeout or provider outage is a failed/incomplete review, never an approval.

## API

- `GET /health`: public liveness/configuration status; does not disclose secrets.
- `POST /v1/reviews/products`: authenticated product metadata review.
- `POST /v1/reviews/files`: authenticated multipart file inspection. Fields: `file`, `product_id`, `product_title`, `product_type` (`digital_file` or `course`), optional `category_name`, `tags_json`, and `description`.

Both review endpoints require `Authorization: Bearer <SUPERVISOR_API_TOKEN>`. File review returns detected type, extraction counts, provider status, warnings, antivirus status, and structured findings. It never authorizes publication.

## Local run

```bash
python -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
export SUPERVISOR_API_TOKEN='local-only-secret'
export GEMINI_API_KEY='your-own-key'
export GROQ_API_KEY='your-own-key'
uvicorn main:app --reload --port 8091
```

Run unit tests with `python -m unittest discover -s tests`.
