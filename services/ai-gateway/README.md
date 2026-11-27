# FARAST AI Gateway (Cloudflare Worker)

This is a stateless, server-side gateway for the existing Laravel AI-provider contract. Laravel calls only this gateway's `/v1/chat/completions`; the Worker holds the Cloudflare credentials and calls Workers AI. Provider credentials never enter browser code or the Laravel repository.

## Contract

- `GET /health`: public liveness only.
- `GET /ready`: confirms that required secrets and the account ID are configured.
- `POST /v1/chat/completions`: Bearer-authenticated OpenAI-compatible text/chat endpoint, pinned to GPT-OSS 20B.
- `POST /v1/chat/completions/review`: separate high-capability product-review endpoint, pinned to GPT-OSS 120B.
- `POST /v1/ai/run/vision`: authenticated, streamed JSON pass-through to the pinned Moondream 3.1 vision model.
- `POST /v1/ai/run/audio`: authenticated, streamed JSON pass-through to the pinned Whisper Large V3 Turbo transcription model.
- The Worker pins all three model IDs server-side, caps chat output at 4,096 tokens, rejects oversized requests, streams image/audio request bodies without materializing their base64 payloads in Worker memory, passes SSE/JSON responses through, and does not log request bodies.
- The Worker itself does not log request bodies; Workers AI's published data-usage policy states zero data retention and no training for standard inference requests. This does not cover data deliberately written by FARAST to its own logs or storage.
- The Worker does not store prompts, user profiles, product files, or knowledge data.

## Free-tier limits

Cloudflare Workers Free has request and CPU limits. Workers AI currently includes 10,000 Neurons per day at no charge; exceeding the daily allocation causes further inference requests to fail until reset. This is a bounded API path, not unlimited inference or a production SLA. Laravel's existing queue must retain work after 429/5xx responses and retry later.

## One-click GitHub deployment

The repository includes a manual workflow: **Actions → deploy-farast-ai-gateway → Run workflow**. Before using it, add these repository secrets in GitHub Settings → Secrets and variables → Actions:

- `CLOUDFLARE_API_TOKEN`: token with permission to create/deploy the Worker.
- `CLOUDFLARE_ACCOUNT_ID`: the Cloudflare account ID.
- `CF_API_TOKEN`: token with Account → Workers AI → Read permission.
- `CF_ACCOUNT_ID`: the same Cloudflare account ID for the upstream AI request.
- `FARAST_AI_GATEWAY_TOKEN`: a long random secret shared with the Laravel host.

The workflow runs tests first, writes the secrets to an ephemeral runner file (never to the repository or logs), deploys the Worker, and deletes that temporary file. It runs only when manually started; it does not deploy on every push.

## Configure and deploy

Install Node.js 22+ and the Wrangler CLI. From this directory:

```bash
npm test
npx wrangler login
npx wrangler deploy
```

Create a Cloudflare API token scoped to the FARAST account with at least **Account → Workers AI → Read** permission, then set the Worker secrets (never commit them):

```bash
npx wrangler secret put FARAST_AI_GATEWAY_TOKEN
npx wrangler secret put CF_API_TOKEN
npx wrangler secret put CF_ACCOUNT_ID
```

Optional secret/configuration:
- Model IDs are pinned in `wrangler.toml` as `CF_TEXT_MODEL`, `CF_REVIEW_MODEL`, `CF_VISION_MODEL`, and `CF_AUDIO_MODEL`; change only after model quality, ZDR, and quota tests.

After deployment, test `/health`, `/ready`, and an authenticated chat request. Only then place the returned exact Worker URL in Laravel's `FARAST_AI_GATEWAY_URL`.

## Privacy and network limits

- Never send private user-memory profiles, hidden behavioral inferences, credentials, passwords, or account secrets to this gateway. Cloudflare's ZDR commitment is for standard Workers AI inference and does not protect data FARAST writes to its own logs or storage.
- For editor tasks, send only the text the user explicitly selected or asked to process; do not silently send the full document or unrelated history.
- Product files may be analyzed only through the approved ZDR model path and only for the product-processing purpose. The application must keep the private user profile and product knowledge in its own database.
- This Worker is an API gateway, not a VPN or a general-purpose proxy. It does not bypass a provider's legal/regional restrictions. If the Worker domain is unreachable from the application host, the application needs a separately tested fallback endpoint.
