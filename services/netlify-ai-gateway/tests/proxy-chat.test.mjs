import test from "node:test";
import assert from "node:assert/strict";
import { proxyChat } from "../netlify/functions/proxy-chat.mjs";

const ENV_KEYS = [
  "FARAST_NETLIFY_GATEWAY_TOKEN",
  "FARAST_CLOUDFLARE_AI_GATEWAY_URL",
  "FARAST_CLOUDFLARE_AI_GATEWAY_TOKEN",
];
const originalEnv = Object.fromEntries(ENV_KEYS.map((key) => [key, process.env[key]]));
const originalFetch = globalThis.fetch;

function setReadyEnv() {
  process.env.FARAST_NETLIFY_GATEWAY_TOKEN = "incoming-gateway-token-".padEnd(40, "x");
  process.env.FARAST_CLOUDFLARE_AI_GATEWAY_URL = "https://farast-ai-gateway.workers.dev";
  process.env.FARAST_CLOUDFLARE_AI_GATEWAY_TOKEN = "upstream-worker-token-".padEnd(40, "y");
}

function makeRequest(path, payload, token = process.env.FARAST_NETLIFY_GATEWAY_TOKEN) {
  return new Request(`https://farast-netlify.test${path}`, {
    method: "POST",
    headers: {
      authorization: token ? `Bearer ${token}` : "",
      "content-type": "application/json",
    },
    body: JSON.stringify(payload),
  });
}

test("forwards text requests only to the fixed Cloudflare Worker endpoint", async () => {
  setReadyEnv();
  let captured;
  globalThis.fetch = async (url, init) => {
    captured = { url: String(url), init };
    return new Response(JSON.stringify({ choices: [{ message: { content: "ok" } }] }), {
      status: 200,
      headers: { "content-type": "application/json" },
    });
  };

  const response = await proxyChat(makeRequest("/v1/chat/completions", {
    model: "arbitrary-model-is-overridden",
    messages: [{ role: "user", content: "سلام" }],
    max_tokens: 99999,
  }), "text");

  assert.equal(response.status, 200);
  assert.equal(response.headers.get("cache-control"), "no-store, private");
  assert.equal(response.headers.get("x-farast-gateway"), "netlify-fallback");
  assert.equal(captured.url, "https://farast-ai-gateway.workers.dev/v1/chat/completions");
  assert.equal(captured.init.headers.authorization, `Bearer ${process.env.FARAST_CLOUDFLARE_AI_GATEWAY_TOKEN}`);
  const body = JSON.parse(captured.init.body);
  assert.equal(body.model, "farast-general");
  assert.equal(body.max_tokens, 1024);
  assert.equal(body.messages[0].content, "سلام");
});

test("routes review requests to the dedicated review path with its own output cap", async () => {
  setReadyEnv();
  let captured;
  globalThis.fetch = async (url, init) => {
    captured = { url: String(url), init };
    return new Response(JSON.stringify({ choices: [] }), { status: 200 });
  };

  const response = await proxyChat(makeRequest("/v1/chat/completions/review", {
    messages: [{ role: "user", content: "review" }],
    max_completion_tokens: 99999,
  }), "review");

  assert.equal(response.status, 200);
  assert.equal(captured.url, "https://farast-ai-gateway.workers.dev/v1/chat/completions/review");
  const body = JSON.parse(captured.init.body);
  assert.equal(body.model, "farast-review");
  assert.equal(body.max_tokens, 2200);
  assert.equal("max_completion_tokens" in body, false);
});

test("rejects missing or incorrect incoming credentials without calling upstream", async () => {
  setReadyEnv();
  let called = false;
  globalThis.fetch = async () => { called = true; return new Response("ok"); };

  const missing = await proxyChat(makeRequest("/v1/chat/completions", {
    messages: [{ role: "user", content: "x" }],
  }, ""), "text");
  const wrong = await proxyChat(makeRequest("/v1/chat/completions", {
    messages: [{ role: "user", content: "x" }],
  }, "wrong-token"), "text");

  assert.equal(missing.status, 401);
  assert.equal(wrong.status, 401);
  assert.equal(called, false);
});

test("rejects arbitrary upstream fields and malformed messages", async () => {
  setReadyEnv();
  const arbitrary = await proxyChat(makeRequest("/v1/chat/completions", {
    url: "https://attacker.invalid",
    messages: [{ role: "user", content: "x" }],
  }), "text");
  const invalidMessage = await proxyChat(makeRequest("/v1/chat/completions", {
    messages: [{ role: "tool", content: "x" }],
  }), "text");

  assert.equal(arbitrary.status, 400);
  assert.equal(invalidMessage.status, 400);
});

test("rejects oversized requests before contacting upstream", async () => {
  setReadyEnv();
  let called = false;
  globalThis.fetch = async () => { called = true; return new Response("ok"); };
  const largeBody = JSON.stringify({
    messages: [{ role: "user", content: "x".repeat(1024 * 1024 + 10) }],
  });
  const largeRequest = new Request("https://farast-netlify.test/v1/chat/completions", {
    method: "POST",
    headers: {
      authorization: `Bearer ${process.env.FARAST_NETLIFY_GATEWAY_TOKEN}`,
      "content-type": "application/json",
      "content-length": String(Buffer.byteLength(largeBody)),
    },
    body: largeBody,
  });

  const response = await proxyChat(largeRequest, "text");
  assert.equal(response.status, 413);
  assert.equal(called, false);
});

test("passes provider quota status through without masking retry information", async () => {
  setReadyEnv();
  globalThis.fetch = async () => new Response(JSON.stringify({ error: "rate_limited" }), {
    status: 429,
    headers: { "content-type": "application/json", "retry-after": "60" },
  });

  const response = await proxyChat(makeRequest("/v1/chat/completions", {
    messages: [{ role: "user", content: "x" }],
  }), "text");

  assert.equal(response.status, 429);
  assert.equal(response.headers.get("retry-after"), "60");
});

test("returns a generic 503 when the upstream cannot be reached and does not expose details", async () => {
  setReadyEnv();
  globalThis.fetch = async () => { throw new Error("sensitive upstream token or body"); };

  const response = await proxyChat(makeRequest("/v1/chat/completions", {
    messages: [{ role: "user", content: "x" }],
  }), "text");

  assert.equal(response.status, 503);
  assert.deepEqual(await response.json(), { error: "upstream_unavailable" });
});

test.after(() => {
  globalThis.fetch = originalFetch;
  for (const key of ENV_KEYS) {
    if (originalEnv[key] === undefined) delete process.env[key];
    else process.env[key] = originalEnv[key];
  }
});
