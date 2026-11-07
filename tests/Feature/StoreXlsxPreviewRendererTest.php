<?php

namespace Tests\Feature;

use App\Services\StoreXlsxPreviewRenderer;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class StoreXlsxPreviewRendererTest extends TestCase
{
    private function workbook(array $parts): string
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The ZIP extension is required for XLSX previews.');
        }

        $path = tempnam(sys_get_temp_dir(), 'farast-test-xlsx-');
        $zip = new ZipArchive();
        $this->assertSame(true, $zip->open($path, ZipArchive::OVERWRITE));
        foreach ($parts as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        $bytes = file_get_contents($path);
        @unlink($path);
        $this->assertIsString($bytes);

        return $bytes;
    }

    private function baseParts(string $relationship = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'): array
    {
        return [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="گزارش" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationship.'</Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>نام محصول</t></is></c><c r="B1" t="inlineStr"><is><t>مقدار</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>&lt;script&gt;alert(1)&lt;/script&gt;</t></is></c><c r="B2"><f>1+1</f><v>2</v></c></row></sheetData></worksheet>',
        ];
    }

    public function test_renders_cached_values_as_escaped_read_only_html_with_user_watermark(): void
    {
        $html = app(StoreXlsxPreviewRenderer::class)->render(
            $this->workbook($this->baseParts()),
            '09123456789'
        );

        $this->assertStringContainsString('نام محصول', $html);
        $this->assertStringContainsString('09123456789', $html);
        $this->assertStringContainsString('فرمول‌ها اجرا نمی‌شوند', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('>2</td>', $html);
    }

    public function test_rejects_external_workbook_relationships(): void
    {
        $relationship = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="https://example.invalid/sheet.xml" TargetMode="External"/>';
        $this->expectException(RuntimeException::class);

        app(StoreXlsxPreviewRenderer::class)->render(
            $this->workbook($this->baseParts($relationship)),
            '09123456789'
        );
    }

    public function test_rejects_path_traversal_inside_xlsx_archive(): void
    {
        $parts = $this->baseParts();
        $parts['xl/worksheets/../../outside.xml'] = '<unsafe/>';

        $this->expectException(RuntimeException::class);
        app(StoreXlsxPreviewRenderer::class)->render($this->workbook($parts), 'reader');
    }
}
