import { timingSafeEqual } from "node:crypto";

const MAX_REQUEST_BYTES = 1024 * 1024;
const MAX_MESSAGES = 80;
const MAX_MESSAGE_CHARS = 120_000;
const ALLOWED_FIELDS = new Set([
  "model", "messages", "temperature", "top_p", "max_tokens",
  "max_completion_tokens", "stream", "stop", "presence_penalty",
  "frequency_penalty", "response_format", "seed",
]);

function json(status, body, headers = {}) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "content-type": "application/json; charset=utf-8",
      "cache-control": "no-store, private",
      "x-content-type-options": "nosniff",
      ...headers,
    },
  });
}

function secureEqual(left, right) {
  if (typeof left !== "string" || typeof right !== "string") return false;
  const a = Buffer.from(left, "utf8");
  const b = Buffer.from(right, "utf8");
  return a.length === b.length && timingSafeEqual(a, b);
}

function normalizeUpstreamBase(value) {
  if (!value) return null;
  try {
    const url = new URL(value);
    if (
      url.protocol !== "https:" ||
      url.username ||
      url.password ||
      url.search ||
      url.hash ||
      (url.pathname !== "/" && url.pathname !== "")
    ) return null;
    return url.toString().replace(/\/+$/, "");
  } catch {
    return null;
  }
}

async function readBodyLimited(request) {
  const declaredLength = Number(request.headers.get("content-length") || 0);
  if (declaredLength > MAX_REQUEST_BYTES) throw new RangeError("request_too_large");

  const reader = request.body?.getReader();
  if (!reader) return "";
  const chunks = [];
  let total = 0;

  while (true) {
    const { value, done } = await reader.read();
    if (done) break;
    total += value.byteLength;
    if (total > MAX_REQUEST_BYTES) {
      await reader.cancel();
      throw new RangeError("request_too_large");
    }
    chunks.push(value);
  }

  const merged = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) {
    merged.set(chunk, offset);
    offset += chunk.byteLength;
  }
  return new TextDecoder().decode(merged);
}

function validatePayload(payload) {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) return "invalid_payload";
  for (const key of Object.keys(payload)) {
    if (!ALLOWED_FIELDS.has(key)) return "unsupported_payload_field";
  }
  if (!Array.isArray(payload.messages) || payload.messages.length < 1 || payload.messages.length > MAX_MESSAGES) {
    return "invalid_messages";
  }

  let totalChars = 0;
  for (const message of payload.messages) {
    if (!message || typeof message !== "object" || Array.isArray(message)) return "invalid_message";
    if (!["system", "user", "assistant"].includes(message.role)) return "invalid_message_role";
    if (typeof message.content !== "string") return "invalid_message_content";
    totalChars += message.content.length;
    if (totalChars > MAX_MESSAGE_CHARS) return "message_content_too_large";
  }

  if (payload.stream !== undefined && typeof payload.stream !== "boolean") return "invalid_stream_flag";
  const requested = Number(payload.max_tokens ?? payload.max_completion_tokens ?? 1024);
  if (!Number.isFinite(requested) || requested < 1) return "invalid_max_tokens";
  return null;
}

export async function proxyChat(request, task) {
  if (request.method !== "POST") return json(405, { error: "method_not_allowed" }, { allow: "POST" });

  const expectedToken = process.env.FARAST_NETLIFY_GATEWAY_TOKEN || "";
  const suppliedAuthorization = request.headers.get("authorization") || "";
  if (!expectedToken) return json(503, { error: "gateway_not_configured" });
  if (!secureEqual(suppliedAuthorization, `Bearer ${expectedToken}`)) {
    return json(401, { error: "unauthorized" }, { "www-authenticate": "Bearer" });
  }

  const contentType = request.headers.get("content-type") || "";
  if (!contentType.toLowerCase().startsWith("application/json")) {
    return json(415, { error: "content_type_must_be_json" });
  }

  let raw;
  try {
    raw = await readBodyLimited(request);
  } catch (error) {
    if (error instanceof RangeError) return json(413, { error: "request_too_large" });
    return json(400, { error: "request_body_unreadable" });
  }

  let payload;
  try {
    payload = JSON.parse(raw);
  } catch {
    return json(400, { error: "invalid_json" });
  }

  const validationError = validatePayload(payload);
  if (validationError) return json(400, { error: validationError });

  const upstreamBase = normalizeUpstreamBase(process.env.FARAST_CLOUDFLARE_AI_GATEWAY_URL || "");
  const upstreamToken = process.env.FARAST_CLOUDFLARE_AI_GATEWAY_TOKEN || "";
  if (!upstreamBase || !upstreamToken) return json(503, { error: "upstream_not_configured" });

  const tokenLimit = task === "review" ? 2200 : 1024;
  const requested = Number(payload.max_tokens ?? payload.max_completion_tokens ?? tokenLimit);
  const forwardedPayload = {
    ...payload,
    model: task === "review" ? "farast-review" : "farast-general",
    max_tokens: Math.min(tokenLimit, Math.trunc(requested)),
  };
  delete forwardedPayload.max_completion_tokens;

  const upstreamPath = task === "review"
    ? "/v1/chat/completions/review"
    : "/v1/chat/completions";

  try {
    const upstream = await fetch(`${upstreamBase}${upstreamPath}`, {
      method: "POST",
      headers: {
        authorization: `Bearer ${upstreamToken}`,
        "content-type": "application/json",
        accept: forwardedPayload.stream ? "text/event-stream" : "application/json",
      },
      body: JSON.stringify(forwardedPayload),
      redirect: "manual",
      signal: AbortSignal.timeout(55_000),
    });

    if (upstream.status >= 300 && upstream.status < 400) {
      return json(502, { error: "upstream_redirect_rejected" });
    }

    const headers = new Headers({
      "cache-control": "no-store, private",
      "content-type": upstream.headers.get("content-type") || "application/json",
      "x-content-type-options": "nosniff",
      "x-farast-gateway": "netlify-fallback",
    });
    const retryAfter = upstream.headers.get("retry-after");
    if (retryAfter) headers.set("retry-after", retryAfter);

    return new Response(upstream.body, { status: upstream.status, headers });
  } catch (error) {
    if (error?.name === "TimeoutError" || error?.name === "AbortError") {
      return json(504, { error: "upstream_timeout" }, { "retry-after": "30" });
    }
    return json(503, { error: "upstream_unavailable" }, { "retry-after": "30" });
  }
}
