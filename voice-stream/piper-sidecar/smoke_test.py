import io
import os
import wave
from pathlib import Path

from piper import PiperVoice

model_dir = Path(os.getenv("PIPER_MODEL_DIR", "/models"))
model_path = model_dir / "fa_IR-mana-medium.onnx"
if not model_path.is_file():
    raise SystemExit(f"missing_model:{model_path}")

voice = PiperVoice.load(str(model_path), use_cuda=False)
output = io.BytesIO()
with wave.open(output, "wb") as wav_file:
    voice.synthesize_wav("سلام، به دستیار صوتی فراست خوش آمدید.", wav_file)

audio = output.getvalue()
if len(audio) < 1024:
    raise SystemExit("piper_tts_smoke_audio_too_short")
with wave.open(io.BytesIO(audio), "rb") as wav_file:
    if wav_file.getnframes() <= 0 or wav_file.getframerate() != 22050:
        raise SystemExit("piper_tts_smoke_invalid_wav")
print(f"Piper Persian TTS smoke passed: {len(audio)} bytes at 22050 Hz")
