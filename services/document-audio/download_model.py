from __future__ import annotations

import hashlib
import os
import urllib.request
from pathlib import Path

MODEL_DIR = Path(os.getenv("PIPER_MODEL_DIR", str(Path(__file__).resolve().parent / "models")))
REVISION = "7a6c333ec560f0e688371adc2fbb7bbe105028c6"
REPOSITORY = "rhasspy/piper-voices"
MODEL_NAME = "fa_IR-amir-medium"
FILES = {
    f"fa/fa_IR/amir/medium/{MODEL_NAME}.onnx": "fb815380d969ea372b0b21b0de14421f58fe481047e153e69685d079b6e1a9d1",
    f"fa/fa_IR/amir/medium/{MODEL_NAME}.onnx.json": None,
}


def download(relative_path: str, expected_sha256: str | None) -> None:
    target = MODEL_DIR / Path(relative_path).name
    if target.exists() and (expected_sha256 is None or hashlib.sha256(target.read_bytes()).hexdigest() == expected_sha256):
        return
    url = f"https://huggingface.co/{REPOSITORY}/resolve/{REVISION}/{relative_path}"
    request = urllib.request.Request(url, headers={"User-Agent": "Farast-Document-Audio/1.0"})
    with urllib.request.urlopen(request, timeout=90) as response:
        payload = response.read(250 * 1024 * 1024 + 1)
    if not payload or len(payload) > 250 * 1024 * 1024:
        raise RuntimeError("piper_model_download_size_invalid")
    if expected_sha256 and hashlib.sha256(payload).hexdigest() != expected_sha256:
        raise RuntimeError("piper_model_checksum_mismatch")
    target.parent.mkdir(parents=True, exist_ok=True)
    temporary = target.with_suffix(target.suffix + ".part")
    temporary.write_bytes(payload)
    temporary.replace(target)


def patch_model_for_alignment() -> None:
    """Expose Piper's per-phoneme sample-count tensor without changing the pinned source model."""
    import onnx
    from piper.patch_voice_with_alignment import add_alignment_output

    model_path = MODEL_DIR / f"{MODEL_NAME}.onnx"
    model = onnx.load(str(model_path))
    try:
        add_alignment_output(model)
    except ValueError as error:
        if "already marked as output" not in str(error):
            raise RuntimeError("piper_model_alignment_patch_failed") from None
    temporary = model_path.with_suffix(".aligned.onnx")
    onnx.save(model, str(temporary))
    temporary.replace(model_path)


if __name__ == "__main__":
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    for path, checksum in FILES.items():
        download(path, checksum)
    patch_model_for_alignment()
    print("document_audio_model_ready_with_phoneme_alignment")
