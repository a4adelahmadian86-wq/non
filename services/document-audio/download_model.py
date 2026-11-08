from __future__ import annotations

import hashlib
import os
import urllib.request
from pathlib import Path

MODEL_DIR = Path(os.getenv("PIPER_MODEL_DIR", "/opt/render/project/src/models"))
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


if __name__ == "__main__":
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    for path, checksum in FILES.items():
        download(path, checksum)
    print("document_audio_model_ready")
