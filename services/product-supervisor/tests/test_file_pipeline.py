from __future__ import annotations

import io
import unittest
import tarfile
import zipfile

from docx import Document
from openpyxl import Workbook
from pptx import Presentation
from pptx.util import Inches
from PIL import Image

from file_pipeline import FileInspectionError, build_pdf_sample, inspect_file, redact_sensitive_content
from pypdf import PdfReader, PdfWriter


class FilePipelineTests(unittest.TestCase):
    def test_text_file_is_extracted(self):
        result = inspect_file("عنوان محصول\nشرح محصول".encode(), "description.txt")
        self.assertEqual(result.detected_type, "text")
        self.assertIn("عنوان محصول", result.extracted_text)
        self.assertEqual(result.content_chunks[0]["locator"], {"line_start": 1, "line_end": 2})
        self.assertEqual(result.content_chunks[0]["content_text"], "عنوان محصول\nشرح محصول")
        self.assertEqual(result.content_chunks[0]["chunk_type"], "text_lines")

    def test_pdf_sample_uses_only_valid_ai_selected_pages_and_keeps_page_order(self):
        writer = PdfWriter()
        for _ in range(4):
            writer.add_blank_page(width=200, height=300)
        source = io.BytesIO()
        writer.write(source)

        generated = build_pdf_sample(source.getvalue(), [4, 2, 4, 99])

        self.assertIsNotNone(generated)
        sample_bytes, selected_pages = generated
        self.assertEqual(selected_pages, [4, 2])
        self.assertEqual(len(PdfReader(io.BytesIO(sample_bytes)).pages), 2)

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
            inspect_file(b"x" * (25 * 1024 * 1024 + 1), "large.txt")

    def test_docx_is_extracted_after_zip_validation(self):
        document = Document()
        document.add_paragraph("متن آزمایشی دوره")
        stream = io.BytesIO()
        document.save(stream)
        result = inspect_file(stream.getvalue(), "lesson.docx")
        self.assertEqual(result.detected_type, "docx")
        self.assertIn("متن آزمایشی دوره", result.extracted_text)

    def test_docx_preserves_heading_list_and_table_structure_in_reader_chunks(self):
        document = Document()
        document.add_heading("فصل اول", level=1)
        document.add_paragraph("مقدمه ساختاریافته")
        document.add_paragraph("گزینه نخست", style="List Bullet")
        table = document.add_table(rows=2, cols=2)
        table.cell(0, 0).text = "عنوان"
        table.cell(0, 1).text = "مقدار"
        table.cell(1, 0).text = "کتاب"
        table.cell(1, 1).text = "<img src=x onerror=alert(1)>"
        stream = io.BytesIO()
        document.save(stream)

        result = inspect_file(stream.getvalue(), "structured.docx")

        self.assertEqual(result.detected_type, "docx")
        self.assertIn("فصل اول", result.extracted_text)
        self.assertIn("کتاب", result.extracted_text)
        self.assertEqual(
            [chunk["chunk_type"] for chunk in result.content_chunks],
            ["docx_heading", "docx_paragraph", "docx_list_item", "docx_table_row", "docx_table_row"],
        )
        self.assertEqual(result.content_chunks[0]["locator"]["heading_level"], 1)
        self.assertEqual(result.content_chunks[2]["locator"]["list_kind"], "bullet")
        self.assertEqual(result.content_chunks[3]["locator"]["cells"], ["عنوان", "مقدار"])
        self.assertEqual(result.content_chunks[4]["locator"]["cells"][1], "<img src=x onerror=alert(1)>")

    def test_docx_preserves_numbered_and_nested_list_metadata(self):
        document = Document()
        numbered = document.add_paragraph("مرحله نخست", style="List Number")
        numbered._p.get_or_add_pPr().get_or_add_numPr().get_or_add_ilvl().val = 1
        bulleted = document.add_paragraph("زیرگزینه", style="List Bullet")
        bulleted._p.get_or_add_pPr().get_or_add_numPr().get_or_add_ilvl().val = 2
        stream = io.BytesIO()
        document.save(stream)

        result = inspect_file(stream.getvalue(), "numbered-lists.docx")

        self.assertEqual(
            [chunk["chunk_type"] for chunk in result.content_chunks],
            ["docx_list_item", "docx_list_item"],
        )
        self.assertEqual(result.content_chunks[0]["locator"]["list_kind"], "number")
        self.assertEqual(result.content_chunks[0]["locator"]["list_level"], 2)
        self.assertEqual(result.content_chunks[1]["locator"]["list_kind"], "bullet")
        self.assertEqual(result.content_chunks[1]["locator"]["list_level"], 3)

    def test_docx_uses_numbering_part_format_for_custom_paragraph_styles(self):
        from docx.oxml.ns import qn

        document = Document()
        numbering = document.part.numbering_part.element
        abstract_formats = {}
        for abstract in numbering.findall(qn("w:abstractNum")):
            abstract_id = abstract.get(qn("w:abstractNumId"))
            first_level = abstract.find(qn("w:lvl"))
            number_format = first_level.find(qn("w:numFmt")) if first_level is not None else None
            if abstract_id is not None and number_format is not None:
                abstract_formats[str(abstract_id)] = str(number_format.get(qn("w:val"), ""))

        decimal_num_id = None
        for num in numbering.findall(qn("w:num")):
            abstract = num.find(qn("w:abstractNumId"))
            if abstract is not None and abstract_formats.get(str(abstract.get(qn("w:val")))) == "decimal":
                decimal_num_id = int(num.get(qn("w:numId")))
                break
        self.assertIsNotNone(decimal_num_id, "the DOCX template should expose a decimal numbering definition")

        paragraph = document.add_paragraph("شماره‌گذاری سفارشی", style="Normal")
        num_properties = paragraph._p.get_or_add_pPr().get_or_add_numPr()
        num_properties.get_or_add_numId().val = decimal_num_id
        num_properties.get_or_add_ilvl().val = 0
        stream = io.BytesIO()
        document.save(stream)

        result = inspect_file(stream.getvalue(), "custom-numbering.docx")

        self.assertEqual(result.content_chunks[0]["chunk_type"], "docx_list_item")
        self.assertEqual(result.content_chunks[0]["locator"]["list_kind"], "number")
        self.assertEqual(result.content_chunks[0]["locator"]["list_level"], 1)

    def test_zip_path_traversal_is_rejected(self):
        stream = io.BytesIO()
        with zipfile.ZipFile(stream, "w") as archive:
            archive.writestr("../escape.txt", "bad")
        with self.assertRaises(FileInspectionError):
            inspect_file(stream.getvalue(), "package.zip")

    def test_generic_zip_reads_bounded_supported_documents_without_extracting_to_disk(self):
        stream = io.BytesIO()
        with zipfile.ZipFile(stream, "w") as archive:
            archive.writestr("chapters/readme.txt", "متن فصل اول")
            archive.writestr("ignored.bin", b"\\x00\\x01")
        result = inspect_file(stream.getvalue(), "package.zip")
        self.assertEqual(result.detected_type, "zip")
        self.assertEqual(result.archive_entries, ["chapters/readme.txt", "ignored.bin"])
        self.assertIn("متن فصل اول", result.extracted_text)
        self.assertEqual(result.embedded_documents[0]["name"], "chapters/readme.txt")
        self.assertEqual(result.content_chunks[0]["locator"]["archive_entry"], "chapters/readme.txt")

    def test_tar_archive_reads_bounded_document_members_without_extracting_to_disk(self):
        stream = io.BytesIO()
        content = "متن داخل آرشیو TAR".encode("utf-8")
        with tarfile.open(fileobj=stream, mode="w", format=tarfile.USTAR_FORMAT) as archive:
            info = tarfile.TarInfo("docs/chapter.txt")
            info.size = len(content)
            archive.addfile(info, io.BytesIO(content))

        result = inspect_file(stream.getvalue(), "book.tar")
        self.assertEqual(result.detected_type, "tar")
        self.assertIn("docs/chapter.txt", result.archive_entries)
        self.assertIn("متن داخل آرشیو TAR", result.extracted_text)
        self.assertEqual(result.content_chunks[0]["locator"]["archive_entry"], "docs/chapter.txt")

    def test_tar_gzip_archive_is_inspected_without_extracting_to_disk(self):
        stream = io.BytesIO()
        content = b"Compressed TAR content"
        with tarfile.open(fileobj=stream, mode="w:gz", format=tarfile.USTAR_FORMAT) as archive:
            info = tarfile.TarInfo("readme.txt")
            info.size = len(content)
            archive.addfile(info, io.BytesIO(content))

        result = inspect_file(stream.getvalue(), "book.tar.gz")
        self.assertEqual(result.detected_type, "tar")
        self.assertIn("Compressed TAR content", result.extracted_text)

    def test_nested_archives_are_listed_but_not_recursively_extracted(self):
        nested = io.BytesIO()
        with zipfile.ZipFile(nested, "w") as inner:
            inner.writestr("secret.txt", "nested content")
        outer = io.BytesIO()
        with zipfile.ZipFile(outer, "w") as archive:
            archive.writestr("nested.zip", nested.getvalue())
        result = inspect_file(outer.getvalue(), "outer.zip")
        self.assertTrue(result.extraction_truncated)
        self.assertNotIn("nested content", result.extracted_text)
        self.assertTrue(any("تو در تو" in warning for warning in result.warnings))

    def test_image_is_decoded_and_resized(self):
        stream = io.BytesIO()
        Image.new("RGB", (32, 24), "white").save(stream, format="PNG")
        result = inspect_file(stream.getvalue(), "cover.png")
        self.assertEqual(result.detected_type, "image")
        self.assertEqual(result.image_mime, "image/jpeg")
        self.assertTrue(result.image_bytes)


    def test_docx_marks_the_reader_block_limit_as_truncated(self):
        document = Document()
        for index in range(301):
            document.add_paragraph(f"پاراگراف {index}")
        stream = io.BytesIO()
        document.save(stream)

        result = inspect_file(stream.getvalue(), "long-document.docx")

        self.assertTrue(result.extraction_truncated)
        self.assertEqual(len(result.content_chunks), 300)
        self.assertIn("پاراگراف 299", result.extracted_text)
        self.assertNotIn("پاراگراف 300", result.extracted_text)

    def test_xlsx_marks_sheet_row_and_column_safety_limits_as_truncated(self):
        workbook = Workbook()
        sheet = workbook.active
        for row in range(1, 302):
            sheet.cell(row=row, column=1, value=f"ردیف {row}")
        stream = io.BytesIO()
        workbook.save(stream)

        result = inspect_file(stream.getvalue(), "long-sheet.xlsx")

        self.assertTrue(result.extraction_truncated)
        self.assertIn("ردیف 300", result.extracted_text)
        self.assertNotIn("ردیف 301", result.extracted_text)

    def test_pptx_marks_slide_limit_as_truncated(self):
        presentation = Presentation()
        for _ in range(101):
            presentation.slides.add_slide(presentation.slide_layouts[5])
        stream = io.BytesIO()
        presentation.save(stream)

        result = inspect_file(stream.getvalue(), "long-presentation.pptx")

        self.assertTrue(result.extraction_truncated)
        self.assertEqual(result.page_count, 100)
        self.assertIn("اسلاید 100", result.extracted_text)
        self.assertNotIn("اسلاید 101", result.extracted_text)

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
