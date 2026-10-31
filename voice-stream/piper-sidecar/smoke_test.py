import json
import os
import threading
import urllib.error
import urllib.request

os.environ.setdefault("PIPER_TTS_TOKEN", "ci-only-piper-test-token")

from server import Handler, PIPER_TTS_TOKEN, ThreadingHTTPServer

http_server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
thread = threading.Thread(target=http_server.serve_forever, daemon=True)
thread.start()
base = f"http://127.0.0.1:{http_server.server_port}"

try:
    with urllib.request.urlopen(f"{base}/health", timeout=10) as response:
        health = json.loads(response.read())
        if response.status != 200 or health.get("engine") != "piper-mana-local":
            raise SystemExit("piper_tts_health_failed")

    unauthenticated = urllib.request.Request(
        f"{base}/v1/synthesize",
        data=json.dumps({"text": "سلام"}).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        urllib.request.urlopen(unauthenticated, timeout=10)
        raise SystemExit("piper_tts_unauthenticated_request_was_accepted")
    except urllib.error.HTTPError as error:
        if error.code != 401:
            raise SystemExit("piper_tts_auth_contract_failed") from error

    request = urllib.request.Request(
        f"{base}/v1/synthesize",
        data=json.dumps({
            "text": "سلام، به دستیار صوتی فراست خوش آمدید.",
            "language": "fa-IR",
            "voice_id": "farast-fa-male-v1",
        }).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {PIPER_TTS_TOKEN}",
        },
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
    http_server.shutdown()
    http_server.server_close()
