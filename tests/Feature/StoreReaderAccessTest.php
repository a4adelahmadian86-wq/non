<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductPreview;
use App\Models\StoreLibraryItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreReaderAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makePreviewFixture(): array
    {
        Storage::fake('private');
        Storage::disk('private')->put('store/previews/sample/preview.pdf', '%PDF-1.4 sample');

        $category = StoreCategory::create([
            'name' => 'نمونه',
            'slug' => 'reader-samples',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'سند نمونه خوانشگر',
            'slug' => 'reader-sample',
            'sku' => 'READER-SAMPLE',
            'type' => 'digital_file',
            'price_rials' => 0,
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'version' => '1.0',
        ]);
        $preview = StoreProductPreview::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => 'store/previews/sample/preview.pdf',
            'mime' => 'application/pdf',
            'page_number' => 1,
            'kind' => 'pdf',
            'watermarked' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return [$product, $preview];
    }

    private function makeXlsxBytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'farast-xlsx-test-');
        $archive = new \ZipArchive();
        $this->assertSame(true, $archive->open($path, \ZipArchive::OVERWRITE));
        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="گزارش" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>عنوان</t></is></c><c r="B1" s="1"><v>42</v></c><c r="C1" s="2"><f>1+1</f><v>73</v></c><c r="D1" s="3"><v>0.125</v></c><c r="E1" s="4"><v>45292</v></c></row></sheetData></worksheet>',
            'xl/styles.xml' => '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="0.0%"/></numFmts><fonts count="3"><font/><font><b/><color rgb="FF1F4E78"/></font><font><color rgb="url(javascript:alert(1))"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFD9EAF7"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="5"><xf/><xf fontId="1" fillId="1" borderId="0" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf fontId="2" fillId="0" borderId="0" xfId="0"/><xf numFmtId="164"/><xf numFmtId="14"/></cellXfs></styleSheet>',
        ];
        foreach ($parts as $name => $contents) {
            $archive->addFromString($name, $contents);
        }
        $archive->close();
        $bytes = file_get_contents($path);
        @unlink($path);

        $this->assertIsString($bytes);

        return $bytes;
    }

    private function makeReaderUser(): User
    {
        return User::create([
            'name' => 'Reader QA',
            'mobile' => '09123456789',
            'password' => 'secret',
        ]);
    }

    public function test_guest_can_read_public_samples_with_ip_watermark(): void
    {
        [$product, $preview] = $this->makePreviewFixture();
        $guestIp = '203.0.113.45';

        $this->withServerVariables(['REMOTE_ADDR' => $guestIp])
            ->get(route('store.reader', $product->slug))
            ->assertOk()
            ->assertSee($guestIp)
            ->assertSee('farast-reader-watermark');

        $previewResponse = $this->withServerVariables(['REMOTE_ADDR' => $guestIp])->get(route('store.preview', $preview));
        $previewResponse->assertOk();
        $this->assertSame('%PDF-1.4 sample', $previewResponse->streamedContent());
    }

    public function test_reader_only_purchase_unlocks_full_content_without_issuing_a_download(): void
    {
        [$product, $sample] = $this->makePreviewFixture();
        Storage::disk('private')->put('store/previews/sample/full.pdf', '%PDF-1.4 full');
        $full = StoreProductPreview::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => 'store/previews/sample/full.pdf',
            'mime' => 'application/pdf',
            'page_number' => 1,
            'kind' => 'pdf',
            'access_level' => 'full',
            'watermarked' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);
        $product->update(['download_policy' => 'reader_only']);

        $this->get(route('store.product', $product->slug))->assertOk()->assertDontSee(route('store.preview', $full));
        $this->get(route('store.reader', $product->slug))->assertOk()->assertSee(route('store.preview', $sample))->assertDontSee(route('store.preview', $full));
        $this->get(route('store.preview', $full))->assertForbidden();

        $user = $this->makeReaderUser();
        $order = Order::create([
            'user_id' => $user->id,
            'subtotal_rials' => 100,
            'total_rials' => 100,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        $libraryItem = StoreLibraryItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'granted_at' => now(),
        ]);

        $this->actingAs($user)->get(route('store.reader', $product->slug))
            ->assertOk()
            ->assertSee(route('store.preview', $full))
            ->assertDontSee(route('store.preview', $sample));
        $fullResponse = $this->actingAs($user)->get(route('store.preview', $full));
        $fullResponse->assertOk();
        $this->assertSame('%PDF-1.4 full', $fullResponse->streamedContent());
        $this->actingAs($user)->post(route('library.download.issue', $libraryItem))
            ->assertForbidden();
    }

    public function test_docx_sanitizer_drops_embedded_images_over_the_per_image_budget(): void
    {
        $controller = app(\App\Http\Controllers\StoreController::class);
        $method = new \ReflectionMethod($controller, 'sanitizeDocxHtml');
        $method->setAccessible(true);

        // 5.6 MB of base64 data represents more than the 4 MiB decoded-image ceiling.
        $oversizedImage = 'data:image/png;base64,'.str_repeat('A', 5_600_000);
        $html = $method->invoke($controller, '<p>متن امن</p><img src="'.$oversizedImage.'" alt="تصویر بزرگ">');

        $this->assertStringContainsString('متن امن', $html);
        $this->assertStringNotContainsString('src=', $html);
        $this->assertStringContainsString('alt="تصویر بزرگ"', $html);
    }

    public function test_docx_preview_is_rendered_as_paginated_watermarked_pdf(): void
    {
        [$product, $preview] = $this->makePreviewFixture();

        $temporary = tempnam(sys_get_temp_dir(), 'farast-docx-test-');
        $imagePath = tempnam(sys_get_temp_dir(), 'farast-docx-image-');
        file_put_contents(
            $imagePath,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGMUiVrAwMDAxMDAwMDAAAAMRgESCxsD7wAAAABJRU5ErkJggg==', true)
        );
        $document = new \PhpOffice\PhpWord\PhpWord();
        $section = $document->addSection(['orientation' => 'landscape']);
        $section->addText('متن محرمانه نمونه Word');
        $section->addImage($imagePath, ['width' => 24, 'height' => 24], false, null, 'تصویر نمونه');
        $table = $section->addTable();
        $table->addRow();
        $table->addCell(4500)->addText('عنوان جدول فارسی');
        $table->addCell(4500)->addText('مقدار نمونه');
        $section->addPageBreak();
        $section->addText('متن صفحه دوم سند Word');
        \PhpOffice\PhpWord\IOFactory::createWriter($document, 'Word2007')->save($temporary);

        $layoutMethod = new \ReflectionMethod(app(\App\Http\Controllers\StoreController::class), 'docxPageLayout');
        $layoutMethod->setAccessible(true);
        $layout = $layoutMethod->invoke(app(\App\Http\Controllers\StoreController::class), $temporary);
        $this->assertGreaterThan($layout['height'], $layout['width']);

        $preparedPdf = app(\App\Http\Controllers\StoreController::class)->prepareDocxPreview(file_get_contents($temporary));
        Storage::disk('private')->put('store/previews/sample/prepared-word.pdf', $preparedPdf);
        @unlink($temporary);
        @unlink($imagePath);

        $preview->update([
            'path' => 'store/previews/sample/prepared-word.pdf',
            'mime' => 'application/pdf',
            'kind' => 'pdf',
        ]);

        $response = $this->actingAs($this->makeReaderUser())->get(route('store.preview', $preview));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline');

        $pdf = $response->streamedContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        preg_match('/\\/Type\\s*\\/Pages[\\s\\S]*?\\/Count\\s+(\\d+)/', $pdf, $pageCountMatch);
        $this->assertGreaterThanOrEqual(2, (int) ($pageCountMatch[1] ?? 0));

        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_reader_rejects_unprepared_docx_assets(): void
    {
        [, $preview] = $this->makePreviewFixture();

        $temporary = tempnam(sys_get_temp_dir(), 'farast-docx-external-');
        $document = new \PhpOffice\PhpWord\PhpWord();
        $document->addSection()->addText('سند دارای پیوند خارجی');
        \PhpOffice\PhpWord\IOFactory::createWriter($document, 'Word2007')->save($temporary);

        $archive = new \ZipArchive();
        $this->assertSame(true, $archive->open($temporary));
        $archive->deleteName('word/_rels/document.xml.rels');
        $archive->addFromString(
            'word/_rels/document.xml.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rIdExternal" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.invalid/" TargetMode="External"/>'
                .'</Relationships>'
        );
        $archive->close();

        Storage::disk('private')->put('store/previews/sample/external.docx', file_get_contents($temporary));
        @unlink($temporary);
        $preview->update([
            'path' => 'store/previews/sample/external.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'kind' => 'docx',
        ]);

        $this->actingAs($this->makeReaderUser())
            ->get(route('store.preview', $preview))
            ->assertStatus(409);
    }

    public function test_reader_rejects_invalid_unprepared_docx_assets(): void
    {
        [, $preview] = $this->makePreviewFixture();
        Storage::disk('private')->put('store/previews/sample/invalid.docx', 'not a zip archive');
        $preview->update([
            'path' => 'store/previews/sample/invalid.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'kind' => 'docx',
        ]);

        $this->actingAs($this->makeReaderUser())
            ->get(route('store.preview', $preview))
            ->assertStatus(409);
    }

    public function test_xlsx_preview_is_served_as_sandboxable_read_only_html_with_watermark(): void
    {
        [$product, $preview] = $this->makePreviewFixture();
        $preparedHtml = app(\App\Services\StoreXlsxPreviewRenderer::class)->render($this->makeXlsxBytes(), '');
        Storage::disk('private')->put('store/previews/sample/workbook.html', $preparedHtml);
        $preview->update([
            'path' => 'store/previews/sample/workbook.html',
            'mime' => 'text/html',
            'kind' => 'html',
        ]);
        $user = $this->makeReaderUser();

        $this->actingAs($user)
            ->get(route('store.reader', $product->slug))
            ->assertOk()
            ->assertSee('farast-xlsx-frame')
            ->assertSee('09123456789')
            ->assertSee('sandbox', false)
            ->assertSee('Excel');

        $response = $this->actingAs($user)->get(route('store.preview', $preview));
        $response->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'inline; filename="farast-workbook-preview.html"')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        $streamedHtml = $response->streamedContent();
        $this->assertStringNotContainsString('09123456789', $streamedHtml);
        $this->assertStringContainsString('گزارش', $streamedHtml);
        $this->assertStringContainsString('42', $streamedHtml);
        $this->assertStringContainsString('font-weight:700;color:#1F4E78;background-color:#D9EAF7;text-align:center;vertical-align:middle;white-space:normal', $streamedHtml);
        $this->assertStringContainsString('73', $streamedHtml);
        $this->assertStringContainsString('12.5%', $streamedHtml);
        $this->assertStringContainsString('2024-01-01', $streamedHtml);
        $this->assertStringNotContainsString('javascript:', $streamedHtml);
        $this->assertStringNotContainsString('1+1', $streamedHtml);
        $this->assertStringContainsString('فرمول‌ها اجرا نمی‌شوند', $streamedHtml);
        $this->assertStringNotContainsString('<script', $streamedHtml);

        $this->assertStringContainsString("style-src 'unsafe-inline'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertSame($preparedHtml, $streamedHtml);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_reader_rejects_unprepared_xlsx_assets(): void
    {
        [, $preview] = $this->makePreviewFixture();
        $temporary = tempnam(sys_get_temp_dir(), 'farast-xlsx-external-');
        file_put_contents($temporary, $this->makeXlsxBytes());

        $archive = new \ZipArchive();
        $this->assertSame(true, $archive->open($temporary));
        $archive->deleteName('xl/_rels/workbook.xml.rels');
        $archive->addFromString(
            'xl/_rels/workbook.xml.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="https://example.invalid/sheet.xml" TargetMode="External"/></Relationships>'
        );
        $archive->close();

        Storage::disk('private')->put('store/previews/sample/external.xlsx', file_get_contents($temporary));
        @unlink($temporary);
        $preview->update([
            'path' => 'store/previews/sample/external.xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'kind' => 'xlsx',
        ]);

        $this->actingAs($this->makeReaderUser())
            ->get(route('store.preview', $preview))
            ->assertStatus(409);
    }

    public function test_reader_rejects_invalid_unprepared_xlsx_assets(): void
    {
        [, $preview] = $this->makePreviewFixture();
        Storage::disk('private')->put('store/previews/sample/invalid.xlsx', 'not a zip archive');
        $preview->update([
            'path' => 'store/previews/sample/invalid.xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'kind' => 'xlsx',
        ]);

        $this->actingAs($this->makeReaderUser())
            ->get(route('store.preview', $preview))
            ->assertStatus(409);
    }

    public function test_authenticated_reader_sees_account_watermark_and_private_preview_headers(): void
    {
        [$product, $preview] = $this->makePreviewFixture();
        $user = $this->makeReaderUser();

        $this->actingAs($user)
            ->get(route('store.reader', $product->slug))
            ->assertOk()
            ->assertSee('09123456789')
            ->assertSee('farast-reader-watermark')
            ->assertSee('is-pdf')
            ->assertSee('reader-search-input')
            ->assertSee('farast-reader.js')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; worker-src 'self' blob:");

        $response = $this->actingAs($user)->get(route('store.preview', $preview));

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Disposition', 'inline');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }
    public function test_sample_reader_audio_supports_byte_range_seeking(): void
    {
        [, $preview] = $this->makePreviewFixture();
        Storage::disk('private')->put('store/reader-audio/sample/narration.mp3', '0123456789');
        $preview->update([
            'audio_disk' => 'private',
            'audio_path' => 'store/reader-audio/sample/narration.mp3',
            'audio_mime' => 'audio/mpeg',
            'audio_timing' => [
                ['text' => 'سلام', 'start' => 0.0, 'end' => 0.4, 'page' => 1],
                ['text' => 'دنیا', 'start' => 0.4, 'end' => 0.8, 'page' => 1],
            ],
        ]);

        $response = $this->withHeader('Range', 'bytes=2-5')
            ->get(route('store.preview.audio', $preview));

        $response->assertStatus(206)
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Content-Length', '4');
        $this->assertSame('2345', $response->streamedContent());
    }

    public function test_full_reader_audio_requires_the_same_reader_entitlement_as_the_document(): void
    {
        [$product] = $this->makePreviewFixture();
        Storage::disk('private')->put('store/reader-audio/sample/full.mp3', 'private-audio');
        $preview = StoreProductPreview::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => 'store/previews/sample/preview.pdf',
            'mime' => 'application/pdf',
            'page_number' => 1,
            'kind' => 'pdf',
            'access_level' => 'full',
            'audio_disk' => 'private',
            'audio_path' => 'store/reader-audio/sample/full.mp3',
            'audio_mime' => 'audio/mpeg',
            'audio_timing' => [['text' => 'محرمانه', 'start' => 0, 'end' => 1, 'page' => 1]],
            'watermarked' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);
        $product->update(['download_policy' => 'reader_only']);

        $this->get(route('store.preview.audio', $preview))->assertForbidden();

        $user = $this->makeReaderUser();
        $this->actingAs($user)->get(route('store.preview.audio', $preview))->assertForbidden();

        $order = Order::create([
            'user_id' => $user->id,
            'subtotal_rials' => 100,
            'total_rials' => 100,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        StoreLibraryItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'granted_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('store.preview.audio', $preview));
        $response->assertOk();
        $this->assertSame('private-audio', $response->streamedContent());
    }

    public function test_reader_exposes_only_the_audio_route_and_word_timing_manifest(): void
    {
        [$product, $preview] = $this->makePreviewFixture();
        Storage::disk('private')->put('store/reader-audio/sample/narration.mp3', 'audio-bytes');
        $preview->update([
            'audio_disk' => 'private',
            'audio_path' => 'store/reader-audio/sample/narration.mp3',
            'audio_mime' => 'audio/mpeg',
            'audio_timing' => [['text' => 'سلام', 'start' => 0, 'end' => 0.4, 'page' => 1]],
        ]);

        $response = $this->get(route('store.reader', $product->slug));
        $response->assertOk()
            ->assertSee(route('store.preview.audio', $preview))
            ->assertSee('data-audio-timing')
            ->assertSee('farast-reader-audio.js')
            ->assertSee('سلام')
            ->assertDontSee('store/reader-audio/sample/narration.mp3');
    }

}
