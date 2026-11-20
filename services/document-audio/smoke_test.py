from __future__ import annotations

import io
import json
import socket
import threading
import time
import urllib.error
import urllib.request
import wave
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

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
    verify_concurrency_contract()


def verify_concurrency_contract() -> None:
    import main
    import uvicorn

    started = threading.Event()
    release = threading.Event()
    results: list[object] = []

    class FakeVoice:
        config = SimpleNamespace(sample_rate=22050)

        def synthesize_wav(self, text, wav_file, **kwargs):
            started.set()
            if not release.wait(timeout=5):
                raise RuntimeError("test_synthesis_release_timeout")
            wav_file.writeframes(b"\x00\x00" * 256)
            return []

    listener = socket.socket()
    listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    listener.bind(("127.0.0.1", 0))
    listener.listen(128)
    port = listener.getsockname()[1]
    base_url = f"http://127.0.0.1:{port}"
    server = uvicorn.Server(uvicorn.Config(
        main.app,
        host="127.0.0.1",
        port=port,
        log_level="critical",
        access_log=False,
    ))

    def post_synthesis() -> None:
        request = urllib.request.Request(
            base_url + "/v1/synthesize",
            data=json.dumps({"text": "سلام، آزمون هم‌زمانی"}).encode("utf-8"),
            headers={
                "Authorization": "Bearer test-token",
                "Content-Type": "application/json",
            },
            method="POST",
        )
        try:
            with urllib.request.urlopen(request, timeout=8) as response:
                results.append(response.status)
        except BaseException as error:
            results.append(error)

    with (
        patch.object(main, "API_TOKEN", "test-token"),
        patch.object(main, "MAX_CONCURRENCY", threading.BoundedSemaphore(1)),
        patch.object(main, "get_voice", return_value=FakeVoice()),
        patch.object(main, "build_word_timings", return_value=None),
    ):
        server_thread = threading.Thread(target=server.run, kwargs={"sockets": [listener]}, daemon=True)
        server_thread.start()
        try:
            for _ in range(50):
                try:
                    with urllib.request.urlopen(base_url + "/health", timeout=0.3) as response:
                        if response.status == 200:
                            break
                except Exception:
                    time.sleep(0.1)
            else:
                raise RuntimeError("document_audio_http_server_did_not_start")

            worker = threading.Thread(target=post_synthesis, daemon=True)
            worker.start()
            if not started.wait(timeout=3):
                raise RuntimeError("document_audio_first_synthesis_did_not_start")

            busy_request = urllib.request.Request(
                base_url + "/v1/synthesize",
                data=json.dumps({"text": "درخواست هم‌زمان"}).encode("utf-8"),
                headers={
                    "Authorization": "Bearer test-token",
                    "Content-Type": "application/json",
                },
                method="POST",
            )
            try:
                urllib.request.urlopen(busy_request, timeout=3)
            except urllib.error.HTTPError as error:
                if error.code != 429:
                    raise RuntimeError(f"document_audio_busy_status_invalid: {error.code}") from error
            else:
                raise RuntimeError("document_audio_concurrency_limit_not_enforced")

            with urllib.request.urlopen(base_url + "/health", timeout=3) as response:
                if response.status != 200:
                    raise RuntimeError("document_audio_health_unavailable_while_busy")

            release.set()
            worker.join(timeout=5)
            if worker.is_alive():
                raise RuntimeError("document_audio_first_synthesis_did_not_finish")
            if results != [200]:
                raise RuntimeError(f"document_audio_synthesis_failed: {results!r}")
        finally:
            release.set()
            server.should_exit = True
            server_thread.join(timeout=5)
            listener.close()
            if server_thread.is_alive():
                raise RuntimeError("document_audio_test_server_did_not_stop")

    print("document_audio_concurrency_ready active_limit=1 busy_status=429 health=200")

if __name__ == "__main__":
    main()
