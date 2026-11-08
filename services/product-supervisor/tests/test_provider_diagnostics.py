from __future__ import annotations

import json
import os
import unittest
from unittest.mock import patch

import httpx

from file_pipeline import analyze_extracted_content


class _FakeResponse:
    def __init__(self, status_code: int, payload: dict | None = None):
        self.status_code = status_code
        self._payload = payload or {}
        self.request = httpx.Request("POST", "https://provider.invalid")

    def raise_for_status(self):
        if self.status_code >= 400:
            response = httpx.Response(self.status_code, request=self.request)
            raise httpx.HTTPStatusError("provider error", request=self.request, response=response)

    def json(self):
        return self._payload


class _FakeAsyncClient:
    responses: list[_FakeResponse] = []
    call_count = 0

    def __init__(self, *args, **kwargs):
        self.responses = type(self).responses
        type(self).call_count = 0

    async def __aenter__(self):
        return self

    async def __aexit__(self, exc_type, exc, traceback):
        return False

    async def post(self, *args, **kwargs):
        response = self.responses[type(self).call_count]
        type(self).call_count += 1
        return response


def _success_response():
    model_json = json.dumps({
        "summary": "تحلیل انجام شد",
        "findings": [],
        "sample_pages": [],
        "facts": [],
    }, ensure_ascii=False)
    return _FakeResponse(200, {
        "candidates": [{"content": {"parts": [{"text": model_json}]}}],
    })


class ProductSupervisorProviderDiagnosticsTests(unittest.IsolatedAsyncioTestCase):
    async def test_missing_key_is_reported_as_not_configured(self):
        with patch.dict(os.environ, {"GEMINI_API_KEY": ""}, clear=False):
            result = await analyze_extracted_content("متن محصول", {})
        self.assertEqual(result["analysis_status"], "not_configured")

    async def test_all_provider_batches_failing_is_not_silent_success(self):
        _FakeAsyncClient.responses = [_FakeResponse(429), _FakeResponse(503)]
        with patch.dict(os.environ, {"GEMINI_API_KEY": "test-key"}, clear=False):
            with patch("file_pipeline.httpx.AsyncClient", _FakeAsyncClient):
                result = await analyze_extracted_content("x" * 24_001, {})

        self.assertEqual(result["analysis_status"], "failed")
        self.assertEqual(result["successful_analysis_batches"], 0)
        self.assertEqual(result["failed_analysis_batches"], 2)
        self.assertEqual(result["analysis_failure_categories"]["provider_rate_limited"], 1)
        self.assertEqual(result["analysis_failure_categories"]["provider_unavailable"], 1)

    async def test_xlsx_sample_sheet_recommendations_are_returned_as_names(self):
        model_json = json.dumps({
            "summary": "برگه‌ها بررسی شدند",
            "findings": [],
            "sample_pages": [],
            "sample_sheets": ["گزارش فروش"],
            "facts": [],
        }, ensure_ascii=False)
        _FakeAsyncClient.responses = [_FakeResponse(200, {
            "candidates": [{"content": {"parts": [{"text": model_json}]}}],
        })]
        with patch.dict(os.environ, {"GEMINI_API_KEY": "test-key"}, clear=False):
            with patch("file_pipeline.httpx.AsyncClient", _FakeAsyncClient):
                result = await analyze_extracted_content("برگه: گزارش فروش\\nفروردین | 10", {"detected_file_type": "xlsx"})

        self.assertEqual(result["sample_sheets"], ["گزارش فروش"])

    async def test_partial_provider_failure_is_reported_with_batch_counts(self):
        _FakeAsyncClient.responses = [_success_response(), _FakeResponse(503)]
        with patch.dict(os.environ, {"GEMINI_API_KEY": "test-key"}, clear=False):
            with patch("file_pipeline.httpx.AsyncClient", _FakeAsyncClient):
                result = await analyze_extracted_content("x" * 24_001, {})

        self.assertEqual(result["analysis_status"], "partial")
        self.assertEqual(result["analysis_batch_count"], 2)
        self.assertEqual(result["successful_analysis_batches"], 1)
        self.assertEqual(result["failed_analysis_batches"], 1)
        self.assertEqual(result["analysis_failure_categories"]["provider_unavailable"], 1)


if __name__ == "__main__":
    unittest.main()
