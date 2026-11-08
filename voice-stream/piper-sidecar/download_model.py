import hashlib
import os
import tempfile
import urllib.request
from pathlib import Path

MODEL_DIR = Path(os.getenv("PIPER_MODEL_DIR", str(Path(__file__).resolve().parent / "models")) )
REVISION = "7a6c333ec560f0e688371adc2fbb7bbe105028c6"
REPOSITORY = "rhasspy/piper-voices"
FILES = {
    "fa_IR-amir-medium.onnx": "fb815380d969ea372b0b21b0de14421f58fe481047e153e69685d079b6e1a9d1",
    "fa_IR-amir-medium.onnx.json": None,
}


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def download(name: str, expected_sha256: str | None) -> None:
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    destination = MODEL_DIR / name
    if destination.exists():
        digest = sha256(destination)
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

    digest = sha256(temporary)
    if expected_sha256 and digest != expected_sha256:
        temporary.unlink(missing_ok=True)
        raise RuntimeError(f"sha256_mismatch:{name}")
    temporary.replace(destination)


if __name__ == "__main__":
    for filename, checksum in FILES.items():
        download(filename, checksum)
    print(f"Persian Piper Mana voice ready in {MODEL_DIR}", flush=True)
