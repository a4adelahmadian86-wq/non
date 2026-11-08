<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductPreview;
use App\Models\StoreProductFile;
use App\Models\StoreProductDocument;
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
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="22" customWidth="1"/></cols><sheetData><row r="1" ht="30" customHeight="1"><c r="A1" t="inlineStr"><is><t>عنوان</t></is></c><c r="B1" s="1"><v>42</v></c><c r="C1" s="2"><f>1+1</f><v>73</v></c><c r="D1" s="3"><v>0.125</v></c><c r="E1" s="4"><v>45292</v></c></row><row r="2"><c r="F2" t="inlineStr"><is><t>ادغام</t></is></c><c r="G2"><v>9</v></c></row></sheetData><mergeCells count="1"><mergeCell ref="F2:G2"/></mergeCells></worksheet>',
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

    public function test_word_page_builder_preserves_semantic_block_types_and_bounds_cells(): void
    {
        $built = app(\App\Services\StoreProductDocumentPageBuilder::class)->build([
            ['chunk_type' => 'docx_heading', 'locator' => ['heading_level' => 1], 'content_text' => 'فصل اول'],
            ['chunk_type' => 'docx_paragraph', 'locator' => [], 'content_text' => 'مقدمه'],
            ['chunk_type' => 'docx_list_item', 'locator' => ['list_kind' => 'number', 'list_level' => 2], 'content_text' => 'گزینه'],
            ['chunk_type' => 'docx_table_row', 'locator' => ['table_index' => 1, 'row_index' => 1, 'cells' => ['عنوان', 'مقدار']], 'content_text' => 'عنوان | مقدار'],
        ], ['detected_type' => 'docx']);

        $this->assertCount(1, $built['pages']);
        $blocks = $built['pages'][0]['locator_json']['content_blocks'];
        $this->assertSame(['heading', 'paragraph', 'list_item', 'table_row'], array_column($blocks, 'type'));
        $this->assertSame(1, $blocks[0]['level']);
        $this->assertSame('number', $blocks[2]['list_kind']);
        $this->assertSame(['عنوان', 'مقدار'], $blocks[3]['cells']);
        $this->assertSame('مقدمه', $blocks[1]['text']);
    }

    public function test_authenticated_reader_progress_and_bookmarks_sync_across_sessions(): void
    {
        [$product] = $this->makePreviewFixture();
        $user = $this->makeReaderUser();

        $this->actingAs($user)
            ->putJson(route('store.reader.state.update', $product), [
                'progress' => ['section' => 0, 'page' => 4],
                'bookmarks' => [
                    ['section' => 0, 'page' => 2],
                    ['section' => 0, 'page' => 4],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('progress.page', 4)
            ->assertJsonCount(2, 'bookmarks');

        $this->actingAs($user)
            ->getJson(route('store.reader.state.show', $product))
            ->assertOk()
            ->assertJsonPath('progress.section', 0)
            ->assertJsonPath('progress.page', 4)
            ->assertJsonCount(2, 'bookmarks');

        $this->actingAs($user)
            ->putJson(route('store.reader.state.update', $product), [
                'progress' => ['section' => 5, 'page' => 1],
                'bookmarks' => [],
            ])
            ->assertStatus(422);
    }

    public function test_guest_cannot_access_account_reader_state_endpoints(): void
    {
        [$product] = $this->makePreviewFixture();

        $this->getJson(route('store.reader.state.show', $product))->assertUnauthorized();
        $this->putJson(route('store.reader.state.update', $product), [
            'progress' => ['section' => 0, 'page' => 1],
            'bookmarks' => [],
        ])->assertUnauthorized();
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

    public function test_reader_only_purchase_unlocks_page_text_but_never_the_original_file_or_download(): void
    {
        [$product, $sample] = $this->makePreviewFixture();
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

        $originalBytes = '%PDF-1.4 original private file';
        Storage::disk('private')->put('store/products/'.$product->id.'/full.pdf', $originalBytes);
        $file = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => '1.0',
            'disk' => 'private',
            'path' => 'store/products/'.$product->id.'/full.pdf',
            'original_name' => 'full.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => strlen($originalBytes),
            'sha256' => hash('sha256', $originalBytes),
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
            'scan_engine' => 'farast_static_v1',
            'scanned_at' => now(),
        ]);
        $document = StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $file->id,
            'source_sha256' => $file->sha256,
            'processing_status' => 'extracted',
            'detected_type' => 'pdf',
            'page_count' => 2,
            'structure_json' => [
                'reader' => ['ready' => true, 'page_count' => 2],
                'completeness' => [
                    'extraction_complete' => true,
                    'analysis_batches_complete' => false,
                    'semantic_review_complete' => false,
                ],
            ],
        ]);
        $document->pages()->create([
            'page_number' => 1,
            'page_kind' => 'pdf_page',
            'locator_key' => 'page:1',
            'locator_json' => [
                'page' => 1,
                'reader_page' => 1,
                'content_blocks' => [
                    ['type' => 'heading', 'level' => 1, 'text' => 'فصل اول'],
                    ['type' => 'paragraph', 'text' => 'متن توضیحی'],
                    ['type' => 'list_item', 'list_kind' => 'bullet', 'list_level' => 1, 'text' => 'گزینه نخست'],
                    ['type' => 'table_row', 'table_index' => 1, 'row_index' => 1, 'cells' => ['عنوان', 'مقدار'], 'text' => 'عنوان | مقدار'],
                ],
            ],
            'content_text' => 'متن مجاز صفحه اول کتاب',
            'content_html' => '<section><p>متن مجاز صفحه اول کتاب</p></section>',
            'content_sha256' => hash('sha256', 'متن مجاز صفحه اول کتاب'),
            'has_extracted_text' => true,
        ]);

        $document->pages()->create([
            'page_number' => 2,
            'page_kind' => 'sheet',
            'locator_key' => 'sheet:Sales',
            'locator_json' => ['sheet' => 'Sales', 'row_start' => 12, 'row_end' => 14, 'reader_page' => 2],
            'content_text' => "سطر ۱۲: نام | تعداد\nسطر ۱۳: کتاب | ۴\nسطر ۱۴: دفتر | ۷",
            'content_html' => '<section>Sales</section>',
            'content_sha256' => hash('sha256', "سطر ۱۲: نام | تعداد\nسطر ۱۳: کتاب | ۴\nسطر ۱۴: دفتر | ۷"),
            'has_extracted_text' => true,
        ]);

        config([
            'services.document_audio.url' => 'https://document-audio.example.test/synthesize',
            'services.document_audio.token' => 'reader-test-token',
        ]);
        \Illuminate\Support\Facades\Queue::fake();

        $readerResponse = $this->actingAs($user)->get(route('store.reader', $product->slug));
        $readerResponse->assertOk()
            ->assertSee('data-reader-mode="document"', false)
            ->assertSee('data-reader-audio-status="pending"', false)
            ->assertSee(route('store.reader.audio', [$product, $document]).'?version=3', false)
            ->assertSee(route('store.reader.page', [$product, 1]), false)
            ->assertDontSee(route('store.preview', $full), false)
            ->assertDontSee($originalBytes, false);


        $pageResponse = $this->actingAs($user)->getJson(route('store.reader.page', [$product, 1]));
        $pageResponse->assertOk()
            ->assertJsonPath('page_number', 1)
            ->assertJsonPath('content_text', 'متن مجاز صفحه اول کتاب')
            ->assertJsonPath('content_blocks.0.type', 'heading')
            ->assertJsonPath('content_blocks.0.level', 1)
            ->assertJsonPath('content_blocks.2.list_kind', 'bullet')
            ->assertJsonPath('content_blocks.3.cells.1', 'مقدار')
            ->assertJsonMissingPath('path')
            ->assertJsonMissingPath('disk');

        $sheetResponse = $this->actingAs($user)->getJson(route('store.reader.page', [$product, 2]));
        $sheetResponse->assertOk()
            ->assertJsonPath('page_kind', 'sheet')
            ->assertJsonPath('sheet_name', 'Sales')
            ->assertJsonPath('row_start', 12)
            ->assertJsonPath('row_end', 14)
            ->assertJsonMissingPath('path')
            ->assertJsonMissingPath('disk');

        $audioBytes = '0123456789';
        $audioPath = 'store/products/'.$product->id.'/artifacts/'.$document->source_sha256.'/audio-v3.wav';
        Storage::disk('private')->put($audioPath, $audioBytes);
        $document->artifacts()->create([
            'artifact_type' => 'audio_full_wav',
            'version' => 3,
            'disk' => 'private',
            'path' => $audioPath,
            'mime' => 'audio/wav',
            'size_bytes' => strlen($audioBytes),
            'sha256' => hash('sha256', $audioBytes),
            'metadata_json' => ['source_sha256' => $document->source_sha256, 'duration_ms' => 1000],
        ]);
        $headResponse = $this->actingAs($user)
            ->call('HEAD', route('store.reader.audio', [$product, $document]).'?version=3');
        $headResponse->assertOk()
            ->assertHeader('Content-Type', 'audio/wav')
            ->assertHeader('Content-Length', '10');

        $audioResponse = $this->actingAs($user)
            ->withHeader('Range', 'bytes=2-5')
            ->get(route('store.reader.audio', [$product, $document]));
        $audioResponse->assertStatus(206)->assertHeader('Content-Range', 'bytes 2-5/10');

        $this->actingAs($user)->get(route('store.preview', $full))->assertNotFound();
        $this->actingAs($user)->post(route('library.download.issue', $libraryItem))->assertForbidden();
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

        $streamedHtml = $response->getContent();
        $this->assertStringContainsString('09123456789', $streamedHtml);
        $this->assertStringContainsString('گزارش', $streamedHtml);
        $this->assertStringContainsString('42', $streamedHtml);
        $this->assertStringContainsString('font-weight:700;color:#1F4E78;background-color:#D9EAF7;text-align:center;vertical-align:middle;white-space:normal', $streamedHtml);
        $this->assertStringContainsString('73', $streamedHtml);
        $this->assertStringContainsString('12.5%', $streamedHtml);
        $this->assertStringContainsString('2024-01-01', $streamedHtml);
        $this->assertStringContainsString('colspan="2"', $streamedHtml);
        $this->assertStringContainsString('width:159px', $streamedHtml);
        $this->assertStringContainsString('height:40px', $streamedHtml);
        $this->assertStringContainsString('ادغام', $streamedHtml);
        $this->assertStringNotContainsString('>9</td>', $streamedHtml);
        $this->assertStringNotContainsString('javascript:', $streamedHtml);
        $this->assertStringNotContainsString('1+1', $streamedHtml);
        $this->assertStringContainsString('فرمول‌ها اجرا نمی‌شوند', $streamedHtml);
        $this->assertStringNotContainsString('<script', $streamedHtml);

        $this->assertStringContainsString("style-src 'unsafe-inline'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertNotSame($preparedHtml, $streamedHtml);
        $this->assertStringContainsString('09123456789', $streamedHtml);
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

        $this->withHeader('Range', 'bytes=20-30')
            ->get(route('store.preview.audio', $preview))
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */10');

        $this->withHeader('Range', 'bytes=0-1,4-5')
            ->get(route('store.preview.audio', $preview))
            ->assertOk()
            ->assertHeader('Content-Length', '10');
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


    public function test_workbook_preview_injects_a_fresh_watermark_for_each_viewer(): void
    {
        [$product] = $this->makePreviewFixture();
        $html = app(\App\Services\StoreXlsxPreviewRenderer::class)->render(
            $this->makeXlsxBytes(),
            '__FARAST_READER_IDENTITY__',
            true
        );
        $path = 'store/previews/'.$product->id.'/workbook-sample.html';
        Storage::disk('private')->put($path, $html);
        $preview = StoreProductPreview::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => $path,
            'mime' => 'text/html',
            'page_number' => 2,
            'kind' => 'html',
            'access_level' => 'sample',
            'watermarked' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $guestIp = '203.0.113.77';
        $guestResponse = $this->withServerVariables(['REMOTE_ADDR' => $guestIp])
            ->get(route('store.preview', $preview));
        $guestResponse->assertOk()
            ->assertSee($guestIp)
            ->assertDontSee('__FARAST_READER_IDENTITY__');

        $user = $this->makeReaderUser();
        $memberResponse = $this->actingAs($user)->get(route('store.preview', $preview));
        $memberResponse->assertOk()
            ->assertSee('09123456789')
            ->assertDontSee($guestIp)
            ->assertDontSee('__FARAST_READER_IDENTITY__');
    }


}
