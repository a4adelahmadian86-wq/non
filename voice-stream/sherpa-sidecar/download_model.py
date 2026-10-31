import hashlib
import os
from pathlib import Path

from huggingface_hub import hf_hub_download

MODEL_DIR = Path(os.getenv("SHERPA_MODEL_DIR", "/models"))
REPO_ID = os.getenv(
    "SHERPA_MODEL_REPO",
    "mentisialog/sherpa-onnx-nemotron-asr-streaming-farsi-1120ms-int8",
)
REVISION = os.getenv("SHERPA_MODEL_REVISION", "main")

EXPECTED_SHA256 = {
    "encoder.int8.onnx": "13c1d630f1c82a3534c76ff36a38b4823bdc2413254d5cf7489927b000d57aa2",
    "decoder.int8.onnx": "548a4763366e3ba4ba5272f3d68465425f5c62b207922ee2d617be920a921b06",
    "joiner.int8.onnx": "e5e365bd22a7f678899674ecad00a9fae23323973de73b508e54ea79eb56b3cc",
    "tokens.txt": "7ace7d33f3784d50f9a46156cce7c411f39faab64b43df17bd5796abc56a3057",
}


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def main() -> None:
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    for filename, expected in EXPECTED_SHA256.items():
        destination = MODEL_DIR / filename
        if destination.is_file() and sha256(destination) == expected:
            print(f"model already verified: {filename}", flush=True)
            continue

        print(f"downloading server-side ASR asset: {filename}", flush=True)
        downloaded = Path(
            hf_hub_download(
                repo_id=REPO_ID,
                filename=filename,
                revision=REVISION,
                local_dir=str(MODEL_DIR),
                force_download=True,
            )
        )
        actual = sha256(downloaded)
        if actual != expected:
            raise RuntimeError(
                f"model_checksum_mismatch:{filename}:expected={expected}:actual={actual}"
            )
        if downloaded.resolve() != destination.resolve():
            destination.write_bytes(downloaded.read_bytes())
        print(f"verified server-side ASR asset: {filename}", flush=True)

    print(f"all Persian ASR model files are verified in {MODEL_DIR}", flush=True)


if __name__ == "__main__":
    main()
