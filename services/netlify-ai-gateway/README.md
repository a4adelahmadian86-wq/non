# FARAST Netlify AI Gateway — secondary network path

This is a small, authenticated application gateway for FARAST. It is not a VPN, open proxy, general-purpose relay, model host, or knowledge store.

## Role in the architecture

- Primary model gateway: the dedicated FARAST Cloudflare Worker.
- Secondary network path: this Netlify Function forwards only the two fixed FARAST chat endpoints to that same Worker.
- It exists for the case where the Iranian website host cannot reach the Worker hostname directly, while Netlify's server can reach it.
- It does not bypass a provider's account, country, sanctions, or product eligibility rules. If Cloudflare rejects the account or request with 401/403, that response is passed through. If the model quota is exhausted (429), that response is also passed through; retrying the same provider through another gateway will not create more quota. This route only helps when the Laravel host cannot establish a network connection to the primary Worker but Netlify can. It does not help when the Worker itself returns a provider error or 5xx; Laravel should not retry the same upstream through Netlify for those responses.
- It stores no prompts, files, user profiles, or durable state. Request bodies are not logged by this code; Netlify still processes the request in transit under its own service terms.
- Send only the minimum task-specific text needed for the request. Never include behavioral/personality profiles, private user-memory records, account identifiers, credentials, or confidential data in this fallback path. Keep private user memory in the existing protected FARAST database and Iranian backup path; do not export it to external gateways.

The gateway accepts only the fixed text and review endpoints, validates JSON messages, applies strict request/output limits, and forwards to a fixed HTTPS Worker URL held in server-side environment variables. It never accepts a client-supplied URL or arbitrary upstream path.

## Netlify Free suitability

Netlify's Free plan is $0 with a hard monthly credit cap and no automatic recharge. Its published plan is suitable only as a low-volume fallback, not as an always-on or SLA-backed production dependency. The function uses streamed responses where requested and has a 55-second upstream timeout. Keep it optional; Laravel must retain its durable queue/retry path when both gateways are unreachable.

## Deploy from this monorepo

1. Create a Netlify account and a new site connected to this GitHub repository.
2. Set the site's Base directory to services/netlify-ai-gateway.
3. Keep the plan on Free and do not enable paid upgrades or auto-recharge.
4. Set these environment variables in Netlify's site settings:
   - FARAST_NETLIFY_GATEWAY_TOKEN: a new random secret used only by Laravel when calling Netlify.
   - FARAST_CLOUDFLARE_AI_GATEWAY_URL: the exact HTTPS origin of the deployed FARAST Cloudflare AI Worker, without a path.
   - FARAST_CLOUDFLARE_AI_GATEWAY_TOKEN: the Worker token; never reuse the Netlify incoming token.
5. Deploy. Verify GET /health and GET /ready; then test both authenticated chat routes from the Laravel host.
6. Only after the gateway-only Laravel client in PR #753 (or its verified successor) is merged, and live tests from the actual Laravel host pass, set FARAST_AI_GATEWAY_FALLBACK_URL to the Netlify site origin and FARAST_AI_GATEWAY_FALLBACK_TOKEN to the incoming Netlify token.

The Worker must already be deployed before this service can become ready. No real secrets or production URLs belong in Git.

## Routes

- GET /health: liveness only.
- GET /ready: configuration readiness only; does not make a model call.
- POST /v1/chat/completions: text task.
- POST /v1/chat/completions/review: higher-quality review task.

Both POST routes require an Authorization Bearer token and application/json. They accept a standard OpenAI-style messages array with text content. The gateway uses separate model aliases for text and review; the upstream Worker selects the actual models.

## Local validation

Run npm test from this directory.
