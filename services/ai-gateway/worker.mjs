const MAX_CHAT_BODY_BYTES = 512 * 1024;
const MAX_MULTIMODAL_BODY_BYTES = 40 * 1024 * 1024;
const MAX_MESSAGES = 64;
const MAX_CHAT_OUTPUT_TOKENS = 1024;
const MAX_REVIEW_OUTPUT_TOKENS = 2200;

const DEFAULT_MODELS = {
    text: "@cf/openai/gpt-oss-20b",
    review: "@cf/openai/gpt-oss-120b",
    vision: "@cf/moondream/moondream3.1-9B-A2B",
    audio: "@cf/openai/whisper-large-v3-turbo",
};

function json(status, payload) {
    return new Response(JSON.stringify(payload), {
        status,
        headers: {
            "Content-Type": "application/json; charset=utf-8",
            "Cache-Control": "no-store",
        },
    });
}

function constantTimeEqual(left, right) {
    if (typeof left !== "string" || typeof right !== "string" || left.length !== right.length) {
        return false;
    }
    let mismatch = 0;
    for (let index = 0; index < left.length; index += 1) {
        mismatch |= left.charCodeAt(index) ^ right.charCodeAt(index);
    }
    return mismatch === 0;
}

function bearerToken(request) {
    const header = request.headers.get("Authorization") || "";
    return header.startsWith("Bearer ") ? header.slice(7).trim() : "";
}

function validAccountId(value) {
    return typeof value === "string" && /^[a-f0-9]{32}$/i.test(value);
}

function modelForTask(task, env) {
    if (task === "text") return String(env.CF_TEXT_MODEL || DEFAULT_MODELS.text);
    if (task === "review") return String(env.CF_REVIEW_MODEL || DEFAULT_MODELS.review);
    if (task === "vision") return String(env.CF_VISION_MODEL || DEFAULT_MODELS.vision);
    if (task === "audio") return String(env.CF_AUDIO_MODEL || DEFAULT_MODELS.audio);
    return "";
}

function upstreamHeaders(env, contentType = "application/json") {
    const headers = {
        Authorization: `Bearer ${env.CF_API_TOKEN}`,
        "Content-Type": contentType,
        Accept: "application/json",
    };
    return headers;
}

function upstreamUrl(env, path) {
    return `https://api.cloudflare.com/client/v4/accounts/${env.CF_ACCOUNT_ID}${path}`;
}

function sanitizeChatPayload(payload, model, outputLimit) {
    const output = { ...payload, model };
    const requested = Number(payload.max_tokens ?? payload.max_completion_tokens ?? 1800);
    output.max_tokens = Number.isFinite(requested)
        ? Math.max(1, Math.min(outputLimit, Math.trunc(requested)))
        : Math.min(1800, outputLimit);
    delete output.max_completion_tokens;
    return output;
}

function authorized(request, env) {
    const expectedToken = String(env.FARAST_AI_GATEWAY_TOKEN || "");
    return Boolean(expectedToken && constantTimeEqual(bearerToken(request), expectedToken));
}

function configured(env) {
    return Boolean(env.CF_API_TOKEN && validAccountId(env.CF_ACCOUNT_ID));
}

function passthrough(upstream, model, provider = "cloudflare-workers-ai") {
    return new Response(upstream.body, {
        status: upstream.status,
        headers: new Headers({
            "Content-Type": upstream.headers.get("Content-Type") || "application/json",
            "Cache-Control": "no-store",
            "X-Accel-Buffering": "no",
            "X-Farast-AI-Provider": provider,
            "X-Farast-AI-Model": model,
        }),
    });
}

export default {
    async fetch(request, env) {
        const url = new URL(request.url);

        if (request.method === "GET" && url.pathname === "/health") {
            return json(200, { ok: true, service: "farast-ai-gateway" });
        }

        if (request.method === "GET" && url.pathname === "/ready") {
            if (!authorized(request, env)) {
                return json(401, { error: { message: "unauthorized" } });
            }
            const ready = configured(env);
            return json(ready ? 200 : 503, {
                ok: ready,
                provider: "cloudflare-workers-ai",
                models: {
                    text: modelForTask("text", env),
                    review: modelForTask("review", env),
                    vision: modelForTask("vision", env),
                    audio: modelForTask("audio", env),
                },
            });
        }

        if (request.method !== "POST") {
            return json(404, { error: { message: "not_found" } });
        }

        if (!authorized(request, env)) {
            return json(401, { error: { message: "unauthorized" } });
        }

        if (!configured(env)) {
            return json(503, { error: { message: "ai_gateway_not_configured" } });
        }

        const chatTask = url.pathname === "/v1/chat/completions/review" ? "review" : (url.pathname === "/v1/chat/completions" ? "text" : "");
        if (chatTask) {
            const contentLength = Number(request.headers.get("Content-Length") || 0);
            if (contentLength > MAX_CHAT_BODY_BYTES) {
                return json(413, { error: { message: "request_too_large" } });
            }

            let payload;
            try {
                const raw = await request.text();
                if (new TextEncoder().encode(raw).byteLength > MAX_CHAT_BODY_BYTES) {
                    return json(413, { error: { message: "request_too_large" } });
                }
                payload = JSON.parse(raw);
            } catch {
                return json(400, { error: { message: "invalid_json" } });
            }

            if (
                !payload ||
                typeof payload !== "object" ||
                !Array.isArray(payload.messages) ||
                payload.messages.length === 0 ||
                payload.messages.length > MAX_MESSAGES
            ) {
                return json(422, { error: { message: "messages_required_or_invalid" } });
            }

            const model = modelForTask(chatTask, env);
            try {
                const upstream = await fetch(upstreamUrl(env, "/ai/v1/chat/completions"), {
                    method: "POST",
                    headers: upstreamHeaders(env),
                    body: JSON.stringify(sanitizeChatPayload(payload, model, chatTask === "review" ? MAX_REVIEW_OUTPUT_TOKENS : MAX_CHAT_OUTPUT_TOKENS)),
                });
                return passthrough(upstream, model);
            } catch {
                return json(503, { error: { message: "ai_upstream_unavailable" } });
            }
        }

        const taskMatch = url.pathname.match(/^\/v1\/ai\/run\/(vision|audio)$/);
        if (taskMatch) {
            const task = taskMatch[1];
            const contentLength = Number(request.headers.get("Content-Length") || 0);
            if (contentLength > MAX_MULTIMODAL_BODY_BYTES) {
                return json(413, { error: { message: "request_too_large" } });
            }
            if (!request.body || !/^application\/json(?:;|$)/i.test(request.headers.get("Content-Type") || "")) {
                return json(415, { error: { message: "json_body_required" } });
            }

            const model = modelForTask(task, env);
            try {
                // Stream the bounded JSON body through; do not materialize base64 audio/image data in Worker memory.
                const upstream = await fetch(upstreamUrl(env, `/ai/run/${model.split("/").map(encodeURIComponent).join("/")}`), {
                    method: "POST",
                    headers: upstreamHeaders(env),
                    body: request.body,
                });
                return passthrough(upstream, model);
            } catch {
                return json(503, { error: { message: "ai_upstream_unavailable" } });
            }
        }

        return json(404, { error: { message: "not_found" } });
    },
};
