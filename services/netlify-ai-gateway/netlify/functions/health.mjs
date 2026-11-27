export default async function health(request) {
  if (request.method !== "GET") {
    return new Response(JSON.stringify({ error: "method_not_allowed" }), {
      status: 405,
      headers: { "content-type": "application/json", allow: "GET", "cache-control": "no-store" },
    });
  }
  return new Response(JSON.stringify({ service: "farast-netlify-ai-gateway", status: "ok" }), {
    status: 200,
    headers: { "content-type": "application/json; charset=utf-8", "cache-control": "no-store" },
  });
}
