from __future__ import annotations

import hmac
import io
import os
import re
import threading
import wave
from pathlib import Path
from typing import Literal

from fastapi import Depends, FastAPI, Header, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel, Field
from piper import PiperVoice

from alignment import append_alignment_metadata, build_word_timings

APP_VERSION = "1.1.0"
VOICE_ID = "farast-document-fa-male-37-v1"
MODEL_DIR = Path(os.getenv("PIPER_MODEL_DIR", str(Path(__file__).resolve().parent / "models")))
MODEL_PATH = MODEL_DIR / "fa_IR-amir-medium.onnx"
CONFIG_PATH = MODEL_DIR / "fa_IR-amir-medium.onnx.json"
API_TOKEN = os.getenv("DOCUMENT_AUDIO_API_TOKEN", "").strip()
MAX_TEXT_CHARS = 6000
MAX_CONCURRENCY = threading.BoundedSemaphore(1)

app = FastAPI(title="Farast Document Audio", version=APP_VERSION, docs_url=None, redoc_url=None)
_voice: PiperVoice | None = None
_voice_lock = threading.Lock()


class SynthesisRequest(BaseModel):
    text: str = Field(min_length=1, max_length=MAX_TEXT_CHARS)
    language: Literal["fa-IR"] = "fa-IR"
    voice_id: str = Field(default=VOICE_ID, min_length=1, max_length=80)


def require_token(authorization: str | None = Header(default=None)) -> None:
    if not API_TOKEN:
        raise HTTPException(status_code=503, detail="audio_service_not_configured")
    supplied = authorization.removeprefix("Bearer ").strip() if authorization else ""
    if not supplied or not hmac.compare_digest(supplied, API_TOKEN):
        raise HTTPException(status_code=401, detail="unauthorized")


def get_voice() -> PiperVoice:
    global _voice
    if _voice is None:
        with _voice_lock:
            if _voice is None:
                if not MODEL_PATH.is_file() or not CONFIG_PATH.is_file():
                    raise HTTPException(status_code=503, detail="voice_model_unavailable")
                voice = PiperVoice.load(str(MODEL_PATH), config_path=str(CONFIG_PATH))
                if len(voice.session.get_outputs()) < 2:
                    raise HTTPException(status_code=503, detail="voice_alignment_unavailable")
                _voice = voice
    return _voice


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok", "service": "document-audio", "version": APP_VERSION}


@app.get("/v1/ready", dependencies=[Depends(require_token)])
def ready() -> dict[str, object]:
    voice = get_voice()
    return {
        "status": "ready",
        "model": VOICE_ID,
        "model_files_present": MODEL_PATH.is_file() and CONFIG_PATH.is_file(),
        "word_alignment": len(voice.session.get_outputs()) >= 2,
        "alignment_method": "piper_phoneme_sample_counts",
    }


@app.post("/v1/synthesize", dependencies=[Depends(require_token)])
def synthesize(payload: SynthesisRequest) -> Response:
    if payload.voice_id != VOICE_ID:
        raise HTTPException(status_code=422, detail="unsupported_voice_id")
    text = re.sub(r"[\u0000-\u0008\u000b\u000c\u000e-\u001f]", " ", payload.text).strip()
    if not text:
        raise HTTPException(status_code=422, detail="empty_text")
    if not MAX_CONCURRENCY.acquire(blocking=False):
        raise HTTPException(status_code=429, detail="synthesis_busy")
    try:
        voice = get_voice()
        output = io.BytesIO()
        with wave.open(output, "wb") as wav_file:
            wav_file.setnchannels(1)
            wav_file.setsampwidth(2)
            wav_file.setframerate(voice.config.sample_rate)
            alignments = voice.synthesize_wav(
                text,
                wav_file,
                set_wav_format=False,
                include_alignments=True,
            )
            total_samples = wav_file.getnframes()

        if total_samples <= 0:
            raise HTTPException(status_code=422, detail="no_speech_content")

        audio = output.getvalue()
        if len(audio) < 44 or audio[:4] != b"RIFF" or audio[8:12] != b"WAVE":
            raise HTTPException(status_code=502, detail="invalid_audio_output")

        word_timings = build_word_timings(
            text,
            alignments or [],
            voice.config.sample_rate,
            total_samples,
        )
        if word_timings is not None:
            audio = append_alignment_metadata(audio, word_timings)

        if len(audio) > 20 * 1024 * 1024:
            raise HTTPException(status_code=413, detail="audio_output_too_large")

        return Response(
            content=audio,
            media_type="audio/wav",
            headers={
                "Cache-Control": "no-store",
                "X-Farast-Voice-Id": VOICE_ID,
                "X-Farast-Alignment": "phoneme" if word_timings is not None else "unavailable",
                "X-Content-Type-Options": "nosniff",
            },
        )
    finally:
        MAX_CONCURRENCY.release()
