# FARAST AI Gateway (Cloudflare Worker)

This is a stateless, server-side gateway for the existing Laravel AI-provider contract. Laravel calls only this gateway's `/v1/chat/completions`; the Worker holds the Cloudflare credentials and calls Workers AI. Provider credentials never enter browser code or the Laravel repository.

## Contract

- `GET /health`: public liveness only.
- `GET /ready`: confirms that required secrets and the account ID are configured.
- `POST /v1/chat/completions`: Bearer-authenticated OpenAI-compatible chat endpoint.
- The Worker pins the model server-side, caps output at 1,024 tokens, rejects oversized requests, passes streaming responses through, and does not log request bodies.
- AI Gateway request and payload logging headers are explicitly disabled.
- The Worker does not store prompts, user profiles, product files, or knowledge data.

## Free-tier limits

Cloudflare Workers Free has request and CPU limits. Workers AI currently includes 10,000 Neurons per day at no charge; exceeding the daily allocation causes further inference requests to fail until reset. This is a bounded API path, not unlimited inference or a production SLA. Laravel's existing queue must retain work after 429/5xx responses and retry later.

## Configure and deploy

Install Node.js 22+ and the Wrangler CLI. From this directory:

```bash
npm test
npx wrangler login
npx wrangler deploy
```

Then set secrets for the Worker (never commit them):

```bash
npx wrangler secret put FARAST_AI_GATEWAY_TOKEN
npx wrangler secret put CF_API_TOKEN
npx wrangler secret put CF_ACCOUNT_ID
```

Optional secret/configuration:
- `CF_AI_GATEWAY_ID`: existing Cloudflare AI Gateway ID, if one is configured. Leave unset otherwise.
- `CF_MODEL`: pinned in `wrangler.toml` to `@cf/openai/gpt-oss-20b`; change only after model quality and quota tests.

After deployment, test `/health`, `/ready`, and an authenticated chat request. Only then place the returned exact Worker URL in Laravel's `FARAST_AI_GATEWAY_URL`.

## Privacy and network limits

- Never send private user-memory profiles, inferred behavior, credentials, or confidential user documents to this free API gateway.
- Only send the minimum content required for the operation. Public/approved product text may be routed according to the site's data policy.
- This Worker is an API gateway, not a VPN or a general-purpose proxy. It does not bypass a provider's legal/regional restrictions. If the Worker domain is unreachable from the application host, the application needs a separately tested fallback endpoint.
