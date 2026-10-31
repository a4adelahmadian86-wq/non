import hashlib
import os
from pathlib import Path

from huggingface_hub import hf_hub_download

MODEL_DIR = Path(os.getenv("PIPER_MODEL_DIR", "/models"))
REPO_ID = "rhasspy/piper-voices"
REVISION = "b22aa2aa275d5e8d528994782814cd8bf9d0337f"
MODEL_FILE = "fa/fa_IR/amir/medium/fa_IR-amir-medium.onnx"
CONFIG_FILE = "fa/fa_IR/amir/medium/fa_IR-amir-medium.onnx.json"
MODEL_SHA256 = "fb815380d969ea372b0b21d0de14421f58fe481047e153e69685d079b6e1a9d1"


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def fetch(filename: str, destination: Path) -> None:
    downloaded = Path(
        hf_hub_download(
            repo_id=REPO_ID,
            filename=filename,
            revision=REVISION,
            local_dir=str(MODEL_DIR),
        )
    )
    if downloaded.resolve() != destination.resolve():
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(downloaded.read_bytes())


def main() -> None:
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    model_path = MODEL_DIR / "fa_IR-amir-medium.onnx"
    config_path = MODEL_DIR / "fa_IR-amir-medium.onnx.json"

    if not model_path.is_file() or sha256(model_path) != MODEL_SHA256:
        downloaded = Path(
            hf_hub_download(
                repo_id=REPO_ID,
                filename=MODEL_FILE,
                revision=REVISION,
                local_dir=str(MODEL_DIR),
                force_download=True,
            )
        )
        actual = sha256(downloaded)
        if actual != MODEL_SHA256:
            raise RuntimeError(f"piper_model_checksum_mismatch:{actual}")
        if downloaded.resolve() != model_path.resolve():
            model_path.write_bytes(downloaded.read_bytes())

    if not config_path.is_file():
        fetch(CONFIG_FILE, config_path)

    if not config_path.is_file() or not model_path.is_file():
        raise RuntimeError("piper_model_assets_missing")

    print(f"Verified Persian Piper model is ready in {MODEL_DIR}", flush=True)


if __name__ == "__main__":
    main()
