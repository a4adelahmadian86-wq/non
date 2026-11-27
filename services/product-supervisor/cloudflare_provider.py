"""Cloudflare Workers AI REST helpers.

Credentials are read only from the service environment. No prompt or response
content is logged by this module.
"""
from __future__ import annotations

import os
import re
from typing import Any

import httpx

DEFAULT_MODELS = {
    "text": "@cf/openai/gpt-oss-20b",
    "vision": "@cf/moondream/moondream3.1-9B-A2B",
    "audio": "@cf/openai/whisper-large-v3-turbo",
}

MODEL_ENV = {
    "text": "CLOUDFLARE_TEXT_MODEL",
    "vision": "CLOUDFLARE_VISION_MODEL",
    "audio": "CLOUDFLARE_AUDIO_MODEL",
}


def cloudflare_account_id() -> str:
    return os.getenv("CLOUDFLARE_ACCOUNT_ID", "").strip()


def cloudflare_api_token() -> str:
    return os.getenv("CLOUDFLARE_API_TOKEN", "").strip()


def cloudflare_model(task: str = "text") -> str:
    if task not in DEFAULT_MODELS:
        raise ValueError("unsupported_cloudflare_model_task")
    model = os.getenv(MODEL_ENV[task], DEFAULT_MODELS[task]).strip()
    if not model or len(model) > 160 or not re.fullmatch(r"@[A-Za-z0-9._/-]+", model):
        raise ValueError("invalid_cloudflare_model_name")
    return model


def cloudflare_provider_ready(task: str | None = None) -> bool:
    if not cloudflare_account_id() or not cloudflare_api_token():
        return False
    if task is None:
        return True
    try:
        cloudflare_model(task)
    except ValueError:
        return False
    return True


def cloudflare_auth_headers(*, include_gateway: bool = False) -> dict[str, str]:
    token = cloudflare_api_token()
    if not token:
        raise RuntimeError("cloudflare_api_token_not_configured")
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
        "Content-Type": "application/json",
    }
    if include_gateway:
        headers.update({
            "cf-aig-gateway-id": os.getenv("CLOUDFLARE_AI_GATEWAY_ID", "default").strip() or "default",
            "cf-aig-collect-log": "false",
            "cf-aig-collect-log-payload": "false",
        })
    return headers


def cloudflare_chat_completions_url() -> str:
    account_id = cloudflare_account_id()
    if not account_id:
        raise RuntimeError("cloudflare_account_id_not_configured")
    return (
        f"https://api.cloudflare.com/client/v4/accounts/{account_id}"
        "/ai/v1/chat/completions"
    )


def cloudflare_run_url(model: str) -> str:
    account_id = cloudflare_account_id()
    if not account_id:
        raise RuntimeError("cloudflare_account_id_not_configured")
    safe_model = model.strip()
    if len(safe_model) > 160 or not re.fullmatch(r"@[A-Za-z0-9._/-]+", safe_model):
        raise ValueError("invalid_cloudflare_model_name")
    return (
        f"https://api.cloudflare.com/client/v4/accounts/{account_id}"
        f"/ai/run/{safe_model}"
    )


async def cloudflare_chat_completion(
    messages: list[dict[str, Any]],
    *,
    response_format: dict[str, Any] | None = None,
    max_tokens: int = 1800,
    temperature: float = 0.1,
    timeout_seconds: float = 90,
) -> str:
    if not cloudflare_provider_ready("text"):
        raise RuntimeError("cloudflare_workers_ai_not_configured")

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
            headers=cloudflare_auth_headers(include_gateway=True),
            json=payload,
        )
        response.raise_for_status()
        body = response.json()

    choices = body.get("choices") or []
    if not choices:
        raise RuntimeError("cloudflare_workers_ai_empty_completion")
    message = choices[0].get("message") or {}
    content = message.get("content")
    if isinstance(content, list):
        content = "".join(
            str(part.get("text", "")) for part in content if isinstance(part, dict)
        )
    if not isinstance(content, str) or not content.strip():
        raise RuntimeError("cloudflare_workers_ai_empty_completion")
    return content.strip()


async def cloudflare_run_model(
    task: str,
    payload: dict[str, Any],
    *,
    timeout_seconds: float = 90,
) -> dict[str, Any]:
    if not cloudflare_provider_ready(task):
        raise RuntimeError("cloudflare_workers_ai_not_configured")

    timeout = httpx.Timeout(timeout_seconds, connect=8.0)
    async with httpx.AsyncClient(timeout=timeout) as client:
        response = await client.post(
            cloudflare_run_url(cloudflare_model(task)),
            headers=cloudflare_auth_headers(include_gateway=True),
            json=payload,
        )
        response.raise_for_status()
        body = response.json()

    if body.get("success") is False:
        raise RuntimeError("cloudflare_workers_ai_request_failed")
    result = body.get("result")
    if not isinstance(result, dict):
        raise RuntimeError("cloudflare_workers_ai_invalid_response")
    return result
