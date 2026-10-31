from __future__ import annotations

import base64
import hmac
import hashlib
import io
import json
import os
import secrets
import struct
import tempfile
import time
from pathlib import Path
from threading import Lock
from typing import Optional

import soundfile as sf
import httpx
from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, UploadFile, WebSocket, WebSocketDisconnect
from fastapi.responses import Response
from pydantic import BaseModel, Field

APP_NAME = "FARAST Self-hosted Voice"
API_TOKEN = os.getenv("FARAST_VOICE_TOKEN", "").strip()
STREAM_APP_KEY = os.getenv("APP_KEY", "").strip()
STREAM_ORIGINS = {item.strip() for item in os.getenv("VOICE_STREAM_ORIGINS", "").split(",") if item.strip()}
STREAM_APP_URL = os.getenv("FARAST_APP_URL", "").strip().rstrip("/")
STREAM_GATEWAY_SECRET = os.getenv("FARAST_VOICE_GATEWAY_SECRET", "").strip()
ASR_MODEL_NAME = os.getenv("FARAST_ASR_MODEL", "small")
ASR_DEVICE = os.getenv("FARAST_ASR_DEVICE", "cpu")
ASR_COMPUTE_TYPE = os.getenv("FARAST_ASR_COMPUTE_TYPE", "int8")
TTS_MODEL_PATH = os.getenv("FARAST_TTS_MODEL_PATH", "/models/persian-tts-male-vits/best_model_91323.pth")
TTS_CONFIG_PATH = os.getenv("FARAST_TTS_CONFIG_PATH", "/models/persian-tts-male-vits/config.json")
TTS_AUTO_DOWNLOAD = os.getenv("FARAST_TTS_AUTO_DOWNLOAD", "true").lower() in {"1", "true", "yes"}
TTS_MODEL_REPO = os.getenv("FARAST_TTS_MODEL_REPO", "Kamtera/persian-tts-male-vits")
VOICE_ID = os.getenv("FARAST_VOICE_ID", "farast-fa-male-v1")
MAX_AUDIO_BYTES = int(os.getenv("FARAST_MAX_AUDIO_BYTES", str(15 * 1024 * 1024)))
MAX_TEXT_CHARS = int(os.getenv("FARAST_MAX_TEXT_CHARS", "1200"))

app = FastAPI(title=APP_NAME, version="0.1.0")
model_lock = Lock()
asr_model = None
tts_model = None
tts_error: Optional[str] = None


def authorize(authorization: Optional[str] = Header(default=None)) -> None:
    if not API_TOKEN:
        raise HTTPException(status_code=503, detail="Voice service is not configured: FARAST_VOICE_TOKEN is missing")
    expected = f"Bearer {API_TOKEN}"
    if not authorization or not secrets.compare_digest(authorization, expected):
        raise HTTPException(status_code=401, detail="Unauthorized")


class SynthesizeRequest(BaseModel):
    text: str = Field(min_length=1, max_length=MAX_TEXT_CHARS)
    language: str = "fa-IR"
    voice_id: str = VOICE_ID


@app.on_event("startup")
def load_models() -> None:
    global asr_model, tts_model, tts_error
    # Model initialization is explicit. Missing or incompatible models fail closed;
    # no browser speech, public inference API, or alternate voice is used as fallback.
    try:
        from faster_whisper import WhisperModel
        asr_model = WhisperModel(
            ASR_MODEL_NAME,
            device=ASR_DEVICE,
            compute_type=ASR_COMPUTE_TYPE,
            cpu_threads=max(1, int(os.getenv("FARAST_ASR_CPU_THREADS", "2"))),
        )
    except Exception as exc:
        app.logger if hasattr(app, "logger") else None
        asr_model = None
        print(f"ASR_INIT_FAILED: {type(exc).__name__}: {exc}", flush=True)

    try:
        model_path = Path(TTS_MODEL_PATH)
        config_path = Path(TTS_CONFIG_PATH)
        if TTS_AUTO_DOWNLOAD and (not model_path.is_file() or not config_path.is_file()):
            # Download model files only; speech inference remains on this host.
            # Persistent /models storage prevents redownloading the ~1 GB checkpoint on restarts.
            from huggingface_hub import hf_hub_download
            model_path.parent.mkdir(parents=True, exist_ok=True)
            if not model_path.is_file():
                hf_hub_download(
                    repo_id=TTS_MODEL_REPO,
                    filename="best_model_91323.pth",
                    local_dir=str(model_path.parent),
                )
            if not config_path.is_file():
                hf_hub_download(
                    repo_id=TTS_MODEL_REPO,
                    filename="config.json",
                    local_dir=str(config_path.parent),
                )
        if not model_path.is_file() or not config_path.is_file():
            raise FileNotFoundError(
                "Persian TTS model files are missing. Verify persistent /models storage and model download access."
            )
        from TTS.api import TTS
        tts_model = TTS(model_path=str(model_path), config_path=str(config_path), progress_bar=False, gpu=False)
        tts_error = None
    except Exception as exc:
        tts_model = None
        tts_error = f"{type(exc).__name__}: {exc}"
        print(f"TTS_INIT_FAILED: {tts_error}", flush=True)


@app.get("/health")
def health() -> dict:
    # Public health response intentionally contains no secrets or internal paths.
    return {
        "service": "farast-voice",
        "status": "ok",
        "asr_ready": asr_model is not None,
        "tts_ready": tts_model is not None,
        "voice_id": VOICE_ID,
        "persistent_models_required": True,
    }


@app.get("/v1/status", dependencies=[Depends(authorize)])
def status() -> dict:
    return {
        "asr_ready": asr_model is not None,
        "tts_ready": tts_model is not None,
        "voice_id": VOICE_ID,
        "tts_error": tts_error if tts_model is None else None,
    }


@app.post("/v1/transcribe", dependencies=[Depends(authorize)])
async def transcribe(
    audio: UploadFile = File(...),
    locale: str = Form("fa-IR"),
) -> dict:
    if asr_model is None:
        raise HTTPException(status_code=503, detail="ASR model is not ready; inspect service logs")
    if locale not in {"fa-IR", "en-US", "ar-SA"}:
        raise HTTPException(status_code=400, detail="Unsupported locale")
    data = await audio.read(MAX_AUDIO_BYTES + 1)
    if not data:
        raise HTTPException(status_code=400, detail="Audio file is empty")
    if len(data) > MAX_AUDIO_BYTES:
        raise HTTPException(status_code=413, detail="Audio exceeds configured size limit")

    suffix = Path(audio.filename or "recording.webm").suffix.lower()
    if suffix not in {".webm", ".wav", ".mp3", ".m4a", ".mp4", ".ogg", ".opus", ".mpeg"}:
        suffix = ".audio"
    try:
        with tempfile.NamedTemporaryFile(suffix=suffix) as tmp:
            tmp.write(data)
            tmp.flush()
            language = {"fa-IR": "fa", "en-US": "en", "ar-SA": "ar"}[locale]
            with model_lock:
                segments, _info = asr_model.transcribe(
                    tmp.name,
                    language=language,
                    vad_filter=True,
                    beam_size=3,
                    condition_on_previous_text=False,
                )
                text = " ".join(segment.text.strip() for segment in segments).strip()
        return {"text": text}
    except Exception as exc:
        print(f"ASR_REQUEST_FAILED: {type(exc).__name__}: {exc}", flush=True)
        raise HTTPException(status_code=422, detail="Could not decode or transcribe this audio") from exc


@app.post("/v1/synthesize", dependencies=[Depends(authorize)])
def synthesize(request: SynthesizeRequest) -> Response:
    if tts_model is None:
        raise HTTPException(status_code=503, detail="Fixed Persian TTS model is not ready; inspect service logs")
    if request.language != "fa-IR" or request.voice_id != VOICE_ID:
        raise HTTPException(status_code=400, detail="Only the configured fixed Persian voice is supported")
    text = request.text.strip()
    if not text:
        raise HTTPException(status_code=400, detail="Text is empty")
    try:
        with model_lock:
            wav = tts_model.tts(text=text)
        sample_rate = int(getattr(tts_model.synthesizer, "output_sample_rate", 22050))
        output = io.BytesIO()
        sf.write(output, wav, sample_rate, format="WAV", subtype="PCM_16")
        return Response(
            content=output.getvalue(),
            media_type="audio/wav",
            headers={"Cache-Control": "no-store", "X-Farast-Voice-Id": VOICE_ID},
        )
    except Exception as exc:
        print(f"TTS_REQUEST_FAILED: {type(exc).__name__}: {exc}", flush=True)
        raise HTTPException(status_code=500, detail="Speech synthesis failed") from exc


def verify_stream_token(token: str) -> Optional[dict]:
    """Verify the short-lived Laravel HMAC token without exposing API credentials."""
    if not STREAM_APP_KEY or not token or "." not in token:
        return None
    encoded, signature = token.split(".", 1)
    if not encoded or not signature:
        return None
    expected = hmac.new(STREAM_APP_KEY.encode(), encoded.encode(), "sha256").hexdigest()
    if not hmac.compare_digest(expected, signature):
        return None
    try:
        padded = encoded + "=" * ((4 - len(encoded) % 4) % 4)
        payload = json.loads(base64.urlsafe_b64decode(padded.encode()).decode("utf-8"))
    except (ValueError, UnicodeDecodeError, json.JSONDecodeError):
        return None
    if not isinstance(payload, dict):
        return None
    if not payload.get("uid") or not payload.get("nonce"):
        return None
    if payload.get("locale") not in {"fa-IR", "en-US", "ar-SA"}:
        return None
    try:
        expires_at = int(payload.get("exp", 0))
    except (TypeError, ValueError):
        return None
    if expires_at < int(time.time()):
        return None
    return payload


def _transcribe_pcm_chunk(raw: bytes, language: str) -> str:
    """Run one bounded 16 kHz mono PCM chunk through the already-loaded local model."""
    import numpy as np

    samples = np.frombuffer(raw, dtype=np.int16).astype(np.float32) / 32768.0
    if samples.size < 8000 or asr_model is None:
        return ""
    with model_lock:
        segments, _info = asr_model.transcribe(
            samples,
            language=language,
            vad_filter=True,
            beam_size=3,
            condition_on_previous_text=False,
        )
        return " ".join(segment.text.strip() for segment in segments).strip()


def is_allowed_stream_origin(origin: str) -> bool:
    return bool(origin and STREAM_ORIGINS and origin in STREAM_ORIGINS)


@app.websocket("/v1/stream")
async def live_stream(websocket: WebSocket) -> None:
    """Receive 16 kHz PCM frames and return local Whisper chunk transcripts."""
    origin = str(websocket.headers.get("origin", "")).strip()
    if not is_allowed_stream_origin(origin):
        await websocket.close(code=1008)
        return
    await websocket.accept()
    reservation_id = None
    usage_reported = False
    audio_seconds = 0.0
    input_bytes = 0
    transcript_count = 0
    started_at = time.monotonic()
    try:
        start = await asyncio.wait_for(websocket.receive_json(), timeout=10)
        token = str(start.get("token", "")) if isinstance(start, dict) else ""
        locale = str(start.get("locale", "")) if isinstance(start, dict) else ""
        payload = verify_stream_token(token)
        if not payload or payload.get("locale") != locale:
            await websocket.send_json({"type": "error", "code": "unauthorized"})
            await websocket.close(code=1008)
            return
        if not STREAM_APP_URL or not STREAM_GATEWAY_SECRET:
            await websocket.send_json({"type": "error", "code": "stream_gateway_not_configured"})
            await websocket.close(code=1013)
            return
        gateway_signature = hmac.new(STREAM_GATEWAY_SECRET.encode(), token.encode(), hashlib.sha256).hexdigest()
        async with httpx.AsyncClient(timeout=httpx.Timeout(8.0, connect=3.0)) as client:
            config_response = await client.post(
                STREAM_APP_URL + "/editor/voice/stream-config",
                json={"token": token},
                headers={"X-Farast-Voice-Gateway": gateway_signature, "Accept": "application/json"},
            )
        config_data = config_response.json() if config_response.is_success else {}
        reservation_id = str(config_data.get("reservation_id") or "")
        if not config_response.is_success or not config_data.get("gateway") or not reservation_id:
            await websocket.send_json({"type": "error", "code": "voice_quota_unavailable"})
            await websocket.close(code=1008)
            return
        if asr_model is None:
            await websocket.send_json({"type": "error", "code": "asr_not_ready"})
            await websocket.close(code=1013)
            return

        language = {"fa-IR": "fa", "en-US": "en", "ar-SA": "ar"}[locale]
        await websocket.send_json({"type": "ready", "sample_rate": 16000, "format": "pcm_s16le", "provider": "self-hosted-whisper"})
        buffer = bytearray()
        silence_bytes = 0
        speech_seen = False
        max_chunk_bytes = 16000 * 2 * 5
        silence_limit_bytes = 24000  # 750 ms at 16 kHz, mono, signed 16-bit PCM.

        async def report_usage(success: bool) -> None:
            nonlocal usage_reported
            if not reservation_id or usage_reported:
                return
            usage_reported = True
            try:
                async with httpx.AsyncClient(timeout=httpx.Timeout(8.0, connect=3.0)) as client:
                    await client.post(
                        STREAM_APP_URL + "/editor/voice/stream-usage",
                        json={
                            "token": token,
                            "reservation_id": reservation_id,
                            "audio_seconds": audio_seconds,
                            "input_bytes": input_bytes,
                            "success": bool(success),
                            "latency_ms": int((time.monotonic() - started_at) * 1000),
                        },
                        headers={"X-Farast-Voice-Gateway": gateway_signature, "Accept": "application/json"},
                    )
            except Exception as exc:
                print(f"LIVE_USAGE_REPORT_FAILED: {type(exc).__name__}", flush=True)

        async def flush_chunk() -> None:
            nonlocal buffer, silence_bytes, speech_seen, transcript_count
            raw = bytes(buffer)
            buffer.clear()
            silence_bytes = 0
            speech_seen = False
            if len(raw) < 16000:
                return
            try:
                transcript = await asyncio.to_thread(_transcribe_pcm_chunk, raw, language)
            except Exception as exc:
                print(f"LIVE_ASR_FAILED: {type(exc).__name__}", flush=True)
                await websocket.send_json({"type": "provider_error", "provider": "self-hosted-whisper", "code": "local_asr_failed"})
                return
            if transcript:
                transcript_count += 1
                await websocket.send_json({"type": "final", "text": transcript, "provider": "self-hosted-whisper"})

        while True:
            message = await websocket.receive()
            if message.get("type") == "websocket.disconnect":
                break
            if message.get("text") is not None:
                try:
                    command = json.loads(message["text"])
                except (TypeError, ValueError):
                    continue
                if isinstance(command, dict) and command.get("type") == "stop":
                    await flush_chunk()
                    await report_usage(transcript_count > 0)
                    break
                continue

            frame = message.get("bytes")
            if not frame:
                continue
            if len(frame) > 48000:
                await websocket.send_json({"type": "error", "code": "audio_frame_too_large"})
                await websocket.close(code=1009)
                return
            frame = frame[: len(frame) - (len(frame) % 2)]
            if not frame:
                continue
            audio_seconds += len(frame) / 32000.0
            input_bytes += len(frame)
            mean_abs = sum(abs(sample[0]) for sample in struct.iter_unpack("<h", frame)) / max(1, len(frame) // 2)
            if mean_abs >= 420:
                speech_seen = True
                silence_bytes = 0
                buffer.extend(frame)
            elif speech_seen:
                buffer.extend(frame)
                silence_bytes += len(frame)

            if speech_seen and (silence_bytes >= silence_limit_bytes or len(buffer) >= max_chunk_bytes):
                await flush_chunk()

        await report_usage(transcript_count > 0)
        await websocket.close(code=1000)
    except WebSocketDisconnect:
        return
    except asyncio.TimeoutError:
        try:
            await websocket.send_json({"type": "error", "code": "stream_start_timeout"})
            await websocket.close(code=1008)
        except Exception:
            pass
    except Exception as exc:
        print(f"LIVE_STREAM_FAILED: {type(exc).__name__}", flush=True)
        try:
            await websocket.send_json({"type": "error", "code": "live_stream_failed"})
            await websocket.close(code=1011)
        except Exception:
            pass
