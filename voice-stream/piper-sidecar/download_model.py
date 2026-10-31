import hashlib
import os
import tempfile
import urllib.request
from pathlib import Path

MODEL_DIR = Path(os.getenv("PIPER_MODEL_DIR", "/models"))
REVISION = "cafdcbdb455e4a9ab2ef18dccb3e21dce6a25ee9"
REPOSITORY = "MahtaFetrat/Mana-Persian-Piper"
FILES = {
    "fa_IR-mana-medium.onnx": "e390c0e74ba71fd97c49ba662ee0c6e1724b462ba2d4561698af4f564840f126",
    "fa_IR-mana-medium.onnx.json": None,
}


def download(name: str, expected_sha256: str | None) -> None:
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    destination = MODEL_DIR / name
    if destination.exists():
        digest = hashlib.sha256(destination.read_bytes()).hexdigest()
        if expected_sha256 is None or digest == expected_sha256:
            return
        destination.unlink()

    url = f"https://huggingface.co/{REPOSITORY}/resolve/{REVISION}/{name}"
    request = urllib.request.Request(url, headers={"User-Agent": "FARAST-self-hosted-voice/1.0"})
    with tempfile.NamedTemporaryFile(dir=MODEL_DIR, delete=False) as temp:
        temporary = Path(temp.name)
        with urllib.request.urlopen(request, timeout=90) as response:
            while chunk := response.read(1024 * 1024):
                temp.write(chunk)

    digest = hashlib.sha256(temporary.read_bytes()).hexdigest()
    if expected_sha256 and digest != expected_sha256:
        temporary.unlink(missing_ok=True)
        raise RuntimeError(f"sha256_mismatch:{name}")
    temporary.replace(destination)


if __name__ == "__main__":
    for filename, checksum in FILES.items():
        download(filename, checksum)
    print(f"Persian Piper voice ready in {MODEL_DIR}")
