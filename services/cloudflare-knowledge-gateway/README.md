# FARAST Cloudflare Knowledge Gateway

This is a small, stateless Cloudflare Worker that exposes a private API for the FARAST knowledge index. It uses Cloudflare AI Search built-in storage; it does not require an R2 bucket, D1 database, separate vector database, or a paid server.

## Responsibilities

- Index sanitized, public product-document knowledge and product metadata.
- Index user experiences only when the caller explicitly marks them as reviewer-approved.
- Search by Persian/English query, with strict metadata filters for product, language, visibility and knowledge type.
- Report item indexing status and index statistics.
- Keep private user profiles, behavior/personality observations, account identifiers and private memories out of the public knowledge index.

The Laravel database remains the source of truth. AI Search is a rebuildable retrieval index, not a replacement for product, user, order, or review records.

## Free-tier limits

Cloudflare AI Search's free allowance is limited: 10 GB-month storage, 5 million ingestion tokens per month, 1,000 semantic queries per month and 1,000 full-text queries per month. These are not unlimited quotas. The application must keep its MySQL keyword/full-text retrieval as a fallback and must not assume paid overage is available.

The index uses multilingual `@cf/baai/bge-m3` embeddings, hybrid vector + keyword retrieval, and a trigram keyword tokenizer for Persian text. It stores only sanitized knowledge text, not original product files.

## Index design

One built-in-storage instance named `farast-knowledge` is created automatically by `POST /v1/setup`. It has five filterable metadata fields:

- `product_id`
- `document_id`
- `knowledge_type`
- `visibility`
- `language`

Allowed knowledge types are `product_document`, `product_metadata` and `approved_experience`. All indexed records must have `visibility=public`. Experiences additionally require `approval_status=approved`.

No `user_id`, email, phone, profile, behavior, personality, audience-profile or private-memory fields are accepted at the request's top level. The Laravel indexer must sanitize experience content before sending it; the gateway cannot infer whether arbitrary text contains personal data.

## API

All routes except `GET /health` require `Authorization: Bearer <service-token>`. Use separate random tokens for Laravel and the Product Supervisor service.

| Method | Path | Purpose |
|---|---|---|
| GET | `/health` | Public liveness check; no account details or secrets |
| POST | `/v1/setup` | Create/validate the dedicated index |
| POST | `/v1/knowledge/upsert` | Upload or replace one knowledge document |
| POST | `/v1/knowledge/search` | Hybrid search with product/visibility/type filters |
| GET | `/v1/knowledge/items/{item_id}` | Check indexing status |
| POST | `/v1/knowledge/delete` | Delete one indexed item by ID |
| GET | `/v1/knowledge/stats` | Indexing and storage statistics |

### Upsert request

```json
{
  "product_id": "42",
  "document_id": "product-document-91",
  "knowledge_type": "product_document",
  "visibility": "public",
  "language": "fa",
  "content": "# خلاصه دانش محصول\n\nمتن پالایش‌شده و تأییدشده..."
}
```

For `approved_experience`, the caller must also send `"approval_status": "approved"`. The Worker computes SHA-256 itself and returns the checksum and queued item ID. Content is limited to 1 MiB per request; current FARAST extracted-text limits are well below this.

### Search request

```json
{
  "query": "این محصول چه قابلیت‌هایی دارد؟",
  "product_id": "42",
  "knowledge_types": ["product_document", "product_metadata"],
  "language": "fa",
  "top_k": 8
}
```

The Worker always adds `visibility=public` to the retrieval filters. A product ID filter is applied before retrieval, not after it.

## Deploy

1. Create a free Cloudflare account. No R2 bucket or payment card is needed for this Worker + AI Search built-in-storage design.
2. Create a Cloudflare API token for deployment with Workers Admin at the account scope and AI Search Edit/Run permissions. Do not use the token as an application API token.
3. Add GitHub repository secrets `CLOUDFLARE_ACCOUNT_ID` and `CLOUDFLARE_API_TOKEN`.
4. Run the manual GitHub Actions workflow **Deploy FARAST Knowledge Gateway**.
5. In Cloudflare Dashboard → Workers & Pages → `farast-knowledge-gateway` → Settings → Variables and Secrets, add two Secret variables:
   - `FARAST_KB_LARAVEL_TOKEN`
   - `FARAST_KB_SUPERVISOR_TOKEN`
6. Call `POST /v1/setup` with either service token. This creates the built-in AI Search instance if it does not already exist.

Do not put any of these tokens in Git, source files, chat messages or the public `.env.example`.

## Local checks

Requires Node.js 22.12 or newer.

```sh
npm install
npm test
npx wrangler deploy --dry-run
```

The tests use a mocked AI Search binding. They verify authorization, public-only indexing, experience approval, product-level filtering, metadata shape and protection against overwriting an incompatible existing index. They do not claim that a live Cloudflare account has been connected or deployed.
