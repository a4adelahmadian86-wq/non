"""FARAST AI Worker gateway client.

The Product Supervisor never calls model-provider APIs directly. It sends bounded,
purpose-specific requests to the authenticated FARAST Worker gateway. Provider
credentials remain inside the Worker environment; this module never logs prompts.
"""
from __future__ import annotations

import os
from typing import Any

import httpx

DEFAULT_MODELS = {
    "text": "@cf/openai/gpt-oss-20b",
    "vision": "@cf/moondream/moondream3.1-9B-A2B",
    "audio": "@cf/openai/whisper-large-v3-turbo",
}


def ai_gateway_url() -> str:
    return os.getenv("FARAST_AI_GATEWAY_URL", "").strip().rstrip("/")


def ai_gateway_token() -> str:
    return os.getenv("FARAST_AI_GATEWAY_TOKEN", "").strip()


def cloudflare_model(task: str = "text") -> str:
    if task not in DEFAULT_MODELS:
        raise ValueError("unsupported_cloudflare_model_task")
    return DEFAULT_MODELS[task]


def cloudflare_provider_ready(task: str | None = None) -> bool:
    if not ai_gateway_url() or not ai_gateway_token():
        return False
    if task is not None and task not in DEFAULT_MODELS:
        return False
    return True


def cloudflare_auth_headers(*, include_gateway: bool = False) -> dict[str, str]:
    token = ai_gateway_token()
    if not token:
        raise RuntimeError("farast_ai_gateway_token_not_configured")
    return {
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
        "Content-Type": "application/json",
    }


def cloudflare_chat_completions_url() -> str:
    base = ai_gateway_url()
    if not base:
        raise RuntimeError("farast_ai_gateway_url_not_configured")
    return f"{base}/v1/chat/completions"


def cloudflare_run_url(task: str) -> str:
    if task not in {"vision", "audio"}:
        raise ValueError("unsupported_cloudflare_run_task")
    base = ai_gateway_url()
    if not base:
        raise RuntimeError("farast_ai_gateway_url_not_configured")
    return f"{base}/v1/ai/run/{task}"


async def cloudflare_chat_completion(
    messages: list[dict[str, Any]],
    *,
    response_format: dict[str, Any] | None = None,
    max_tokens: int = 1800,
    temperature: float = 0.1,
    timeout_seconds: float = 90,
) -> str:
    if not cloudflare_provider_ready("text"):
        raise RuntimeError("farast_ai_gateway_not_configured")

    payload: dict[str, Any] = {
        "model": cloudflare_model("text"),
        "messages": messages,
        "max_tokens": max(1, min(int(max_tokens), 8192)),
        "temperature": max(0.0, min(float(temperature), 1.0)),
        "stream": False,
    }
    if response_format:
        payload["response_format"] = response_format

    timeout = httpx.Timeout(timeout_seconds, connect=8.0)
    async with httpx.AsyncClient(timeout=timeout) as client:
        response = await client.post(
            cloudflare_chat_completions_url(),
            headers=cloudflare_auth_headers(),
            json=payload,
        )
        response.raise_for_status()
        body = response.json()

    choices = body.get("choices") or []
    if not choices:
        raise RuntimeError("farast_ai_gateway_empty_completion")
    message = choices[0].get("message") or {}
    content = message.get("content")
    if isinstance(content, list):
        content = "".join(
            str(part.get("text", "")) for part in content if isinstance(part, dict)
        )
    if not isinstance(content, str) or not content.strip():
        raise RuntimeError("farast_ai_gateway_empty_completion")
    return content.strip()


async def cloudflare_run_model(
    task: str,
    payload: dict[str, Any],
    *,
    timeout_seconds: float = 90,
) -> dict[str, Any]:
    if not cloudflare_provider_ready(task):
        raise RuntimeError("farast_ai_gateway_not_configured")

    timeout = httpx.Timeout(timeout_seconds, connect=8.0)
    async with httpx.AsyncClient(timeout=timeout) as client:
        response = await client.post(
            cloudflare_run_url(task),
            headers=cloudflare_auth_headers(),
            json=payload,
        )
        response.raise_for_status()
        body = response.json()

    if body.get("success") is False:
        raise RuntimeError("farast_ai_gateway_request_failed")
    result = body.get("result")
    if not isinstance(result, dict):
        raise RuntimeError("farast_ai_gateway_invalid_response")
    return result
