const INSTANCE_ID = "farast-knowledge";
const MAX_CONTENT_BYTES = 1_048_576;
const ALLOWED_KNOWLEDGE_TYPES = new Set([
  "product_document",
  "product_metadata",
  "approved_experience",
]);
const PRIVATE_FIELD_NAMES = new Set([
  "user_id",
  "author_id",
  "email",
  "phone",
  "password",
  "token",
  "profile",
  "private_memory",
  "behavior",
  "personality",
  "audience_profile",
  "customer_profile",
  "author_name",
  "customer_name",
  "user_name",
  "account_id",
  "session_id",
  "ip_address",
  "address",
  "mobile",
  "email_address",
  "user_profile",
  "user_memory",
  "memory",
  "behavior_profile",
  "personality_profile",
  "psychographic_profile",
  "user_preferences",
  "purchase_history",
  "order_history",
  "private_notes",
]);

const CUSTOM_METADATA = [
  { field_name: "product_id", data_type: "text" },
  { field_name: "document_id", data_type: "text" },
  { field_name: "knowledge_type", data_type: "text" },
  { field_name: "visibility", data_type: "text" },
  { field_name: "language", data_type: "text" },
];

const INSTANCE_CONFIG = {
  id: INSTANCE_ID,
  embedding_model: "@cf/baai/bge-m3",
  index_method: { vector: true, keyword: true },
  fusion_method: "rrf",
  indexing_options: {
    keyword_tokenizer: "trigram",
    use_ocr: false,
    file_summary: false,
  },
  retrieval_options: { keyword_match_mode: "or" },
  chunk_size: 512,
  chunk_overlap: 30,
  max_num_results: 10,
  score_threshold: 0.22,
  custom_metadata: CUSTOM_METADATA,
  cache: false,
  reranking: false,
  rewrite_query: false,
};

function jsonResponse(body, status = 200, extraHeaders = {}) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "content-type": "application/json; charset=utf-8",
      "cache-control": "no-store, max-age=0",
      "x-content-type-options": "nosniff",
      "referrer-policy": "no-referrer",
      ...extraHeaders,
    },
  });
}

function constantTimeEqual(left, right) {
  if (typeof left !== "string" || typeof right !== "string" || left.length !== right.length) {
    return false;
  }
  let difference = 0;
  for (let index = 0; index < left.length; index += 1) {
    difference |= left.charCodeAt(index) ^ right.charCodeAt(index);
  }
  return difference === 0;
}

function isAuthorized(request, env) {
  const authorization = request.headers.get("authorization") || "";
  const match = authorization.match(/^Bearer\s+(.+)$/i);
  if (!match) return false;
  const supplied = match[1].trim();
  const allowed = [
    env.FARAST_KB_LARAVEL_TOKEN,
    env.FARAST_KB_SUPERVISOR_TOKEN,
  ].filter((value) => typeof value === "string" && value.length >= 32);
  return allowed.some((token) => constantTimeEqual(supplied, token));
}

function hasPrivateFields(value) {
  if (!value || typeof value !== "object") return false;
  if (Array.isArray(value)) return value.some((item) => hasPrivateFields(item));

  return Object.entries(value).some(([key, child]) => {
    const normalizedKey = key
      .replace(/([a-z0-9])([A-Z])/g, "$1_$2")
      .toLowerCase()
      .replace(/[\s-]+/g, "_");
    return PRIVATE_FIELD_NAMES.has(normalizedKey)
      || (child && typeof child === "object" && hasPrivateFields(child));
  });
}

function validIdentifier(value) {
  return typeof value === "string"
    && value.length >= 1
    && value.length <= 64
    && value !== "."
    && value !== ".."
    && /^[A-Za-z0-9_-]+$/.test(value);
}

function containsDirectContactDetails(value) {
  if (typeof value !== "string") return false;
  const compact = value.replace(/[\s().-]/g, "");
  const hasEmail = /[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i.test(value);
  const hasIranianMobile = /(?:\+?98|0)?9\d{9}/.test(compact);
  return hasEmail || hasIranianMobile;
}

function itemKey(productId, knowledgeType, documentId) {
  const prefix = knowledgeType === "approved_experience" ? "experiences" : "products";
  return `${prefix}/${productId}/${knowledgeType}/${documentId}.md`;
}

async function sha256Hex(value) {
  const bytes = new TextEncoder().encode(value);
  const digest = await crypto.subtle.digest("SHA-256", bytes);
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

async function ensureInstance(env) {
  const namespace = env.AI_SEARCH;
  if (!namespace) throw new Error("ai_search_binding_unavailable");

  const listed = await namespace.list({ search: INSTANCE_ID, per_page: 100 });
  const exists = (listed.result || []).some((item) => item.id === INSTANCE_ID);
  if (!exists) {
    try {
      return await namespace.create(INSTANCE_CONFIG);
    } catch {
      const refreshed = await namespace.list({ search: INSTANCE_ID, per_page: 100 });
      if (!(refreshed.result || []).some((item) => item.id === INSTANCE_ID)) {
        throw new Error("ai_search_instance_creation_failed");
      }
    }
  }

  const instance = namespace.get(INSTANCE_ID);
  const info = await instance.info();
  const configuredFields = (info.custom_metadata || [])
    .map((field) => field.field_name)
    .sort();
  const expectedFields = CUSTOM_METADATA.map((field) => field.field_name).sort();
  const metadataMatches = JSON.stringify(configuredFields) === JSON.stringify(expectedFields);
  const embeddingMatches = info.embedding_model === INSTANCE_CONFIG.embedding_model;
  if (!metadataMatches || !embeddingMatches) {
    throw new Error("ai_search_instance_schema_mismatch");
  }
  return instance;
}

async function readJson(request, maxBytes = MAX_CONTENT_BYTES + 32_768) {
  const contentLength = Number(request.headers.get("content-length") || 0);
  if (contentLength > maxBytes) throw new RequestError(413, "request_too_large");
  const raw = await request.text();
  if (new TextEncoder().encode(raw).byteLength > maxBytes) {
    throw new RequestError(413, "request_too_large");
  }
  try {
    return JSON.parse(raw);
  } catch {
    throw new RequestError(400, "invalid_json");
  }
}

class RequestError extends Error {
  constructor(status, code) {
    super(code);
    this.status = status;
    this.code = code;
  }
}

async function upsertKnowledge(request, env) {
  const body = await readJson(request);
  if (hasPrivateFields(body)) throw new RequestError(400, "private_fields_not_allowed");

  const productId = String(body.product_id || "");
  const documentId = String(body.document_id || "");
  const knowledgeType = String(body.knowledge_type || "");
  const visibility = String(body.visibility || "");
  const language = String(body.language || "fa");
  const content = body.content;
  if (!validIdentifier(productId) || !validIdentifier(documentId)) {
    throw new RequestError(400, "invalid_product_or_document_id");
  }
  if (!ALLOWED_KNOWLEDGE_TYPES.has(knowledgeType)) {
    throw new RequestError(400, "unsupported_knowledge_type");
  }
  if (visibility !== "public") throw new RequestError(403, "only_public_knowledge_can_be_indexed");
  if (knowledgeType === "product_document") {
    if (body.access_scope !== "public_sample_only") {
      throw new RequestError(403, "only_public_sample_documents_can_be_indexed");
    }
    if (body.review_status !== "approved" || body.product_status !== "published") {
      throw new RequestError(403, "product_document_requires_approved_publication");
    }
  }
  if (knowledgeType === "product_metadata") {
    if (body.review_status !== "approved" || body.product_status !== "published") {
      throw new RequestError(403, "product_metadata_requires_approved_publication");
    }
  }
  if (knowledgeType === "approved_experience") {
    if (body.approval_status !== "approved") {
      throw new RequestError(403, "experience_requires_approval");
    }
    if (body.privacy_review_status !== "approved" || body.anonymized !== true) {
      throw new RequestError(403, "experience_requires_privacy_review");
    }
    if (containsDirectContactDetails(content)) {
      throw new RequestError(403, "experience_contains_contact_details");
    }
  }
  if (typeof content !== "string" || !content.trim()) {
    throw new RequestError(400, "knowledge_content_required");
  }
  const contentBytes = new TextEncoder().encode(content).byteLength;
  if (contentBytes > MAX_CONTENT_BYTES) throw new RequestError(413, "knowledge_content_too_large");
  if (!/^[a-z]{2,3}(?:-[A-Z]{2})?$/.test(language)) {
    throw new RequestError(400, "invalid_language");
  }

  const checksum = await sha256Hex(content);
  if (body.checksum && !constantTimeEqual(String(body.checksum).toLowerCase(), checksum)) {
    throw new RequestError(400, "knowledge_checksum_mismatch");
  }

  const key = itemKey(productId, knowledgeType, documentId);
  if (key.length > 128) throw new RequestError(400, "knowledge_key_too_long");

  const instance = await ensureInstance(env);
  const previous = await instance.items.list({ search: key, per_page: 50 });
  const uploaded = await instance.items.upload(key, content, {
    metadata: {
      product_id: productId,
      document_id: documentId,
      knowledge_type: knowledgeType,
      visibility,
      language,
    },
  });

  const oldItems = (previous.result || []).filter((item) => item.key === key && item.id !== uploaded.id);
  for (const oldItem of oldItems) {
    await instance.items.delete(oldItem.id);
  }

  return {
    item_id: uploaded.id,
    key: uploaded.key,
    status: "queued",
    checksum,
    bytes: contentBytes,
  };
}

async function searchKnowledge(request, env) {
  const body = await readJson(request, 32_768);
  if (hasPrivateFields(body)) throw new RequestError(400, "private_fields_not_allowed");
  const query = typeof body.query === "string" ? body.query.trim() : "";
  if (!query || query.length > 1500) throw new RequestError(400, "invalid_query");

  const productId = body.product_id == null ? null : String(body.product_id);
  if (productId && !validIdentifier(productId)) throw new RequestError(400, "invalid_product_id");

  const language = String(body.language || "fa");
  if (!/^[a-z]{2,3}(?:-[A-Z]{2})?$/.test(language)) {
    throw new RequestError(400, "invalid_language");
  }

  const requestedTypes = Array.isArray(body.knowledge_types) ? body.knowledge_types : ["product_document", "product_metadata"];
  const knowledgeTypes = [...new Set(requestedTypes.map(String))];
  if (
    knowledgeTypes.length < 1
    || knowledgeTypes.length > 3
    || knowledgeTypes.some((type) => !ALLOWED_KNOWLEDGE_TYPES.has(type))
  ) {
    throw new RequestError(400, "invalid_knowledge_types");
  }

  const topK = Math.max(1, Math.min(Number(body.top_k) || 8, 20));
  const filters = {
    visibility: "public",
    language,
    knowledge_type: { $in: knowledgeTypes },
  };
  if (productId) filters.product_id = productId;

  const instance = await ensureInstance(env);
  const result = await instance.search({
    messages: [{ role: "user", content: query }],
    ai_search_options: {
      retrieval: {
        retrieval_type: "hybrid",
        match_threshold: Math.max(0.1, Math.min(Number(body.match_threshold) || 0.22, 0.8)),
        max_num_results: topK,
        context_expansion: 1,
        keyword_match_mode: "or",
        filters,
        return_on_failure: true,
      },
      cache: { enabled: false },
    },
  });

  return {
    chunks: (result.chunks || []).map((chunk) => ({
      id: chunk.id,
      text: chunk.text,
      score: chunk.score,
      source_key: chunk.item?.key || null,
      metadata: chunk.item?.metadata || {},
      instance_id: chunk.instance_id || INSTANCE_ID,
    })),
    errors: result.errors || [],
  };
}

async function getItemStatus(itemId, env) {
  if (!validIdentifier(itemId)) throw new RequestError(400, "invalid_item_id");
  const instance = await ensureInstance(env);
  return await instance.items.get(itemId).info();
}

async function deleteKnowledge(request, env) {
  if (!isAuthorized(request, env.FARAST_KB_LARAVEL_TOKEN)) {
    throw new RequestError(403, "laravel_token_required");
  }

  const body = await readJson(request, 16_384);
  const productId = validIdentifier(body.product_id);
  const documentId = validIdentifier(body.document_id);
  const knowledgeType = String(body.knowledge_type || "");
  if (!productId || !documentId || !KNOWLEDGE_TYPES.has(knowledgeType)) {
    throw new RequestError(400, "invalid_delete_scope");
  }

  const key = itemKey(productId, knowledgeType, documentId);
  const instance = await ensureInstance(env);
  const listed = await instance.items.list({ search: key, per_page: 50 });
  const matches = (listed.result || []).filter((item) => item.key === key);
  let deleted = 0;

  for (const item of matches) {
    await instance.items.delete(item.id);
    deleted += 1;
  }

  return { status: "deleted", product_id: productId, document_id: documentId, knowledge_type: knowledgeType, deleted };
}

async function deleteItem(request, env) {
  const body = await readJson(request, 16_384);
  const itemId = String(body.item_id || "");
  if (!validIdentifier(itemId)) throw new RequestError(400, "invalid_item_id");
  const instance = await ensureInstance(env);
  await instance.items.delete(itemId);
  return { deleted: true, item_id: itemId };
}

async function stats(env) {
  const instance = await ensureInstance(env);
  const [info, current] = await Promise.all([instance.info(), instance.stats()]);
  return {
    id: info.id,
    status: info.status,
    embedding_model: info.embedding_model,
    index_method: info.index_method,
    custom_metadata: info.custom_metadata,
    indexing: {
      queued: current.queued,
      running: current.running,
      completed: current.completed,
      error: current.error,
      skipped: current.skipped,
      outdated: current.outdated,
      vectors_count: current.engine?.vectorize?.vectorsCount ?? null,
      storage_bytes: current.engine?.r2?.payloadSizeBytes ?? null,
      object_count: current.engine?.r2?.objectCount ?? null,
    },
  };
}

export async function handleRequest(request, env) {
  const url = new URL(request.url);
  const path = url.pathname.replace(/\/+$/, "") || "/";
  if (request.method === "GET" && path === "/health") {
    return jsonResponse({ status: "ok", service: "farast-knowledge-gateway", version: "1" });
  }

  if (!isAuthorized(request, env)) {
    return jsonResponse({ error: "unauthorized" }, 401);
  }

  try {
    if (request.method === "POST" && path === "/v1/setup") {
      const instance = await ensureInstance(env);
      const [info, current] = await Promise.all([instance.info(), instance.stats()]);
      return jsonResponse({
        status: "ready",
        id: info.id,
        embedding_model: info.embedding_model,
        index_method: info.index_method,
        custom_metadata: info.custom_metadata,
        queued: current.queued,
        completed: current.completed,
      });
    }

    if (request.method === "POST" && path === "/v1/knowledge/upsert") {
      return jsonResponse(await upsertKnowledge(request, env), 202);
    }

    if (request.method === "POST" && path === "/v1/knowledge/search") {
      return jsonResponse(await searchKnowledge(request, env));
    }

    if (request.method === "POST" && path === "/v1/knowledge/delete") {
      return jsonResponse(await deleteKnowledge(request, env));
    }

    if (request.method === "GET" && path === "/v1/knowledge/stats") {
      return jsonResponse(await stats(env));
    }

    const itemMatch = path.match(/^\/v1\/knowledge\/items\/([A-Za-z0-9._-]{1,64})$/);
    if (request.method === "GET" && itemMatch) {
      return jsonResponse(await getItemStatus(itemMatch[1], env));
    }

    return jsonResponse({ error: "not_found" }, 404);
  } catch (error) {
    if (error instanceof RequestError) {
      return jsonResponse({ error: error.code }, error.status);
    }
    if (error?.message === "ai_search_instance_schema_mismatch") {
      return jsonResponse({ error: "ai_search_instance_schema_mismatch" }, 409);
    }
    return jsonResponse({ error: "knowledge_service_unavailable" }, 503);
  }
}

export default {
  fetch(request, env) {
    return handleRequest(request, env);
  },
};
