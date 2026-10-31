import json
import threading
import urllib.request

from server import Handler, ThreadingHTTPServer

server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
base = f"http://127.0.0.1:{server.server_port}"

try:
    with urllib.request.urlopen(f"{base}/health", timeout=10) as response:
        health = json.loads(response.read())
        if response.status != 200 or health.get("engine") != "piper-mana-local":
            raise SystemExit("piper_tts_health_failed")

    request = urllib.request.Request(
        f"{base}/v1/synthesize",
        data=json.dumps({"text": "سلام، به دستیار صوتی فراست خوش آمدید."}).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=60) as response:
        audio = response.read()
        if response.status != 200 or response.headers.get_content_type() != "audio/wav":
            raise SystemExit("piper_tts_http_contract_failed")
        if response.headers.get("X-Farast-TTS-Provider") != "piper-mana-local":
            raise SystemExit("piper_tts_provider_header_missing")
        if len(audio) < 1024 or audio[:4] != b"RIFF" or audio[8:12] != b"WAVE":
            raise SystemExit("piper_tts_invalid_wav")

    print(f"Piper Persian TTS HTTP smoke passed: {len(audio)} WAV bytes")
finally:
    server.shutdown()
    server.server_close()
