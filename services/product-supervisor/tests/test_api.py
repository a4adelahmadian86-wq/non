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

    def test_file_review_requires_bearer_token(self):
        response = self.client.post(
            "/v1/reviews/files",
            data={"product_id": "p-1", "product_title": "عنوان آزمایشی", "product_type": "digital_file"},
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
        self.assertEqual(body["provider_status"]["gladia"], "disabled")

    def test_health_does_not_disclose_secret(self):
        response = self.client.get("/health")
        self.assertEqual(response.status_code, 200)
        self.assertNotIn("test-token", response.text)


if __name__ == "__main__":
    unittest.main()
