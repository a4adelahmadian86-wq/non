from __future__ import annotations

import os
import unittest
from unittest.mock import patch

from fastapi.testclient import TestClient

from main import app


class ProductSupervisorFileApiTests(unittest.TestCase):
    def setUp(self):
        self.client = TestClient(app)
        self.env = patch.dict(os.environ, {"SUPERVISOR_API_TOKEN": "test-token"}, clear=False)
        self.env.start()

    def tearDown(self):
        self.env.stop()

    def test_document_facts_require_exact_source_evidence_and_use_server_locator(self):
        source_chunks = [{
            "locator": {"page": 7, "reader_page": 7},
            "content_text": "این کتاب شامل هشت فصل آموزشی است.",
        }]
        facts = [
            {
                "fact_type": "quantity",
                "fact_key": "تعداد فصل‌ها",
                "fact_value": "هشت فصل",
                "evidence": "این کتاب شامل هشت فصل آموزشی است.",
                "locator": {"page": 999},
            },
            {
                "fact_type": "quantity",
                "fact_key": "تعداد صفحات",
                "fact_value": "۹۹۹ صفحه",
                "evidence": "این کتاب ۹۹۹ صفحه دارد.",
            },
        ]

        accepted = validate_evidence_facts(facts, source_chunks)

        self.assertEqual(len(accepted), 1)
        self.assertEqual(accepted[0]["verification_status"], "evidence_backed")
        self.assertEqual(accepted[0]["locator"]["page"], 7)
        self.assertNotEqual(accepted[0]["locator"]["page"], 999)

    def test_file_review_requires_bearer_token(self):
        response = self.client.post(
            "/v1/reviews/files",
            data={"product_id": "p-1", "product_title": "عنوان آزمایشی", "product_type": "digital_file", "primary_scan_status": "clean"},
            files={"file": ("description.txt", "متن محصول".encode(), "text/plain")},
        )
        self.assertEqual(response.status_code, 401)

    def test_file_review_never_authorizes_publication(self):
        with patch.dict(os.environ, {"GEMINI_API_KEY": "", "GROQ_API_KEY": ""}):
            response = self.client.post(
                "/v1/reviews/files",
                headers={"Authorization": "Bearer test-token"},
                data={
                    "product_id": "p-1",
                    "product_title": "عنوان آزمایشی",
                    "product_type": "digital_file",
                    "primary_scan_status": "clean",
                    "category_name": "آموزشی",
                    "tags_json": '["آموزش"]',
                },
                files={"file": ("description.txt", "متن محصول".encode(), "text/plain")},
            )
        self.assertEqual(response.status_code, 200)
        body = response.json()
        self.assertFalse(body["publication_authorized"])
        self.assertFalse(body["automated_publication"])
        self.assertEqual(body["inspection"]["detected_type"], "text")
        self.assertEqual(body["security_gate"], "upstream_scan_verified")
        self.assertEqual(body["inspection"]["upstream_scan_status"], "clean")
        self.assertEqual(body["provider_status"]["gladia"], "disabled")

    def test_health_does_not_disclose_secret(self):
        response = self.client.get("/health")
        self.assertEqual(response.status_code, 200)
        self.assertNotIn("test-token", response.text)


if __name__ == "__main__":
    unittest.main()
