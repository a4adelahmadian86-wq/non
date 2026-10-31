import hmac
import io
import json
import os
import threading
import wave
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from piper import PiperVoice

HOST = os.getenv("PIPER_HOST", "0.0.0.0")
PORT = int(os.getenv("PIPER_PORT", "7007"))
MODEL_DIR = Path(os.getenv("PIPER_MODEL_DIR", "/models"))
MODEL_PATH = MODEL_DIR / "fa_IR-mana-medium.onnx"
MAX_TEXT_LENGTH = 6000
VOICE_ID = "farast-fa-male-v1"

if not MODEL_PATH.is_file() or not MODEL_PATH.with_suffix(".onnx.json").is_file():
    raise RuntimeError(f"piper_model_missing:{MODEL_PATH}")

VOICE = PiperVoice.load(str(MODEL_PATH), use_cuda=False)
SYNTHESIS_LOCK = threading.Lock()


class Handler(BaseHTTPRequestHandler):
    server_version = "FARAST-Piper/1.1"

    def send_json(self, status: int, payload: dict) -> None:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def authorized(self) -> bool:
        token = os.getenv("FARAST_VOICE_TTS_TOKEN") or os.getenv("FARAST_VOICE_TOKEN") or ""
        if not token:
            return True
        supplied = self.headers.get("Authorization", "")
        if supplied.startswith("Bearer "):
            supplied = supplied[7:].strip()
        else:
            supplied = ""
        return hmac.compare_digest(supplied, token)

    def do_GET(self) -> None:
        if self.path != "/health":
            self.send_json(404, {"ok": False})
            return
        self.send_json(200, {"ok": True, "engine": "piper-mana-local", "locale": "fa-IR", "voice_id": VOICE_ID})

    def do_POST(self) -> None:
        if self.path != "/v1/synthesize":
            self.send_json(404, {"ok": False})
            return
        if not self.authorized():
            self.send_json(401, {"ok": False, "message": "invalid_voice_token"})
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length <= 0 or length > 40 * 1024:
                self.send_json(413, {"ok": False, "message": "invalid_request_size"})
                return
            payload = json.loads(self.rfile.read(length))
            text = str(payload.get("text", "")).strip()
            language = str(payload.get("language", "fa-IR"))
            voice_id = str(payload.get("voice_id", VOICE_ID))
            if language != "fa-IR" or voice_id != VOICE_ID:
                self.send_json(422, {"ok": False, "message": "unsupported_voice"})
                return
            if not text or len(text) > MAX_TEXT_LENGTH:
                self.send_json(422, {"ok": False, "message": "invalid_text"})
                return

            audio_buffer = io.BytesIO()
            with SYNTHESIS_LOCK:
                with wave.open(audio_buffer, "wb") as wav_file:
                    VOICE.synthesize_wav(text, wav_file)
            audio = audio_buffer.getvalue()
            if not audio:
                self.send_json(502, {"ok": False, "message": "empty_audio"})
                return

            self.send_response(200)
            self.send_header("Content-Type", "audio/wav")
            self.send_header("Content-Length", str(len(audio)))
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Farast-TTS-Provider", "piper-mana-local")
            self.send_header("X-Farast-Voice-Id", VOICE_ID)
            self.send_header("X-Content-Type-Options", "nosniff")
            self.end_headers()
            self.wfile.write(audio)
        except (ValueError, TypeError, json.JSONDecodeError):
            self.send_json(400, {"ok": False, "message": "invalid_json"})
        except Exception as error:
            print(f"tts_inference_failed:{type(error).__name__}", flush=True)
            self.send_json(502, {"ok": False, "message": "local_tts_failed"})

    def log_message(self, fmt: str, *args) -> None:
        print("piper_tts: " + (fmt % args), flush=True)


if __name__ == "__main__":
    print(f"FARAST local Persian Piper TTS listening on {HOST}:{PORT}", flush=True)
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()
