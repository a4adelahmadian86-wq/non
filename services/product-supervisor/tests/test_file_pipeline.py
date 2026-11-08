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

from file_pipeline import FileInspectionError, inspect_file, redact_sensitive_content
from openpyxl.chart import BarChart, Reference
from openpyxl.drawing.image import Image as SpreadsheetImage
from pptx.chart.data import CategoryChartData
from pptx.enum.chart import XL_CHART_TYPE


class FilePipelineTests(unittest.TestCase):
    def test_text_file_is_extracted(self):
        result = inspect_file("عنوان محصول\nشرح محصول".encode(), "description.txt")
        self.assertEqual(result.detected_type, "text")
        self.assertIn("عنوان محصول", result.extracted_text)
        self.assertEqual(result.content_chunks[0]["locator"], {"line_start": 1, "line_end": 2})
        self.assertEqual(result.content_chunks[0]["content_text"], "عنوان محصول\nشرح محصول")
        self.assertEqual(result.content_chunks[0]["chunk_type"], "text_lines")

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

    def test_docx_extracts_footnotes_and_embedded_images(self):
        document = Document()
        document.add_paragraph("متن اصلی سند")
        image_stream = io.BytesIO()
        Image.new("RGB", (16, 16), "white").save(image_stream, format="PNG")
        document.add_picture(io.BytesIO(image_stream.getvalue()))
        source = io.BytesIO()
        document.save(source)

        rewritten = io.BytesIO()
        with zipfile.ZipFile(io.BytesIO(source.getvalue()), "r") as original, zipfile.ZipFile(rewritten, "w") as output:
            for item in original.infolist():
                output.writestr(item, original.read(item.filename))
            output.writestr(
                "word/footnotes.xml",
                '<?xml version="1.0"?><w:footnotes xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:footnote w:id="1"><w:p><w:r><w:t>توضیح پانویس</w:t></w:r></w:p></w:footnote></w:footnotes>',
            )

        result = inspect_file(rewritten.getvalue(), "lesson.docx")
        self.assertIn("متن اصلی سند", result.extracted_text)
        self.assertIn("توضیح پانویس", result.extracted_text)
        self.assertEqual(len(result.image_assets), 1)
        self.assertEqual(result.image_assets[0]["locator"]["media"], "docx_image")

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
        self.assertTrue(any(chunk.get("locator", {}).get("sheet") == "Sheet" for chunk in result.content_chunks))

    def test_xlsx_extracts_chart_metadata_and_embedded_images(self):
        workbook = Workbook()
        sheet = workbook.active
        sheet.append(["ماه", "فروش"])
        sheet.append(["فروردین", 10])
        sheet.append(["اردیبهشت", 20])
        chart = BarChart()
        chart.title = "نمودار فروش ماهانه"
        data = Reference(sheet, min_col=2, min_row=1, max_row=3)
        categories = Reference(sheet, min_col=1, min_row=2, max_row=3)
        chart.add_data(data, titles_from_data=True)
        chart.set_categories(categories)
        sheet.add_chart(chart, "D2")

        image_stream = io.BytesIO()
        Image.new("RGB", (12, 12), "white").save(image_stream, format="PNG")
        sheet.add_image(SpreadsheetImage(io.BytesIO(image_stream.getvalue())), "A5")

        stream = io.BytesIO()
        workbook.save(stream)
        result = inspect_file(stream.getvalue(), "report.xlsx")
        self.assertIn("نمودار فروش ماهانه", result.extracted_text)
        self.assertEqual(len(result.image_assets), 1)
        self.assertEqual(result.image_assets[0]["locator"]["media"], "xlsx_image")

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

    def test_pptx_extracts_chart_series_and_embedded_images(self):
        presentation = Presentation()
        slide = presentation.slides.add_slide(presentation.slide_layouts[5])
        chart_data = CategoryChartData()
        chart_data.categories = ["فروردین", "اردیبهشت"]
        chart_data.add_series("فروش", (10, 20))
        chart = slide.shapes.add_chart(
            XL_CHART_TYPE.COLUMN_CLUSTERED,
            Inches(1), Inches(1), Inches(5), Inches(3), chart_data
        ).chart
        chart.has_title = True
        chart.chart_title.text_frame.text = "نمودار درآمد"

        image_stream = io.BytesIO()
        Image.new("RGB", (12, 12), "white").save(image_stream, format="PNG")
        slide.shapes.add_picture(io.BytesIO(image_stream.getvalue()), Inches(1), Inches(4))

        stream = io.BytesIO()
        presentation.save(stream)
        result = inspect_file(stream.getvalue(), "charts.pptx")
        self.assertIn("نمودار درآمد", result.extracted_text)
        self.assertIn("فروش", result.extracted_text)
        self.assertIn("10", result.extracted_text)
        self.assertEqual(len(result.image_assets), 1)
        self.assertEqual(result.image_assets[0]["locator"]["media"], "pptx_image")

    def test_standalone_image_is_one_reader_page_with_a_visual_asset(self):
        image_stream = io.BytesIO()
        Image.new("RGB", (20, 20), "white").save(image_stream, format="PNG")
        result = inspect_file(image_stream.getvalue(), "cover.png")
        self.assertEqual(result.detected_type, "image")
        self.assertEqual(result.page_count, 1)
        self.assertEqual(len(result.image_assets), 1)

    def test_wav_is_not_misclassified_as_image(self):
        wav = b"RIFF" + (36).to_bytes(4, "little") + b"WAVEfmt " + b"0" * 16 + b"data" + b"0" * 4
        result = inspect_file(wav, "sample.wav")
        self.assertEqual(result.detected_type, "audio")


if __name__ == "__main__":
    unittest.main()
