from __future__ import annotations

import importlib
import sys
from pathlib import Path
from types import SimpleNamespace

import pytest
from fastapi.testclient import TestClient


def load_voice_module(monkeypatch: pytest.MonkeyPatch, token: str = "ci-test-token"):
    monkeypatch.setenv("FARAST_VOICE_TOKEN", token)
    monkeypatch.setenv("FARAST_TTS_AUTO_DOWNLOAD", "false")
    monkeypatch.setenv("FARAST_TTS_MODEL_PATH", "/tmp/farast-test-missing/model.pth")
    monkeypatch.setenv("FARAST_TTS_CONFIG_PATH", "/tmp/farast-test-missing/config.json")
    module_name = "app.main"
    if module_name in sys.modules:
        return importlib.reload(sys.modules[module_name])
    return importlib.import_module(module_name)


@pytest.fixture
def voice_api(monkeypatch: pytest.MonkeyPatch):
    module = load_voice_module(monkeypatch)
    with TestClient(module.app) as client:
        yield module, client


def auth_headers(module) -> dict[str, str]:
    return {"Authorization": f"Bearer {module.API_TOKEN}"}


def test_health_is_public_and_does_not_expose_credentials_or_model_paths(voice_api):
    _module, client = voice_api

    response = client.get("/health")

    assert response.status_code == 200
    payload = response.json()
    assert payload["service"] == "farast-voice"
    assert payload["status"] == "ok"
    assert payload["persistent_models_required"] is True
    assert "ci-test-token" not in response.text
    assert "/tmp/" not in response.text
    assert "tts_error" not in payload


def test_protected_endpoints_reject_missing_and_invalid_bearer_tokens(voice_api):
    _module, client = voice_api

    assert client.get("/v1/status").status_code == 401
    assert client.get("/v1/status", headers={"Authorization": "Bearer wrong"}).status_code == 401
    assert client.post(
        "/v1/transcribe",
        files={"audio": ("sample.wav", b"audio", "audio/wav")},
        headers={"Authorization": "Bearer wrong"},
    ).status_code == 401
    assert client.post(
        "/v1/synthesize",
        json={"text": "سلام"},
        headers={"Authorization": "Bearer wrong"},
    ).status_code == 401


def test_missing_server_token_fails_closed(monkeypatch: pytest.MonkeyPatch):
    module = load_voice_module(monkeypatch, token="")

    with TestClient(module.app) as client:
        response = client.get("/v1/status", headers={"Authorization": "Bearer ci-test-token"})

    assert response.status_code == 503
    assert "FARAST_VOICE_TOKEN" in response.json()["detail"]


def test_status_exposes_readiness_only_to_authenticated_clients(voice_api):
    module, client = voice_api
    module.tts_error = "FileNotFoundError: configured model files are missing"

    response = client.get("/v1/status", headers=auth_headers(module))

    assert response.status_code == 200
    assert response.json()["asr_ready"] is False
    assert response.json()["tts_ready"] is False
    assert response.json()["tts_error"]
    assert "tts_error" not in client.get("/health").json()


def test_transcribe_rejects_unsupported_locale_before_calling_model(voice_api):
    module, client = voice_api
    module.asr_model = object()

    response = client.post(
        "/v1/transcribe",
        headers=auth_headers(module),
        files={"audio": ("sample.wav", b"audio", "audio/wav")},
        data={"locale": "xx-XX"},
    )

    assert response.status_code == 400
    assert response.json()["detail"] == "Unsupported locale"


def test_transcribe_enforces_configured_upload_size_limit(voice_api):
    module, client = voice_api
    module.asr_model = object()
    oversized_audio = b"x" * (module.MAX_AUDIO_BYTES + 1)

    response = client.post(
        "/v1/transcribe",
        headers=auth_headers(module),
        files={"audio": ("large.wav", oversized_audio, "audio/wav")},
        data={"locale": "fa-IR"},
    )

    assert response.status_code == 413
    assert "size limit" in response.json()["detail"]


def test_transcribe_returns_text_from_the_local_model(voice_api):
    module, client = voice_api

    class FakeASR:
        calls = None

        def transcribe(self, file_path, **kwargs):
            assert Path(file_path).is_file()
            self.calls = kwargs
            return iter([
                SimpleNamespace(text=" سلام "),
                SimpleNamespace(text=" دنیا "),
            ]), SimpleNamespace(language="fa")

    fake_model = FakeASR()
    module.asr_model = fake_model

    response = client.post(
        "/v1/transcribe",
        headers=auth_headers(module),
        files={"audio": ("sample.webm", b"test-audio", "audio/webm")},
        data={"locale": "fa-IR"},
    )

    assert response.status_code == 200
    assert response.json() == {"text": "سلام دنیا"}
    assert fake_model.calls["language"] == "fa"
    assert fake_model.calls["vad_filter"] is True


def test_transcribe_rejects_empty_audio(voice_api):
    module, client = voice_api
    module.asr_model = object()

    response = client.post(
        "/v1/transcribe",
        headers=auth_headers(module),
        files={"audio": ("empty.wav", b"", "audio/wav")},
        data={"locale": "fa-IR"},
    )

    assert response.status_code == 400
    assert response.json()["detail"] == "Audio file is empty"


def test_synthesize_fails_closed_when_fixed_model_is_unavailable(voice_api):
    module, client = voice_api
    module.tts_model = None

    response = client.post(
        "/v1/synthesize",
        headers=auth_headers(module),
        json={"text": "سلام", "language": "fa-IR", "voice_id": module.VOICE_ID},
    )

    assert response.status_code == 503


def test_synthesize_rejects_unconfigured_voice(voice_api):
    module, client = voice_api
    module.tts_model = SimpleNamespace(synthesizer=SimpleNamespace(output_sample_rate=22050), tts=lambda **_kwargs: [0.0, 0.1])

    response = client.post(
        "/v1/synthesize",
        headers=auth_headers(module),
        json={"text": "سلام", "language": "en-US", "voice_id": "unconfigured-voice"},
    )

    assert response.status_code == 400
    assert response.json()["detail"] == "Only the configured fixed Persian voice is supported"


def test_synthesize_returns_uncached_wav_for_configured_voice(voice_api):
    module, client = voice_api

    class FakeTTS:
        synthesizer = SimpleNamespace(output_sample_rate=22050)

        def tts(self, *, text):
            assert text == "سلام"
            return [0.0, 0.1, -0.1, 0.0]

    module.tts_model = FakeTTS()

    response = client.post(
        "/v1/synthesize",
        headers=auth_headers(module),
        json={"text": "سلام", "language": "fa-IR", "voice_id": module.VOICE_ID},
    )

    assert response.status_code == 200
    assert response.headers["content-type"].startswith("audio/wav")
    assert response.headers["cache-control"] == "no-store"
    assert response.headers["x-farast-voice-id"] == module.VOICE_ID
    assert response.content[:4] == b"RIFF"
