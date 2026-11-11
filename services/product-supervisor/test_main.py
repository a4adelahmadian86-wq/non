import os

from fastapi.testclient import TestClient

import main


client = TestClient(main.app)


def valid_payload():
    return {
        "product_id": "42",
        "product_type": "digital_file",
        "title": "راهنمای حرفه‌ای فروش",
        "price_rials": 1250000,
        "checks": ["content", "pricing", "seo"],
    }


def test_health_does_not_expose_secrets(monkeypatch):
    monkeypatch.setenv("GEMINI_API_KEY", "AIza-test-only-not-a-real-key")
    response = client.get("/health")
    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ok"
    assert body["service"] == "product-supervisor"
    assert body["providers"]["gemini"]["configured"] is True
    assert body["providers"]["gemini"]["role"] == "product_snapshot_and_database_report_review"
    assert body["providers"]["groq"]["configured"] is bool(os.getenv("GROQ_API_KEY", "").strip())
    assert "privacy_gate_confirmed" in body["providers"]["groq"]
    assert "AIza-test-only-not-a-real-key" not in response.text


def test_review_requires_dedicated_bearer_token(monkeypatch):
    monkeypatch.setenv("SUPERVISOR_API_TOKEN", "dedicated-secret")
    response = client.post("/v1/reviews/products", json=valid_payload())
    assert response.status_code == 401


def test_file_review_contract_allows_intake_before_product_title_exists():
    schema = client.get("/openapi.json").json()
    request_schema = schema["paths"]["/v1/reviews/files"]["post"]["requestBody"]["content"]["multipart/form-data"]["schema"]
    schema_name = request_schema["$ref"].rsplit("/", 1)[-1]
    form_schema = schema["components"]["schemas"][schema_name]

    assert "product_title" not in form_schema.get("required", [])
    assert "product_id" in form_schema.get("required", [])
    assert "product_type" in form_schema.get("required", [])


def test_review_requires_provider_configuration(monkeypatch):
    monkeypatch.setenv("SUPERVISOR_API_TOKEN", "dedicated-secret")
    monkeypatch.delenv("GEMINI_API_KEY", raising=False)
    response = client.post(
        "/v1/reviews/products",
        json=valid_payload(),
        headers={"Authorization": "Bearer dedicated-secret"},
    )
    assert response.status_code == 503


def test_review_rejects_unexpected_fields(monkeypatch):
    monkeypatch.setenv("SUPERVISOR_API_TOKEN", "dedicated-secret")
    payload = valid_payload()
    payload["customer_email"] = "private@example.com"
    response = client.post(
        "/v1/reviews/products",
        json=payload,
        headers={"Authorization": "Bearer dedicated-secret"},
    )
    assert response.status_code == 422


def test_review_payload_limits_long_product_text(monkeypatch):
    monkeypatch.setenv("SUPERVISOR_API_TOKEN", "dedicated-secret")
    payload = valid_payload()
    payload["description"] = "x" * 12001
    response = client.post(
        "/v1/reviews/products",
        json=payload,
        headers={"Authorization": "Bearer dedicated-secret"},
    )
    assert response.status_code == 422
