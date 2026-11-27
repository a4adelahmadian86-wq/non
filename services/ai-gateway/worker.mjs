const MAX_BODY_BYTES = 512 * 1024;
const MAX_MESSAGES = 64;
const MAX_OUTPUT_TOKENS = 1024;
const DEFAULT_MODEL = "@cf/openai/gpt-oss-20b";

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

function sanitizePayload(payload, model) {
    const output = { ...payload, model };
    const requested = Number(payload.max_tokens ?? payload.max_completion_tokens ?? 1024);
    output.max_tokens = Number.isFinite(requested)
        ? Math.max(1, Math.min(MAX_OUTPUT_TOKENS, Math.trunc(requested)))
        : MAX_OUTPUT_TOKENS;
    delete output.max_completion_tokens;
    return output;
}

export default {
    async fetch(request, env) {
        const url = new URL(request.url);

        if (request.method === "GET" && url.pathname === "/health") {
            return json(200, { ok: true, service: "farast-ai-gateway" });
        }

        if (request.method === "GET" && url.pathname === "/ready") {
            const ready = Boolean(
                env.FARAST_AI_GATEWAY_TOKEN &&
                env.CF_API_TOKEN &&
                validAccountId(env.CF_ACCOUNT_ID)
            );
            return json(ready ? 200 : 503, {
                ok: ready,
                provider: "cloudflare-workers-ai",
                model: env.CF_MODEL || DEFAULT_MODEL,
            });
        }

        if (request.method !== "POST" || url.pathname !== "/v1/chat/completions") {
            return json(404, { error: { message: "not_found" } });
        }

        const expectedToken = String(env.FARAST_AI_GATEWAY_TOKEN || "");
        const suppliedToken = bearerToken(request);
        if (!expectedToken || !constantTimeEqual(suppliedToken, expectedToken)) {
            return json(401, { error: { message: "unauthorized" } });
        }

        if (!env.CF_API_TOKEN || !validAccountId(env.CF_ACCOUNT_ID)) {
            return json(503, { error: { message: "ai_gateway_not_configured" } });
        }

        const contentLength = Number(request.headers.get("Content-Length") || 0);
        if (contentLength > MAX_BODY_BYTES) {
            return json(413, { error: { message: "request_too_large" } });
        }

        let payload;
        try {
            const raw = await request.text();
            if (new TextEncoder().encode(raw).byteLength > MAX_BODY_BYTES) {
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

        const model = String(env.CF_MODEL || DEFAULT_MODEL);
        const upstreamUrl = `https://api.cloudflare.com/client/v4/accounts/${env.CF_ACCOUNT_ID}/ai/v1/chat/completions`;
        const headers = {
            Authorization: `Bearer ${env.CF_API_TOKEN}`,
            "Content-Type": "application/json",
            Accept: request.headers.get("Accept") || "application/json",
            "cf-aig-collect-log": "false",
            "cf-aig-collect-log-payload": "false",
        };
        if (env.CF_AI_GATEWAY_ID) {
            headers["cf-aig-gateway-id"] = String(env.CF_AI_GATEWAY_ID);
        }

        try {
            const upstream = await fetch(upstreamUrl, {
                method: "POST",
                headers,
                body: JSON.stringify(sanitizePayload(payload, model)),
            });
            const responseHeaders = new Headers({
                "Content-Type": upstream.headers.get("Content-Type") || "application/json",
                "Cache-Control": "no-store",
                "X-Accel-Buffering": "no",
                "X-Farast-AI-Provider": "cloudflare-workers-ai",
                "X-Farast-AI-Model": model,
            });
            return new Response(upstream.body, {
                status: upstream.status,
                headers: responseHeaders,
            });
        } catch {
            return json(503, { error: { message: "ai_upstream_unavailable" } });
        }
    },
};
