import os

# Importing the module must not require model weights. Startup loading is exercised
# only by the actual deployment; these tests validate the fail-closed API contract.
os.environ.setdefault("FARAST_VOICE_TOKEN", "test-token-for-contract-tests")

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
