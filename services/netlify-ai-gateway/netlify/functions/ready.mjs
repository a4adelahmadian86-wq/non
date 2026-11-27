function configured() {
  const incoming = process.env.FARAST_NETLIFY_GATEWAY_TOKEN || "";
  const upstreamToken = process.env.FARAST_CLOUDFLARE_AI_GATEWAY_TOKEN || "";
  const rawUrl = process.env.FARAST_CLOUDFLARE_AI_GATEWAY_URL || "";
  try {
    const url = new URL(rawUrl);
    return incoming.length >= 32 &&
      upstreamToken.length >= 32 &&
      incoming !== upstreamToken &&
      url.protocol === "https:" &&
      !url.username &&
      !url.password &&
      !url.search &&
      !url.hash &&
      (url.pathname === "/" || url.pathname === "");
  } catch {
    return false;
  }
}

export default async function ready(request) {
  if (request.method !== "GET") {
    return new Response(JSON.stringify({ error: "method_not_allowed" }), {
      status: 405,
      headers: { "content-type": "application/json", allow: "GET", "cache-control": "no-store" },
    });
  }
  const isReady = configured();
  return new Response(JSON.stringify({
    service: "farast-netlify-ai-gateway",
    status: isReady ? "ready" : "not_configured",
    configured: isReady,
  }), {
    status: isReady ? 200 : 503,
    headers: { "content-type": "application/json; charset=utf-8", "cache-control": "no-store" },
  });
}
