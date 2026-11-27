import test from "node:test";
import assert from "node:assert/strict";
import { webcrypto } from "node:crypto";
import { handleRequest } from "../src/index.js";

globalThis.crypto ??= webcrypto;

function makeEnvironment(options = {}) {
  const state = {
    config: options.mismatch ? {
      custom_metadata: [
        { field_name: "product_id", data_type: "text" },
        { field_name: "document_id", data_type: "text" },
        { field_name: "knowledge_type", data_type: "text" },
        { field_name: "visibility", data_type: "text" },
        { field_name: "language", data_type: "text" },
      ],
    } : null,
    items: [],
    deleted: [],
    lastSearch: null,
    mismatch: options.mismatch || false,
  };

  const instance = {
    info: async () => ({
      id: "farast-knowledge",
      embedding_model: state.mismatch ? "@cf/baai/bge-base-en-v1.5" : "@cf/baai/bge-m3",
      index_method: { vector: true, keyword: true },
      custom_metadata: state.config?.custom_metadata || [
        { field_name: "product_id", data_type: "text" },
        { field_name: "document_id", data_type: "text" },
        { field_name: "knowledge_type", data_type: "text" },
        { field_name: "visibility", data_type: "text" },
        { field_name: "language", data_type: "text" },
      ],
      status: "ready",
    }),
    stats: async () => ({
      queued: 1,
      running: 0,
      completed: 3,
      error: 0,
      skipped: 0,
      outdated: 0,
      engine: { vectorize: { vectorsCount: 18 }, r2: { payloadSizeBytes: 2048, objectCount: 4 } },
    }),
    update: async (patch) => {
      state.config = { ...state.config, ...patch };
      return instance.info();
    },
    search: async (payload) => {
      state.lastSearch = payload;
      return {
        chunks: [{
          id: "chunk-1",
          text: "دانش تأییدشده محصول",
          score: 0.91,
          item: {
            key: "products/42/product_document/doc-1.md",
            metadata: {
              product_id: "42",
              document_id: "doc-1",
              knowledge_type: "product_document",
              visibility: "public",
              language: "fa",
            },
          },
        }],
        errors: [],
      };
    },
    items: {
      list: async ({ search } = {}) => ({
        result: state.items.filter((item) => !search || item.key.includes(search)),
        result_info: { total_count: state.items.length },
      }),
      upload: async (key, body, options) => {
        const item = {
          id: "item-" + (state.items.length + 1),
          key,
          content: body,
          metadata: options.metadata,
          status: "queued",
        };
        state.items.push(item);
        return { id: item.id, key: item.key };
      },
      delete: async (id) => {
        state.deleted.push(id);
        state.items = state.items.filter((item) => item.id !== id);
      },
      get: (id) => ({
        info: async () => {
          const item = state.items.find((candidate) => candidate.id === id);
          if (!item) throw new Error("item_not_found");
          return { id: item.id, key: item.key, status: item.status, metadata: item.metadata };
        },
      }),
    },
  };

  const namespace = {
    list: async ({ search } = {}) => ({
      result: state.config && (!search || "farast-knowledge".includes(search))
        ? [{ id: "farast-knowledge" }]
        : [],
      result_info: { total_count: state.config ? 1 : 0 },
    }),
    create: async (config) => {
      state.config = config;
      return instance;
    },
    get: () => instance,
  };

  return {
    state,
    env: {
      AI_SEARCH: namespace,
      FARAST_KB_LARAVEL_TOKEN: "l".repeat(48),
      FARAST_KB_SUPERVISOR_TOKEN: "s".repeat(48),
    },
  };
}

function request(path, { method = "GET", token, body } = {}) {
  const headers = {};
  if (token) headers.Authorization = "Bearer " + token;
  if (body !== undefined) headers["Content-Type"] = "application/json";
  return new Request("https://knowledge.test" + path, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });
}

test("health is public but exposes no account or secret details", async () => {
  const { env } = makeEnvironment();
  const response = await handleRequest(request("/health"), env);
  assert.equal(response.status, 200);
  const body = await response.json();
  assert.equal(body.service, "farast-knowledge-gateway");
  assert.equal("account_id" in body, false);
});

test("all knowledge operations require a valid service token", async () => {
  const { env } = makeEnvironment();
  const response = await handleRequest(request("/v1/setup", { method: "POST" }), env);
  assert.equal(response.status, 401);
});

test("setup creates a hybrid multilingual instance with five privacy-scoped metadata fields", async () => {
  const { env, state } = makeEnvironment();
  const response = await handleRequest(request("/v1/setup", {
    method: "POST",
    token: env.FARAST_KB_LARAVEL_TOKEN,
  }), env);

  assert.equal(response.status, 200);
  const body = await response.json();
  assert.equal(body.id, "farast-knowledge");
  assert.equal(body.embedding_model, "@cf/baai/bge-m3");
  assert.equal(state.config.index_method.vector, true);
  assert.equal(state.config.index_method.keyword, true);
  assert.equal(state.config.indexing_options.keyword_tokenizer, "trigram");
  assert.equal(state.config.custom_metadata.length, 5);
  assert.equal(state.config.cache, false);
  assert.equal(state.config.ai_search_model, undefined);
});

test("product knowledge upsert attaches public metadata and replaces old copies safely", async () => {
  const { env, state } = makeEnvironment();
  state.config = {
    custom_metadata: [
      { field_name: "product_id", data_type: "text" },
      { field_name: "document_id", data_type: "text" },
      { field_name: "knowledge_type", data_type: "text" },
      { field_name: "visibility", data_type: "text" },
      { field_name: "language", data_type: "text" },
    ],
  };
  state.items.push({
    id: "old-item",
    key: "products/42/product_document/doc-1.md",
    status: "completed",
  });

  const response = await handleRequest(request("/v1/knowledge/upsert", {
    method: "POST",
    token: env.FARAST_KB_SUPERVISOR_TOKEN,
    body: {
      product_id: "42",
      document_id: "doc-1",
      knowledge_type: "product_document",
      visibility: "public",
      language: "fa",
      content: "# دانش محصول\n\nاین محتوا تأیید شده است.",
    },
  }), env);

  assert.equal(response.status, 202);
  const body = await response.json();
  assert.equal(body.status, "queued");
  assert.equal(body.key, "products/42/product_document/doc-1.md");
  assert.ok(body.checksum);
  assert.deepEqual(state.items.find((item) => item.id === body.item_id)?.metadata, {
    product_id: "42",
    document_id: "doc-1",
    knowledge_type: "product_document",
    visibility: "public",
    language: "fa",
  });
  assert.deepEqual(state.deleted, ["old-item"]);
});

test("approved experiences cannot be indexed before reviewer approval", async () => {
  const { env } = makeEnvironment();
  const response = await handleRequest(request("/v1/knowledge/upsert", {
    method: "POST",
    token: env.FARAST_KB_LARAVEL_TOKEN,
    body: {
      product_id: "42",
      document_id: "experience-1",
      knowledge_type: "approved_experience",
      visibility: "public",
      language: "fa",
      content: "تجربه تأییدنشده",
    },
  }), env);
  assert.equal(response.status, 403);
  assert.equal((await response.json()).error, "experience_requires_approval");
});

test("private profile and behavior fields are rejected before indexing", async () => {
  const { env } = makeEnvironment();
  const response = await handleRequest(request("/v1/knowledge/upsert", {
    method: "POST",
    token: env.FARAST_KB_LARAVEL_TOKEN,
    body: {
      user_id: 99,
      product_id: "42",
      document_id: "doc-1",
      knowledge_type: "product_document",
      visibility: "public",
      content: "متن",
    },
  }), env);
  assert.equal(response.status, 400);
  assert.equal((await response.json()).error, "private_fields_not_allowed");
});

test("semantic search always filters public knowledge and scopes to the requested product", async () => {
  const { env, state } = makeEnvironment();
  state.config = {
    custom_metadata: [
      { field_name: "product_id", data_type: "text" },
      { field_name: "document_id", data_type: "text" },
      { field_name: "knowledge_type", data_type: "text" },
      { field_name: "visibility", data_type: "text" },
      { field_name: "language", data_type: "text" },
    ],
  };

  const response = await handleRequest(request("/v1/knowledge/search", {
    method: "POST",
    token: env.FARAST_KB_LARAVEL_TOKEN,
    body: {
      query: "این محصول برای چه کاری مناسب است؟",
      product_id: "42",
      language: "fa",
      knowledge_types: ["product_document", "approved_experience"],
      top_k: 6,
    },
  }), env);

  assert.equal(response.status, 200);
  const body = await response.json();
  assert.equal(body.chunks.length, 1);
  assert.equal(body.chunks[0].metadata.product_id, "42");
  assert.equal(state.lastSearch.ai_search_options.cache.enabled, false);
  assert.equal(state.lastSearch.ai_search_options.retrieval.filters.visibility, "public");
  assert.equal(state.lastSearch.ai_search_options.retrieval.filters.product_id, "42");
  assert.deepEqual(
    state.lastSearch.ai_search_options.retrieval.filters.knowledge_type.$in,
    ["product_document", "approved_experience"],
  );
});

test("an existing instance with a different embedding schema is never overwritten", async () => {
  const { env } = makeEnvironment({ mismatch: true });
  const response = await handleRequest(request("/v1/setup", {
    method: "POST",
    token: env.FARAST_KB_LARAVEL_TOKEN,
  }), env);
  assert.equal(response.status, 409);
  assert.equal((await response.json()).error, "ai_search_instance_schema_mismatch");
});
