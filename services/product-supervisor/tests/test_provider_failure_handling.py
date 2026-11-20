from __future__ import annotations

import asyncio
import os
import unittest
from unittest.mock import AsyncMock, patch

from httpx import ReadTimeout, Request, Response


class ProviderFailureHandlingTests(unittest.TestCase):
    def test_provider_timeout_is_recorded_as_failed_analysis_without_crashing(self):
        from file_pipeline import analyze_extracted_content

        with patch.dict(os.environ, {
            "GROQ_API_KEY": "test-groq-key",
            "GROQ_ZDR_CONFIRMED": "true",
        }, clear=False), patch(
            "file_pipeline.groq_provider_ready", return_value=True
        ), patch(
            "file_pipeline.httpx.AsyncClient.post",
            new_callable=AsyncMock,
            side_effect=ReadTimeout("provider timeout"),
        ) as provider_request:
            result = asyncio.run(analyze_extracted_content("متن قابل بررسی", {}))

        self.assertEqual(result["analysis_status"], "failed")
        self.assertEqual(result["analysis_batch_count"], 1)
        self.assertEqual(result["successful_analysis_batches"], 0)
        self.assertEqual(result["failed_analysis_batches"], 1)
        self.assertEqual(result["analysis_failure_categories"], {"provider_network_error": 1})
        provider_request.assert_awaited_once()

    def test_provider_rate_limit_is_recorded_as_failed_analysis_without_retrying_or_crashing(self):
        from file_pipeline import analyze_extracted_content

        provider_response = Response(
            429,
            request=Request("POST", "https://api.groq.com/openai/v1/chat/completions"),
        )
        with patch.dict(os.environ, {
            "GROQ_API_KEY": "test-groq-key",
            "GROQ_ZDR_CONFIRMED": "true",
        }, clear=False), patch(
            "file_pipeline.groq_provider_ready", return_value=True
        ), patch(
            "file_pipeline.httpx.AsyncClient.post",
            new_callable=AsyncMock,
            return_value=provider_response,
        ) as provider_request:
            result = asyncio.run(analyze_extracted_content("متن قابل بررسی", {}))

        self.assertEqual(result["analysis_status"], "failed")
        self.assertEqual(result["failed_analysis_batches"], 1)
        self.assertEqual(result["analysis_failure_categories"], {"provider_rate_limited": 1})
        provider_request.assert_awaited_once()


if __name__ == "__main__":
    unittest.main()
