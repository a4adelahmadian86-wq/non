# FARAST Product Supervisor

An isolated, stateless product-quality review service. It is separate from the existing voice/AI gateway and never publishes a product.

## Provider routing

| Task | Primary free provider | Data sent |
|---|---|---|
| Product metadata, SEO and review of persisted reports | Cloudflare Workers AI GPT-OSS 120B | Redacted product metadata plus bounded DB summaries, findings and short evidence-backed facts; never raw source chunks in this endpoint |
| Extracted file text and content quality | Cloudflare Workers AI GPT-OSS 20B | Redacted extracted text in bounded batches |
| Product image understanding | Cloudflare Workers AI Moondream 3.1 | Resized image plus redacted product context |
| Audio transcription | Cloudflare Workers AI Whisper Large V3 Turbo | Extracted audio bytes; no original filename |
| Optional product-metadata fallback | Gemini | Used only when Cloudflare Workers AI is not configured; never receives raw file text or original files |
| Optional text/audio fallback | Groq | Used only when account-level ZDR is enabled and `GROQ_ZDR_CONFIRMED=true`; image analysis is not routed to Groq |
| Optional audio provider | Gladia | Disabled; no Gladia request is made |

Cloudflare Workers AI is the preferred inference provider. The selected models are `@cf/openai/gpt-oss-120b` for compact final product metadata review, `@cf/openai/gpt-oss-20b` for bulk file-text analysis, `@cf/moondream/moondream3.1-9B-A2B` for images and `@cf/openai/whisper-large-v3-turbo` for audio. Its Free plan includes 10,000 neurons per day across the account's Workers AI usage (shared with the site's assistant/editor if they use the same account). GPT-OSS 120B uses more neurons, so it is reserved for the short final metadata review; GPT-OSS 20B handles bulk document text. When the allowance is exhausted, the primary endpoint returns a retryable rate-limit response; Laravel may use the domestic offline node for extraction only, then the scheduled recovery command requeues the full AI review after the primary provider is ready. No automatic paid-provider fallback is performed after a Cloudflare error.

The service never sends private user profiles, behavioral observations or account memory to these provider calls. Product metadata and product-file content are separate from those user records. Cloudflare Workers AI inference is not used to train models and is processed under Cloudflare's Workers AI data-handling policy. The later AI Search index is a separate persistent knowledge store; only verified product knowledge and explicitly approved, sanitized experiences may be indexed there.

## File safety and privacy

- Uploads are handled as request-scoped temporary data and are not persisted by this service. The ASGI upload spool and the optional ClamAV temporary file are closed/removed after processing.
- Maximum upload size is 25 MiB. Request bodies are bounded. File type is determined from content signatures, not just the supplied extension or MIME type.
- Executables and scripts are rejected. ZIP archives have entry-count, expanded-size, compression-ratio, encryption and path-traversal checks. DOCX, XLSX and PPTX are parsed only after archive checks; spreadsheet and slide extraction are capped. PDF page count and extracted text are bounded. Images are decoded, dimension-checked and resized before provider submission.
- Common private keys, bearer tokens, API keys, email addresses and phone-like values are redacted from extracted text before model submission. Redaction is best-effort and is not a guarantee that all personal or confidential information is removed.
- Laravel invokes this endpoint only after its primary upload scanner has marked the asset clean. If the optional secondary ClamAV scanner in this service is unavailable, the response says `upstream_scan_verified` and separately reports `antivirus_status: not_configured`; it does not pretend a second scan ran. An infected result from the secondary scanner is rejected.
- A successful AI review is advisory only. The endpoint always returns `publication_authorized: false` and `automated_publication: false`.
- Cloudflare Workers AI is the default when configured. Gemini is restricted to product metadata and compact database reports and is used only when Cloudflare is not configured. Groq file processing is disabled unless the operator has independently enabled account-level ZDR and then explicitly sets `GROQ_ZDR_CONFIRMED=true`. That variable is only an operator assertion, not proof supplied by Groq. Never assume a free tier is confidential.
- Gladia remains disabled because its free tier/data-retention terms are not appropriate as a default path for sensitive product uploads.

## Deployment model

The service is stateless. Uploaded files are inspected in request-scoped temporary storage and removed after processing; no product files or customer data are kept on the container filesystem. The Laravel queue remains the durable source of truth and retries when this worker is unavailable.

### External primary endpoint: existing Render Blueprint

The repository already contains `render.yaml` with the `farast-product-supervisor` service. Reuse that definition; do not create another supervisor implementation.

The Render Free web service is the current no-card external-host candidate:
- 0.1 CPU, 512 MiB RAM and 750 free instance hours per workspace per calendar month.
- Free web services sleep after 15 minutes without traffic and wake on the next request; cold start can take about a minute.
- Free services have no persistent disk and are not covered by a production SLA. This is acceptable only because the supervisor is stateless and Laravel keeps the durable jobs and source data.
- Render's official first-deploy guide says no payment is required. The free tier is a best-effort endpoint, not an uptime guarantee.

The existing `render.yaml` configures Cloudflare Workers AI as the preferred inference provider and keeps automatic deployment disabled. Configure secrets in the Render dashboard only:
- `SUPERVISOR_API_TOKEN`: generated by the Blueprint; copy it into Laravel's primary supervisor token setting.
- `CLOUDFLARE_ACCOUNT_ID` and `CLOUDFLARE_API_TOKEN`: dedicated Workers AI token for this service; no R2 permissions are needed.
- `GEMINI_API_KEY`: optional metadata-only fallback.
- `GROQ_API_KEY`: optional file fallback only if account-level ZDR is enabled and `GROQ_ZDR_CONFIRMED=true`.

The model identifiers and gateway ID are set in `render.yaml`; do not put credentials in Git. After deploying, verify `/health` and authenticated `/v1/ready`, then run a test product and a test file before configuring the Production primary URL.

Back4App Containers is not selected for a persistent endpoint: Back4App's own September 2026 engineering guide says the free URL expires 60 minutes after deployment. Northflank and Koyeb are excluded because their current official signup/billing guidance requires a valid payment method. Hugging Face's free CPU hardware does not permit creating a Docker/Gradio compute Space on a free plan.

Sources:
- https://render.com/docs/your-first-deploy
- https://render.com/docs/free
- https://render.com/changelog/free-web-services-now-remain-active-while-receiving-websocket-messages
- https://www.back4app.com/blog/deploy-node-app-dockerfile-back4app
- https://northflank.com/pricing
- https://www.koyeb.com/docs/faqs/pricing
- https://huggingface.co/docs/hub/spaces-overview

### Queue recovery after offline processing

When the domestic Runflare node returns `processing_mode=offline_structural_only`, Laravel saves the extracted content and marks the document as `extracted` or `partial`, not semantically complete. The existing queue is not allowed to call this a successful AI review.

The scheduled `farast:retry-offline-product-documents` command checks only due offline documents. It probes the primary endpoint's authenticated `/v1/ready` and re-dispatches the existing `ProcessProductDocument` job only when a provider-assisted primary is ready. If the primary is still unavailable or the free quota is exhausted, the document remains recoverable and receives another six-hour retry delay. No second queue or second document pipeline is created.

## Domestic Runflare fallback

The same Docker image can run as a temporary domestic fallback node. It is not a VPN, proxy, tunnel, or data store. Runflare's free tier is limited to three months, has no backup, and is explicitly unsuitable for production-critical data. Runflare's terms prohibit VPN/proxy/tunneling workloads.

For the Runflare instance, set only:
- `SUPERVISOR_API_TOKEN`: a new, dedicated random token, different from the primary node.
- `SUPERVISOR_OFFLINE_MODE=true`.

Do not configure Cloudflare, Gemini or Groq credentials on this node. Offline mode deliberately skips all external model calls:
- file reviews perform bounded local file inspection and text extraction, return content chunks and warnings, and never authorize publication;
- product metadata reviews return `needs_changes` with an explicit manual-review warning, never an AI approval;
- after international connectivity returns, Laravel's existing queue can retry the full review through the primary endpoint.

The Laravel `ProductSupervisorClient` now fails over to the next configured endpoint on connection errors, HTTP 408/425/429 and 5xx responses. The primary service maps upstream provider 401/403 access restrictions to 503 so the client can try the next endpoint. It does not fail over on ordinary validation errors such as 400/413/422.

### Runflare setup and renewal

1. Create a free project in the Iran cluster and one Docker service using the existing `services/product-supervisor/Dockerfile`.
2. Connect the existing public GitHub repository through Runflare's GitHub OAuth app; select branch `main`, directory/build context `services/product-supervisor`, and enable auto-pull.
3. Set the two environment variables above in Runflare's service settings. Do not attach a database or persistent volume.
4. Verify public `/health` reports `offline_mode: true` and authenticated `/v1/ready` reports `processing_mode: offline_structural_only`.
5. Set the new URL and its separate token as Laravel's Product Supervisor fallback endpoint only after the health/readiness test.

When the free service expires and is deleted, the new project and Docker service must first be recreated in the Runflare panel. The included `runflare-deploy.cmd` then deploys the existing Docker item from Windows CMD. Runflare's CLI documentation currently requires a project/service item to exist before `runflare deploy`; it does not document fully automatic creation of a deleted project. No data recovery is needed from Runflare because it stores no source-of-truth data.

Official Runflare documentation:
- https://runflare.com/free-cloud-hosting/
- https://docs.runflare.com/docker/docker-create-app/
- https://docs.runflare.com/docker/deploy-github/
- https://docs.runflare.com/docker/deploy-cli/

## Provider secrets

The service never needs a frontend key. Keep all provider keys and the dedicated supervisor token in the worker's secret environment only. Do not commit keys, put them in frontend code, or send them through chat.

## API

- `GET /health`: public liveness/configuration status; does not disclose secrets.
- `POST /v1/reviews/products`: authenticated product metadata review.
- `POST /v1/reviews/files`: authenticated multipart file inspection. Fields: `file`, `product_id`, `product_title`, `product_type` (`digital_file` or `course`), `primary_scan_status=clean` (asserted by the authenticated Laravel caller after its upload scanner succeeds), optional `category_name`, `tags_json`, and `description`.

Both review endpoints require `Authorization: Bearer <SUPERVISOR_API_TOKEN>`. File review returns detected type, extraction counts, provider status, warnings, antivirus status, and structured findings. It never authorizes publication.

## Local run

The Docker image and local development use Python 3.12; `.python-version` pins the local interpreter for prebuilt Pydantic Core wheels.

```bash
python -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
export SUPERVISOR_API_TOKEN='local-only-secret'
export CLOUDFLARE_ACCOUNT_ID='your-account-id'
export CLOUDFLARE_API_TOKEN='your-workers-ai-token'
# Optional fallbacks:
# export GEMINI_API_KEY='your-own-key'
# export GROQ_API_KEY='your-own-key'
# Set only after enabling account-level Groq ZDR:
# export GROQ_ZDR_CONFIRMED=true
uvicorn main:app --reload --port 8091
```

Run unit tests with `pytest -q`.
