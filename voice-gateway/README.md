# FARAST Voice Gateway

Standalone WebSocket process for live voice typing. Browser receives only a short-lived FARAST session token; provider credentials are fetched server-to-server from Laravel and never sent to the browser.

Environment: FARAST_BASE_URL, FARAST_GATEWAY_SECRET, PORT (default 6002).

Native adapters: Google Cloud Speech and Azure Speech. Unsupported providers fail closed instead of pretending to transcribe.
