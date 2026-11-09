from __future__ import annotations

import io
import os
import unittest
from unittest.mock import AsyncMock, patch

from fastapi.testclient import TestClient
from docx import Document
from docx.shared import Inches
from PIL import Image
from pypdf import PdfWriter

from main import app, validate_evidence_facts


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
        with patch.dict(os.environ, {"GEMINI_API_KEY": "AIza-test-only-not-a-real-key", "GROQ_API_KEY": "", "GROQ_ZDR_CONFIRMED": ""}):
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
        self.assertEqual(body["provider_status"]["gemini"], "not_requested")
        self.assertEqual(body["provider_status"]["groq_text"], "not_configured")
        self.assertTrue(any("هیچ متن فایلی" in warning for warning in body["warnings"]))
        self.assertEqual(body["analysis_batch_count"], 0)
        self.assertEqual(body["failed_analysis_batches"], 0)


    def test_mixed_document_images_fail_closed_without_groq_zdr_and_never_use_gemini(self):
        image_stream = io.BytesIO()
        Image.new("RGB", (32, 32), "white").save(image_stream, format="PNG")
        document = Document()
        document.add_paragraph("متن راهنمای محصول")
        document.add_picture(io.BytesIO(image_stream.getvalue()), width=Inches(1))
        source = io.BytesIO()
        document.save(source)

        with patch.dict(os.environ, {
            "GEMINI_API_KEY": "AIza-test-only-not-a-real-key",
            "GROQ_API_KEY": "test-groq-key",
            "GROQ_ZDR_CONFIRMED": "",
        }, clear=False), patch("file_pipeline.analyze_image_with_groq", new_callable=AsyncMock) as vision, patch(
            "file_pipeline.analyze_extracted_content", new_callable=AsyncMock
        ) as text_review:
            response = self.client.post(
                "/v1/reviews/files",
                headers={"Authorization": "Bearer test-token"},
                data={
                    "product_id": "p-mixed",
                    "product_title": "عنوان آزمایشی",
                    "product_type": "digital_file",
                    "primary_scan_status": "clean",
                },
                files={"file": ("mixed.docx", source.getvalue(), "application/vnd.openxmlformats-officedocument.wordprocessingml.document")},
            )

        self.assertEqual(response.status_code, 200)
        body = response.json()
        self.assertEqual(body["provider_status"]["gemini"], "not_requested")
        self.assertEqual(body["provider_status"]["groq_vision"], "not_configured")
        self.assertEqual(body["provider_status"]["groq_text"], "not_configured")
        self.assertEqual(body["inspection"]["embedded_image_count"], 1)
        self.assertTrue(body["inspection"]["visual_review_required"])
        self.assertTrue(body["inspection"]["visual_inventory_complete"])
        self.assertFalse(body["publication_authorized"])
        vision.assert_not_awaited()
        text_review.assert_not_awaited()

    def test_gemini_knowledge_payload_excludes_raw_document_chunks(self):
        from main import minimize_document_knowledge_for_gemini
        report = minimize_document_knowledge_for_gemini({
            "knowledge_status": "available",
            "access_scope": "full_document",
            "sources": [{
                "source_file_id": 4, "source_sha256": "a" * 64,
                "summary": "گزارش بررسی‌شده",
                "chunks": [{"content": "متن کامل محرمانه فایل"}],
                "sample_pages": ["متن خام صفحه و محتوای کامل محرمانه"],
                "sample_sheets": [{"rows": [["متن خام شیت محرمانه"]]}],
                "findings": [{"area": "content", "severity": "warning", "title": "نقص", "detail": "گزارش کوتاه", "recommendation": "اصلاح شود"}],
                "facts": [{"type": "feature", "key": "ویژگی", "value": "مقدار", "evidence": "شاهد کوتاه از سند", "locator": {"page": 3}, "verification_status": "evidence_backed"},
                          {"type": "feature", "key": "ساختگی", "value": "نباید وارد شود", "evidence": "بدون تأیید", "locator": {}, "verification_status": "unverified"}],
            }],
        }, lambda value: (value, 0))
        source = report["sources"][0]
        self.assertNotIn("chunks", source)
        self.assertNotIn("sample_pages", source)
        self.assertNotIn("sample_sheets", source)
        self.assertNotIn("متن کامل محرمانه فایل", str(report))
        self.assertNotIn("متن خام صفحه", str(report))
        self.assertNotIn("متن خام شیت", str(report))
        self.assertEqual(len(source["facts"]), 1)
        self.assertEqual(source["facts"][0]["verification_status"], "evidence_backed")
        self.assertEqual(source["facts"][0]["locator"]["page"], 3)

    def test_gemini_model_identifier_is_not_accepted_as_api_key(self):
        from main import _gemini_api_key_is_configured
        with patch.dict(os.environ, {"GEMINI_API_KEY": "gemini-2.5-flash", "GEMINI_MODEL": "gemini-2.5-flash"}, clear=False):
            self.assertFalse(_gemini_api_key_is_configured())

    def test_docx_reader_source_keeps_semantic_blocks_while_pdf_remains_visual_artifact(self):
        document = Document()
        document.add_heading("فصل ساختاریافته", level=1)
        document.add_paragraph("متن توضیحی")
        table = document.add_table(rows=1, cols=2)
        table.cell(0, 0).text = "عنوان"
        table.cell(0, 1).text = "مقدار"
        docx_stream = io.BytesIO()
        document.save(docx_stream)

        writer = PdfWriter()
        writer.add_blank_page(width=200, height=300)
        pdf_stream = io.BytesIO()
        writer.write(pdf_stream)

        with patch.dict(os.environ, {"GEMINI_API_KEY": "", "GROQ_API_KEY": ""}):
            response = self.client.post(
                "/v1/reviews/files",
                headers={"Authorization": "Bearer test-token"},
                data={
                    "product_id": "p-docx",
                    "product_title": "سند Word",
                    "product_type": "digital_file",
                    "primary_scan_status": "structural_checks_passed",
                },
                files={
                    "file": ("lesson.pdf", pdf_stream.getvalue(), "application/pdf"),
                    "reader_source_file": ("lesson.docx", docx_stream.getvalue(), "application/vnd.openxmlformats-officedocument.wordprocessingml.document"),
                },
            )

        self.assertEqual(response.status_code, 200, response.text)
        body = response.json()
        self.assertEqual(body["inspection"]["detected_type"], "docx")
        self.assertEqual(body["inspection"]["page_count"], 1)
        self.assertEqual(
            [chunk["chunk_type"] for chunk in body["content_chunks"]],
            ["docx_heading", "docx_paragraph", "docx_table_row"],
        )
        self.assertEqual(body["content_chunks"][0]["locator"]["heading_level"], 1)
        self.assertEqual(body["content_chunks"][2]["locator"]["cells"], ["عنوان", "مقدار"])

    def test_health_does_not_disclose_secret(self):
        response = self.client.get("/health")
        self.assertEqual(response.status_code, 200)
        self.assertNotIn("test-token", response.text)
        self.assertEqual(response.json()["providers"]["gemini"]["configured"], bool(os.getenv("GEMINI_API_KEY", "").strip()) and not os.getenv("GEMINI_API_KEY", "").strip().lower().startswith("gemini-"))
        self.assertEqual(response.json()["providers"]["groq"]["configured"], bool(os.getenv("GROQ_API_KEY", "").strip()))


    def test_authenticated_readiness_verifies_shared_token_without_provider_call(self):
        with patch.dict(os.environ, {"GEMINI_API_KEY": "", "GROQ_API_KEY": ""}):
            response = self.client.get(
                "/v1/ready",
                headers={"Authorization": "Bearer test-token"},
            )
        self.assertEqual(response.status_code, 200)
        self.assertTrue(response.json()["authenticated"])
        self.assertFalse(response.json()["providers"]["gemini"]["ready_for_product_review"])
        self.assertFalse(response.json()["providers"]["groq"]["ready_for_file_review"])

    def test_authenticated_readiness_rejects_missing_token(self):
        response = self.client.get("/v1/ready")
        self.assertEqual(response.status_code, 401)


if __name__ == "__main__":
    unittest.main()
