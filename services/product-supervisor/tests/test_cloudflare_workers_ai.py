import asyncio
import base64
import json
import os
import unittest
from io import BytesIO
from unittest.mock import patch

import httpx
from PIL import Image

import cloudflare_provider
import file_pipeline


class FakeResponse:
    def __init__(self, body, status_code=200, url="https://api.cloudflare.test"):
        self._body = body
        self.status_code = status_code
        self.request = httpx.Request("POST", url)
        self._response = httpx.Response(status_code, request=self.request)

    def raise_for_status(self):
        if self.status_code >= 400:
            raise httpx.HTTPStatusError(
                "mock provider error",
                request=self.request,
                response=self._response,
            )

    def json(self):
        return self._body


class FakeAsyncClient:
    calls = []
    response_factory = None

    def __init__(self, *args, **kwargs):
        self.options = kwargs

    async def __aenter__(self):
        return self

    async def __aexit__(self, exc_type, exc, tb):
        return False

    async def post(self, url, headers=None, json=None, **kwargs):
        self.calls.append({"url": url, "headers": headers or {}, "json": json or {}})
        if type(self).response_factory:
            return type(self).response_factory(url, headers or {}, json or {})
        if url.endswith("/ai/v1/chat/completions"):
            body = {
                "choices": [{
                    "message": {
                        "content": jsonlib_dumps({
                            "summary": "خلاصه",
                            "findings": [],
                            "facts": [],
                            "sample_pages": [],
                            "sample_sheets": [],
                        })
                    }
                }]
            }
            return FakeResponse(body, url=url)
        if "moondream" in url:
            return FakeResponse({"result": {"answer": "تصویر شامل یک محصول است."}}, url=url)
        if "whisper" in url:
            return FakeResponse({"result": {"text": "متن صوت فارسی"}}, url=url)
        return FakeResponse({"result": {"response": "ok"}}, url=url)


def jsonlib_dumps(value):
    return json.dumps(value, ensure_ascii=False)


class CloudflareWorkersAiTests(unittest.TestCase):
    def setUp(self):
        FakeAsyncClient.calls = []
        FakeAsyncClient.response_factory = None
        self.env = patch.dict(os.environ, {
            "CLOUDFLARE_ACCOUNT_ID": "account-test",
            "CLOUDFLARE_API_TOKEN": "token-test-only",
            "CLOUDFLARE_TEXT_MODEL": "@cf/openai/gpt-oss-20b",
            "CLOUDFLARE_VISION_MODEL": "@cf/moondream/moondream3.1-9B-A2B",
            "CLOUDFLARE_AUDIO_MODEL": "@cf/openai/whisper-large-v3-turbo",
            "GROQ_API_KEY": "",
            "GROQ_ZDR_CONFIRMED": "",
        }, clear=False)
        self.env.__enter__()
        self.client_patch = patch("cloudflare_provider.httpx.AsyncClient", FakeAsyncClient)
        self.client_patch.start()

    def tearDown(self):
        self.client_patch.stop()
        self.env.__exit__(None, None, None)

    def test_cloudflare_provider_requires_account_and_scoped_token(self):
        self.assertTrue(cloudflare_provider.cloudflare_provider_ready("text"))
        with patch.dict(os.environ, {"CLOUDFLARE_API_TOKEN": ""}, clear=False):
            self.assertFalse(cloudflare_provider.cloudflare_provider_ready("text"))

    def test_gpt_oss_20b_uses_account_scoped_openai_compatible_endpoint(self):
        answer = asyncio.run(cloudflare_provider.cloudflare_chat_completion(
            [{"role": "user", "content": "سلام"}],
            response_format={"type": "json_object"},
        ))

        self.assertIn("خلاصه", answer)
        request = FakeAsyncClient.calls[-1]
        self.assertEqual(
            request["url"],
            "https://api.cloudflare.com/client/v4/accounts/account-test/ai/v1/chat/completions",
        )
        self.assertEqual(request["headers"]["Authorization"], "Bearer token-test-only")
        self.assertEqual(request["headers"]["cf-aig-gateway-id"], "default")
        self.assertEqual(request["headers"]["cf-aig-collect-log"], "false")
        self.assertEqual(request["headers"]["cf-aig-collect-log-payload"], "false")
        self.assertEqual(request["json"]["model"], "@cf/openai/gpt-oss-20b")
        self.assertEqual(request["json"]["response_format"], {"type": "json_object"})

    def test_file_text_analysis_prefers_cloudflare_without_groq_zdr(self):
        result = asyncio.run(file_pipeline.analyze_extracted_content(
            "این متن برای بررسی دقیق محصول است.",
            {"detected_file_type": "text"},
        ))

        self.assertEqual(result["analysis_status"], "completed")
        self.assertEqual(result["analysis_provider"], "cloudflare")
        request = FakeAsyncClient.calls[-1]
        self.assertEqual(request["json"]["model"], "@cf/openai/gpt-oss-20b")
        self.assertEqual(request["headers"]["Authorization"], "Bearer token-test-only")

    def test_moondream_vision_receives_a_bounded_data_uri_and_returns_caption(self):
        image_stream = BytesIO()
        Image.new("RGB", (24, 24), "white").save(image_stream, format="JPEG")

        result = asyncio.run(file_pipeline.analyze_image_with_cloudflare(
            image_stream.getvalue(),
            "image/jpeg",
            {"title": "تصویر محصول"},
        ))

        self.assertIn("تصویر شامل", result)
        request = FakeAsyncClient.calls[-1]
        self.assertIn("/ai/run/@cf/moondream/moondream3.1-9B-A2B", request["url"])
        self.assertEqual(request["headers"]["cf-aig-gateway-id"], "default")
        self.assertEqual(request["headers"]["cf-aig-collect-log-payload"], "false")
        self.assertTrue(request["json"]["image"].startswith("data:image/jpeg;base64,"))
        self.assertEqual(request["json"]["task"], "query")

    def test_whisper_transcription_sends_audio_as_base64_without_filename(self):
        audio = b"fake-audio-bytes"
        text, truncated = asyncio.run(file_pipeline.transcribe_audio_with_cloudflare(
            audio,
            "private-original-name.wav",
        ))

        self.assertEqual(text, "متن صوت فارسی")
        self.assertFalse(truncated)
        request = FakeAsyncClient.calls[-1]
        self.assertIn("/ai/run/@cf/openai/whisper-large-v3-turbo", request["url"])
        self.assertEqual(request["headers"]["cf-aig-gateway-id"], "default")
        self.assertEqual(request["headers"]["cf-aig-collect-log-payload"], "false")
        self.assertEqual(request["json"]["audio"], base64.b64encode(audio).decode("ascii"))
        self.assertNotIn("private-original-name", json.dumps(request["json"]))

    def test_file_analysis_propagates_cloudflare_rate_limit_for_laravel_queue_retry(self):
        FakeAsyncClient.response_factory = lambda url, headers, payload: FakeResponse(
            {"success": False, "errors": [{"message": "rate limited"}]},
            status_code=429,
            url=url,
        )
        with self.assertRaises(httpx.HTTPStatusError):
            asyncio.run(file_pipeline.analyze_extracted_content(
                "این متن باید پس از بازنشانی سهمیه دوباره بررسی شود.",
                {"detected_file_type": "text"},
            ))

    def test_cloudflare_rate_limit_is_not_hidden_as_a_success(self):
        FakeAsyncClient.response_factory = lambda url, headers, payload: FakeResponse(
            {"success": False, "errors": [{"message": "rate limited"}]},
            status_code=429,
            url=url,
        )
        with self.assertRaises(httpx.HTTPStatusError):
            asyncio.run(cloudflare_provider.cloudflare_chat_completion(
                [{"role": "user", "content": "سلام"}],
            ))


if __name__ == "__main__":
    unittest.main()
