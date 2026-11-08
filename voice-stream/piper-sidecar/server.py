import base64
import hmac
import io
import json
import os
import threading
import wave
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from piper import PiperVoice

HOST = os.getenv("PIPER_HOST", "127.0.0.1")
PORT = int(os.getenv("PIPER_PORT", "7007"))
MODEL_DIR = Path(os.getenv("PIPER_MODEL_DIR", str(Path(__file__).resolve().parent / "models")) )
MODEL_PATH = MODEL_DIR / "fa_IR-mana-medium.onnx"
MAX_TEXT_LENGTH = 6000
VOICE_ID = "farast-fa-male-v1"
PIPER_TOKEN = (os.getenv("FARAST_VOICE_TTS_TOKEN") or os.getenv("FARAST_VOICE_TOKEN") or "").strip()

if not PIPER_TOKEN:
    raise RuntimeError("piper_tts_token_missing")

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
        supplied = self.headers.get("Authorization", "")
        if not supplied.startswith("Bearer "):
            return False
        return hmac.compare_digest(supplied[7:].strip(), PIPER_TOKEN)

    def do_GET(self) -> None:
        if self.path != "/health":
            self.send_json(404, {"ok": False})
            return
        self.send_json(200, {"ok": True, "engine": "piper-mana-local", "locale": "fa-IR", "voice_id": VOICE_ID})

    def do_POST(self) -> None:
        if self.path not in {"/v1/synthesize", "/v1/synthesize/segments"}:
            self.send_json(404, {"ok": False})
            return
        segment_mode = self.path.endswith("/segments")
        if not self.authorized():
            self.send_json(401, {"ok": False, "message": "invalid_voice_token"})
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            max_request_bytes = 1024 * 1024 if segment_mode else 40 * 1024
            if length <= 0 or length > max_request_bytes:
                self.send_json(413, {"ok": False, "message": "invalid_request_size"})
                return
            payload = json.loads(self.rfile.read(length))
            if not isinstance(payload, dict):
                self.send_json(400, {"ok": False, "message": "invalid_json"})
                return
            language = str(payload.get("language", "fa-IR"))
            voice_id = str(payload.get("voice_id", VOICE_ID))
            if language != "fa-IR" or voice_id != VOICE_ID:
                self.send_json(400, {"ok": False, "message": "unsupported_voice"})
                return

            segment_timings = []
            if segment_mode:
                segments = payload.get("segments")
                if not isinstance(segments, list) or not 1 <= len(segments) <= 100:
                    self.send_json(422, {"ok": False, "message": "invalid_segments"})
                    return
                normalized = []
                total_chars = 0
                for item in segments:
                    if not isinstance(item, dict):
                        self.send_json(422, {"ok": False, "message": "invalid_segment"})
                        return
                    segment_text = str(item.get("text", "")).strip()
                    if not segment_text or len(segment_text) > 3000:
                        self.send_json(422, {"ok": False, "message": "invalid_segment_text"})
                        return
                    total_chars += len(segment_text)
                    normalized.append(segment_text)
                if total_chars > 60000:
                    self.send_json(413, {"ok": False, "message": "segment_text_limit"})
                    return

                frame_parts = []
                audio_params = None
                total_frames = 0
                with SYNTHESIS_LOCK:
                    for index, segment_text in enumerate(normalized):
                        segment_buffer = io.BytesIO()
                        with wave.open(segment_buffer, "wb") as segment_wav:
                            VOICE.synthesize_wav(segment_text, segment_wav)
                        with wave.open(io.BytesIO(segment_buffer.getvalue()), "rb") as segment_wav:
                            current_params = segment_wav.getparams()
                            if audio_params is None:
                                audio_params = current_params
                            elif (current_params.nchannels, current_params.sampwidth, current_params.framerate) != (audio_params.nchannels, audio_params.sampwidth, audio_params.framerate):
                                self.send_json(502, {"ok": False, "message": "inconsistent_audio_format"})
                                return
                            start_ms = round(total_frames * 1000 / audio_params.framerate)
                            frames = segment_wav.readframes(segment_wav.getnframes())
                            frame_parts.append(frames)
                            total_frames += segment_wav.getnframes()
                            end_ms = round(total_frames * 1000 / audio_params.framerate)
                            segment_timings.append({"segment_index": index, "start_ms": start_ms, "end_ms": end_ms})
                            silence_frames = int(audio_params.framerate * 0.18)
                            if index < len(normalized) - 1 and silence_frames > 0:
                                frame_parts.append(bytes(silence_frames * audio_params.nchannels * audio_params.sampwidth))
                                total_frames += silence_frames

                if audio_params is None:
                    self.send_json(502, {"ok": False, "message": "empty_audio"})
                    return
                audio_buffer = io.BytesIO()
                with wave.open(audio_buffer, "wb") as combined_wav:
                    combined_wav.setnchannels(audio_params.nchannels)
                    combined_wav.setsampwidth(audio_params.sampwidth)
                    combined_wav.setframerate(audio_params.framerate)
                    combined_wav.writeframes(b"".join(frame_parts))
                audio = audio_buffer.getvalue()
            else:
                text = str(payload.get("text", "")).strip()
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
            if segment_mode:
                timing_header = base64.urlsafe_b64encode(json.dumps(segment_timings, separators=(",", ":")).encode("utf-8")).decode("ascii")
                self.send_header("X-Farast-Segment-Timings", timing_header)
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
