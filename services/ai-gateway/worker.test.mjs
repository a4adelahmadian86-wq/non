import test from "node:test";
import assert from "node:assert/strict";
import worker from "./worker.mjs";

const baseEnv = {
    FARAST_AI_GATEWAY_TOKEN: "site-to-gateway-secret",
    CF_API_TOKEN: "cloudflare-upstream-secret",
    CF_ACCOUNT_ID: "0123456789abcdef0123456789abcdef",
    CF_MODEL: "@cf/openai/gpt-oss-20b",
    CF_AI_GATEWAY_ID: "farast",
};

async function withFetch(mock, callback) {
    const original = globalThis.fetch;
    globalThis.fetch = mock;
    try {
        await callback();
    } finally {
        globalThis.fetch = original;
    }
}

test("health is public and reveals no configuration secrets", async () => {
    const response = await worker.fetch(
        new Request("https://gateway.test/health"),
        baseEnv
    );
    assert.equal(response.status, 200);
    assert.deepEqual(await response.json(), {
        ok: true,
        service: "farast-ai-gateway",
    });
});

test("readiness requires both private tokens and a valid account id", async () => {
    const response = await worker.fetch(
        new Request("https://gateway.test/ready"),
        { ...baseEnv, CF_ACCOUNT_ID: "invalid" }
    );
    assert.equal(response.status, 503);
});

test("chat rejects missing or invalid bearer token before upstream access", async () => {
    await withFetch(async () => {
        throw new Error("upstream must not be called");
    }, async () => {
        const response = await worker.fetch(
            new Request("https://gateway.test/v1/chat/completions", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ messages: [{ role: "user", content: "سلام" }] }),
            }),
            baseEnv
        );
        assert.equal(response.status, 401);
    });
});

test("chat forwards only through the gateway and pins model and output limits", async () => {
    await withFetch(async (input, init) => {
        assert.equal(
            String(input),
            "https://api.cloudflare.com/client/v4/accounts/0123456789abcdef0123456789abcdef/ai/v1/chat/completions"
        );
        assert.equal(init.headers.Authorization, "Bearer cloudflare-upstream-secret");
        assert.equal(init.headers["cf-aig-collect-log"], "false");
        assert.equal(init.headers["cf-aig-collect-log-payload"], "false");
        assert.equal(init.headers["cf-aig-gateway-id"], "farast");

        const sent = JSON.parse(init.body);
        assert.equal(sent.model, "@cf/openai/gpt-oss-20b");
        assert.equal(sent.max_tokens, 1024);
        assert.deepEqual(sent.messages, [{ role: "user", content: "سلام" }]);

        return new Response(JSON.stringify({
            id: "chatcmpl-test",
            choices: [{ message: { role: "assistant", content: "سلام" } }],
        }), {
            status: 200,
            headers: { "Content-Type": "application/json" },
        });
    }, async () => {
        const response = await worker.fetch(
            new Request("https://gateway.test/v1/chat/completions", {
                method: "POST",
                headers: {
                    Authorization: "Bearer site-to-gateway-secret",
                    "Content-Type": "application/json",
                },
                body: JSON.stringify({
                    model: "client-selected-model-must-be-ignored",
                    max_tokens: 99999,
                    messages: [{ role: "user", content: "سلام" }],
                }),
            }),
            baseEnv
        );
        assert.equal(response.status, 200);
        assert.equal(response.headers.get("X-Farast-AI-Provider"), "cloudflare-workers-ai");
        assert.equal((await response.json()).id, "chatcmpl-test");
    });
});

test("upstream status and streaming response body are passed through without caching", async () => {
    await withFetch(async (_input, init) => {
        assert.equal(JSON.parse(init.body).stream, true);
        return new Response("data: [DONE]\n\n", {
            status: 429,
            headers: { "Content-Type": "text/event-stream" },
        });
    }, async () => {
        const response = await worker.fetch(
            new Request("https://gateway.test/v1/chat/completions", {
                method: "POST",
                headers: {
                    Authorization: "Bearer site-to-gateway-secret",
                    "Content-Type": "application/json",
                    Accept: "text/event-stream",
                },
                body: JSON.stringify({
                    stream: true,
                    messages: [{ role: "user", content: "سلام" }],
                }),
            }),
            baseEnv
        );
        assert.equal(response.status, 429);
        assert.equal(response.headers.get("Cache-Control"), "no-store");
        assert.equal(await response.text(), "data: [DONE]\n\n");
    });
});

test("chat rejects oversized payloads and invalid message arrays", async () => {
    const oversized = await worker.fetch(
        new Request("https://gateway.test/v1/chat/completions", {
            method: "POST",
            headers: {
                Authorization: "Bearer site-to-gateway-secret",
                "Content-Type": "application/json",
            },
            body: JSON.stringify({ messages: [{ role: "user", content: "x".repeat(600000) }] }),
        }),
        baseEnv
    );
    assert.equal(oversized.status, 413);

    const invalid = await worker.fetch(
        new Request("https://gateway.test/v1/chat/completions", {
            method: "POST",
            headers: {
                Authorization: "Bearer site-to-gateway-secret",
                "Content-Type": "application/json",
            },
            body: JSON.stringify({ messages: [] }),
        }),
        baseEnv
    );
    assert.equal(invalid.status, 422);
});
