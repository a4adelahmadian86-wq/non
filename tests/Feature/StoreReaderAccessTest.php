<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductPreview;
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

    private function makeReaderUser(): User
    {
        return User::create([
            'name' => 'Reader QA',
            'mobile' => '09123456789',
            'password' => 'secret',
        ]);
    }

    public function test_guest_cannot_open_reader_or_fetch_a_preview_asset(): void
    {
        [$product, $preview] = $this->makePreviewFixture();

        $this->get(route('store.reader', $product->slug))->assertRedirect(route('login'));
        $this->get(route('store.preview', $preview))->assertRedirect(route('login'));
    }

    public function test_docx_preview_is_rendered_as_paginated_watermarked_pdf(): void
    {
        [$product, $preview] = $this->makePreviewFixture();

        $temporary = tempnam(sys_get_temp_dir(), 'farast-docx-test-');
        $document = new \PhpOffice\PhpWord\PhpWord();
        $document->addSection()->addText('متن محرمانه نمونه Word');
        \PhpOffice\PhpWord\IOFactory::createWriter($document, 'Word2007')->save($temporary);
        Storage::disk('private')->put('store/previews/sample/preview.docx', file_get_contents($temporary));
        @unlink($temporary);

        $preview->update([
            'path' => 'store/previews/sample/preview.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'kind' => 'docx',
        ]);

        $response = $this->actingAs($this->makeReaderUser())->get(route('store.preview', $preview));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="farast-word-preview.pdf"');

        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertGreaterThan(1000, strlen($response->getContent()));

        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_docx_preview_rejects_external_relationships(): void
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
            ->assertStatus(422);
    }

    public function test_docx_preview_rejects_invalid_zip_payloads(): void
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
            ->assertStatus(422);
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
            ->assertSee('farast-reader.js');

        $response = $this->actingAs($user)->get(route('store.preview', $preview));

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Disposition', 'inline');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }
}
