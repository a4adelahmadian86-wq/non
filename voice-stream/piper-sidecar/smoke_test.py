import base64
import json
import os
import threading
import urllib.error
import urllib.request

os.environ.setdefault("FARAST_VOICE_TTS_TOKEN", "ci-only-piper-test-token")

from server import Handler, PIPER_TOKEN, ThreadingHTTPServer

server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
base = f"http://127.0.0.1:{server.server_port}"

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

    for unsupported_payload in (
        {"text": "سلام", "language": "en-US", "voice_id": "farast-fa-male-v1"},
        {"text": "سلام", "language": "fa-IR", "voice_id": "unsupported-voice"},
    ):
        unsupported = urllib.request.Request(
            f"{base}/v1/synthesize",
            data=json.dumps(unsupported_payload).encode("utf-8"),
            headers={
                "Content-Type": "application/json",
                "Authorization": f"Bearer {PIPER_TOKEN}",
            },
            method="POST",
        )
        try:
            urllib.request.urlopen(unsupported, timeout=10)
            raise SystemExit("piper_tts_unsupported_voice_was_accepted")
        except urllib.error.HTTPError as error:
            if error.code != 400:
                raise SystemExit("piper_tts_voice_validation_failed") from error

    request = urllib.request.Request(
        f"{base}/v1/synthesize",
        data=json.dumps({
            "text": "سلام، به دستیار صوتی فراست خوش آمدید.",
            "language": "fa-IR",
            "voice_id": "farast-fa-male-v1",
        }).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {PIPER_TOKEN}",
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

    batch_request = urllib.request.Request(
        f"{base}/v1/synthesize/segments",
        data=json.dumps({
            "language": "fa-IR",
            "voice_id": "farast-fa-male-v1",
            "segments": [
                {"text": "این بخش نخست سند است."},
                {"text": "این بخش دوم سند است."},
            ],
        }).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {PIPER_TOKEN}",
        },
        method="POST",
    )
    with urllib.request.urlopen(batch_request, timeout=90) as response:
        batch_audio = response.read()
        encoded_timings = response.headers.get("X-Farast-Segment-Timings")
        if response.status != 200 or response.headers.get_content_type() != "audio/wav":
            raise SystemExit("piper_tts_segment_http_contract_failed")
        if response.headers.get("X-Farast-TTS-Provider") != "piper-mana-local":
            raise SystemExit("piper_tts_segment_provider_header_missing")
        if not encoded_timings or len(batch_audio) < 1024 or batch_audio[:4] != b"RIFF" or batch_audio[8:12] != b"WAVE":
            raise SystemExit("piper_tts_segment_audio_invalid")
        timings = json.loads(base64.urlsafe_b64decode(encoded_timings.encode("ascii")))
        if len(timings) != 2 or timings[0]["start_ms"] != 0 or timings[1]["start_ms"] <= timings[0]["end_ms"]:
            raise SystemExit("piper_tts_segment_timing_invalid")

    print(f"Piper Persian TTS authenticated HTTP smoke passed: {len(audio)} WAV bytes; aligned batch {len(batch_audio)} bytes")
finally:
    server.shutdown()
    server.server_close()
