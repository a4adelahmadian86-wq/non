from __future__ import annotations

import io
import wave
from pathlib import Path

from piper import PiperVoice

from alignment import append_alignment_metadata, build_word_timings

MODEL_DIR = Path(__file__).resolve().parent / "models"
MODEL_PATH = MODEL_DIR / "fa_IR-amir-medium.onnx"
CONFIG_PATH = MODEL_DIR / "fa_IR-amir-medium.onnx.json"


def main() -> None:
    if not MODEL_PATH.is_file() or not CONFIG_PATH.is_file():
        raise RuntimeError("document_audio_model_missing")

    voice = PiperVoice.load(str(MODEL_PATH), config_path=str(CONFIG_PATH))
    if len(voice.session.get_outputs()) < 2:
        raise RuntimeError("piper_alignment_output_missing")

    text = "سلام، این یک آزمون هم‌ترازی واژه‌ها است."
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

    timings = build_word_timings(text, alignments or [], voice.config.sample_rate, total_samples)
    if timings is None or len(timings) != len(text.split()):
        raise RuntimeError("piper_persian_word_alignment_failed")

    aligned_wav = append_alignment_metadata(output.getvalue(), timings)
    if b"FARA" not in aligned_wav or aligned_wav[:4] != b"RIFF":
        raise RuntimeError("piper_alignment_metadata_invalid")

    print(f"document_audio_alignment_ready words={len(timings)} sample_rate={voice.config.sample_rate}")


if __name__ == "__main__":
    main()
