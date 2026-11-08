<?php

namespace Tests\Feature;

use App\Services\ProductFilePreflightScanner;
use RuntimeException;
use Tests\TestCase;

class ProductFilePreflightScannerTest extends TestCase
{
    public function test_pdf_is_inspected_without_external_scanner(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'farast-pdf-');
        file_put_contents($path, "%PDF-1.4\n1 0 obj <</Type /Page>> endobj\n2 0 obj <</Type /Pages /Count 1>> endobj\n%%EOF");

        try {
            $result = app(ProductFilePreflightScanner::class)->inspect($path, 'sample.pdf', 'application/pdf');

            $this->assertSame('pdf', $result['kind']);
            $this->assertSame(1, $result['page_count']);
            $this->assertSame(ProductFilePreflightScanner::ENGINE, $result['security']['engine']);
            $this->assertFalse($result['security']['external_scanner']);
            $this->assertSame('structural_clean', $result['security']['status']);
        } finally {
            @unlink($path);
        }
    }

    public function test_executable_signature_is_rejected_even_when_extension_is_pdf(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'farast-exe-');
        file_put_contents($path, "MZ".str_repeat("\0", 128));

        try {
            $this->expectException(RuntimeException::class);
            app(ProductFilePreflightScanner::class)->inspect($path, 'innocent.pdf', 'application/pdf');
        } finally {
            @unlink($path);
        }
    }

    public function test_unknown_extension_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'farast-unknown-');
        file_put_contents($path, 'plain content');

        try {
            $this->expectException(RuntimeException::class);
            app(ProductFilePreflightScanner::class)->inspect($path, 'payload.php', 'text/plain');
        } finally {
            @unlink($path);
        }
    }

    public function test_text_file_is_inspected_and_preview_is_bounded(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'farast-text-');
        file_put_contents($path, "First line\nSecond line\n");

        try {
            $result = app(ProductFilePreflightScanner::class)->inspect($path, 'notes.txt', 'text/plain');

            $this->assertSame('text', $result['kind']);
            $this->assertStringContainsString('First line', $result['text_preview']);
            $this->assertLessThanOrEqual(900, mb_strlen($result['text_preview']));
        } finally {
            @unlink($path);
        }
    }
}
