import hmac
import io
import os
import threading
import wave
from pathlib import Path
from typing import Optional

from fastapi import FastAPI, Header, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel, Field
from piper import PiperVoice

MODEL_PATH = Path(os.getenv("PIPER_MODEL_PATH", "/models/fa_IR-amir-medium.onnx"))
MAX_TEXT_LENGTH = max(100, int(os.getenv("FARAST_TTS_MAX_TEXT_LENGTH", "6000")))
VOICE_ID = "farast-fa-male-v1"
ACCEPTED_VOICE_IDS = {VOICE_ID, "fa_IR-amir-medium"}

voice: Optional[PiperVoice] = None
synthesis_lock = threading.Lock()
app = FastAPI(title="Farast private Persian TTS", docs_url=None, redoc_url=None)


class SynthesisRequest(BaseModel):
    text: str = Field(min_length=1, max_length=6000)
    language: str = "fa-IR"
    voice_id: str = VOICE_ID


@app.on_event("startup")
def load_voice() -> None:
    global voice
    if not MODEL_PATH.is_file() or not Path(str(MODEL_PATH) + ".json").is_file():
        raise RuntimeError("piper_voice_model_not_ready")
    voice = PiperVoice.load(str(MODEL_PATH))


@app.get("/health")
def health():
    if voice is None:
        raise HTTPException(status_code=503, detail="voice_not_ready")
    return {"ok": True, "engine": "piper", "language": "fa-IR", "voice_id": VOICE_ID}


@app.post("/v1/synthesize")
def synthesize(
    request: SynthesisRequest,
    authorization: Optional[str] = Header(default=None),
):
    token = os.getenv("FARAST_VOICE_TTS_TOKEN") or os.getenv("FARAST_VOICE_TOKEN") or ""
    if token:
        supplied = (authorization or "").removeprefix("Bearer ").strip()
        if not hmac.compare_digest(supplied, token):
            raise HTTPException(status_code=401, detail="invalid_voice_token")

    if request.language != "fa-IR":
        raise HTTPException(status_code=422, detail="unsupported_language")
    if request.voice_id not in ACCEPTED_VOICE_IDS:
        raise HTTPException(status_code=422, detail="unsupported_voice")
    text = request.text.strip()
    if not text or len(text) > MAX_TEXT_LENGTH:
        raise HTTPException(status_code=422, detail="invalid_text_length")
    if voice is None:
        raise HTTPException(status_code=503, detail="voice_not_ready")

    output = io.BytesIO()
    try:
        # Piper's phonemizer is not thread-safe; serialize synthesis requests.
        with synthesis_lock:
            with wave.open(output, "wb") as wav_file:
                voice.synthesize_wav(text, wav_file)
    except Exception as error:
        raise HTTPException(status_code=502, detail="synthesis_failed") from error

    audio = output.getvalue()
    if not audio:
        raise HTTPException(status_code=502, detail="empty_audio")
    return Response(
        content=audio,
        media_type="audio/wav",
        headers={
            "Cache-Control": "no-store",
            "X-Content-Type-Options": "nosniff",
            "X-Farast-Voice-Id": VOICE_ID,
            "X-Farast-TTS-Provider": "piper-local",
        },
    )
