import base64
import hashlib
import hmac
import json
import os
import time

# Importing the module must not require model weights. Startup loading is exercised
# only by the actual deployment; these tests validate the fail-closed API contract.
os.environ.setdefault("FARAST_VOICE_TOKEN", "test-token-for-contract-tests")
os.environ.setdefault("APP_KEY", "test-app-key")

from fastapi.testclient import TestClient
from app import main


def test_health_does_not_expose_secret():
    client = TestClient(main.app)
    response = client.get("/health")
    assert response.status_code == 200
    assert "FARAST_VOICE_TOKEN" not in response.text
    assert "test-token-for-contract-tests" not in response.text


def test_status_requires_auth():
    client = TestClient(main.app)
    response = client.get("/v1/status")
    assert response.status_code == 401


def test_status_accepts_bearer_token():
    client = TestClient(main.app)
    response = client.get("/v1/status", headers={"Authorization": "Bearer test-token-for-contract-tests"})
    assert response.status_code == 200
    assert response.json()["voice_id"] == main.VOICE_ID


def test_synthesis_rejects_wrong_voice_without_model():
    client = TestClient(main.app)
    response = client.post(
        "/v1/synthesize",
        headers={"Authorization": "Bearer test-token-for-contract-tests"},
        json={"text": "سلام", "language": "fa-IR", "voice_id": "different-voice"},
    )
    # Voice identity validation occurs before model readiness validation.
    assert response.status_code == 400



def _signed_stream_token(payload):
    encoded = base64.urlsafe_b64encode(json.dumps(payload, separators=(",", ":")).encode()).decode().rstrip("=")
    signature = hmac.new(main.STREAM_APP_KEY.encode(), encoded.encode(), hashlib.sha256).hexdigest()
    return encoded + "." + signature


def test_stream_token_verification_accepts_only_valid_short_lived_laravel_tokens():
    payload = {
        "uid": 7,
        "locale": "fa-IR",
        "iat": int(time.time()),
        "exp": int(time.time()) + 120,
        "nonce": "test-stream-nonce",
    }
    token = _signed_stream_token(payload)
    assert main.verify_stream_token(token) == payload
    assert main.verify_stream_token(token + "tampered") is None


def test_stream_token_verification_rejects_expired_or_unsupported_tokens():
    expired = {
        "uid": 7,
        "locale": "fa-IR",
        "iat": int(time.time()) - 300,
        "exp": int(time.time()) - 1,
        "nonce": "expired",
    }
    unsupported = {
        "uid": 7,
        "locale": "xx-XX",
        "iat": int(time.time()),
        "exp": int(time.time()) + 120,
        "nonce": "unsupported",
    }
    assert main.verify_stream_token(_signed_stream_token(expired)) is None
    assert main.verify_stream_token(_signed_stream_token(unsupported)) is None
