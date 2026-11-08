from __future__ import annotations

import io
import unittest
import zipfile

from docx import Document
from openpyxl import Workbook
from pptx import Presentation
from pptx.util import Inches
from pypdf import PdfWriter
from unittest.mock import patch
from PIL import Image

from file_pipeline import FileInspectionError, inspect_file, redact_sensitive_content


class FilePipelineTests(unittest.TestCase):
    def test_text_file_is_extracted(self):
        result = inspect_file("عنوان محصول\nشرح محصول".encode(), "description.txt")
        self.assertEqual(result.detected_type, "text")
        self.assertIn("عنوان محصول", result.extracted_text)

    def test_text_content_is_preserved_as_reusable_blocks_without_external_antivirus(self):
        with patch("file_pipeline._clamav_scan", side_effect=AssertionError("external scanner must not run")):
            result = inspect_file("عنوان محصول\\n\\nشرح کامل محصول".encode(), "description.txt", primary_scan_status="structural_clean")
        self.assertTrue(result.content_blocks)
        self.assertIn("شرح کامل محصول", result.content_blocks[1]["text"])
        self.assertEqual(result.antivirus_status, "not_configured")
        self.assertEqual(result.upstream_scan_status, "structural_clean")

    def test_pdf_page_count_is_preserved_for_reader_alignment(self):
        writer = PdfWriter()
        writer.add_blank_page(width=612, height=792)
        stream = io.BytesIO()
        writer.write(stream)
        result = inspect_file(stream.getvalue(), "sample.pdf", primary_scan_status="structural_clean")
        self.assertEqual(result.page_count, 1)
        self.assertEqual(result.public_dict()["page_count"], 1)

    def test_sensitive_values_are_redacted(self):
        text, count = redact_sensitive_content("Contact person@example.com or Bearer abcdefghijklmnopqrstuvwxyz")
        self.assertGreaterEqual(count, 2)
        self.assertNotIn("person@example.com", text)
        self.assertNotIn("abcdefghijklmnopqrstuvwxyz", text)

    def test_executable_extension_is_rejected(self):
        with self.assertRaises(FileInspectionError):
            inspect_file(b"MZ" + b"0" * 20, "payload.exe")

    def test_upload_size_is_bounded(self):
        with self.assertRaises(FileInspectionError):
            inspect_file(b"x" * (50 * 1024 * 1024 + 1), "large.txt")

    def test_docx_is_extracted_after_zip_validation(self):
        document = Document()
        document.add_paragraph("متن آزمایشی دوره")
        stream = io.BytesIO()
        document.save(stream)
        result = inspect_file(stream.getvalue(), "lesson.docx")
        self.assertEqual(result.detected_type, "docx")
        self.assertIn("متن آزمایشی دوره", result.extracted_text)

    def test_zip_path_traversal_is_rejected(self):
        stream = io.BytesIO()
        with zipfile.ZipFile(stream, "w") as archive:
            archive.writestr("../escape.txt", "bad")
        with self.assertRaises(FileInspectionError):
            inspect_file(stream.getvalue(), "package.zip")

    def test_generic_zip_is_not_extracted_or_executed(self):
        stream = io.BytesIO()
        with zipfile.ZipFile(stream, "w") as archive:
            archive.writestr("readme.txt", "hello")
        result = inspect_file(stream.getvalue(), "package.zip")
        self.assertEqual(result.detected_type, "zip")
        self.assertEqual(result.archive_entries, ["readme.txt"])

    def test_image_is_decoded_and_resized(self):
        stream = io.BytesIO()
        Image.new("RGB", (32, 24), "white").save(stream, format="PNG")
        result = inspect_file(stream.getvalue(), "cover.png")
        self.assertEqual(result.detected_type, "image")
        self.assertEqual(result.image_mime, "image/jpeg")
        self.assertTrue(result.image_bytes)


    def test_xlsx_content_is_extracted(self):
        workbook = Workbook()
        sheet = workbook.active
        sheet["A1"] = "عنوان دوره"
        sheet["B1"] = "مبانی فروش"
        stream = io.BytesIO()
        workbook.save(stream)
        result = inspect_file(stream.getvalue(), "outline.xlsx")
        self.assertEqual(result.detected_type, "xlsx")
        self.assertIn("مبانی فروش", result.extracted_text)

    def test_pptx_content_is_extracted(self):
        presentation = Presentation()
        slide = presentation.slides.add_slide(presentation.slide_layouts[5])
        shape = slide.shapes.add_textbox(Inches(1), Inches(1), Inches(5), Inches(1))
        shape.text = "جلسه اول دوره"
        stream = io.BytesIO()
        presentation.save(stream)
        result = inspect_file(stream.getvalue(), "course.pptx")
        self.assertEqual(result.detected_type, "pptx")
        self.assertIn("جلسه اول دوره", result.extracted_text)

    def test_wav_is_not_misclassified_as_image(self):
        wav = b"RIFF" + (36).to_bytes(4, "little") + b"WAVEfmt " + b"0" * 16 + b"data" + b"0" * 4
        result = inspect_file(wav, "sample.wav")
        self.assertEqual(result.detected_type, "audio")


if __name__ == "__main__":
    unittest.main()
