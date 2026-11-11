<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreReaderAudioStreamingTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudioPreview(): StoreProductPreview
    {
        Storage::fake('private');
        Storage::disk('private')->put('store/reader-audio/streaming/narration.mp3', '0123456789');
        Storage::disk('private')->put('store/previews/streaming/preview.pdf', '%PDF-1.4 sample');

        $category = StoreCategory::create([
            'name' => 'Audio streaming QA',
            'slug' => 'audio-streaming-qa',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'Audio streaming fixture',
            'slug' => 'audio-streaming-fixture',
            'sku' => 'AUDIO-STREAMING-QA',
            'type' => 'digital_file',
            'price_rials' => 0,
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'version' => '1.0',
        ]);

        return StoreProductPreview::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => 'store/previews/streaming/preview.pdf',
            'mime' => 'application/pdf',
            'page_number' => 1,
            'kind' => 'pdf',
            'audio_disk' => 'private',
            'audio_path' => 'store/reader-audio/streaming/narration.mp3',
            'audio_mime' => 'audio/mpeg',
            'watermarked' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    public function test_audio_preview_supports_suffix_ranges_and_strong_etag_if_range(): void
    {
        $preview = $this->makeAudioPreview();

        $suffix = $this->withHeaders(['Range' => 'bytes=-4'])
            ->get(route('store.preview.audio', $preview));

        $suffix->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 6-9/10')
            ->assertHeader('Content-Length', '4')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('ETag')
            ->assertHeader('Last-Modified');
        $this->assertSame('6789', $suffix->streamedContent());

        $etag = (string) $suffix->headers->get('ETag');
        $matching = $this->withHeaders([
            'Range' => 'bytes=0-1',
            'If-Range' => $etag,
        ])->get(route('store.preview.audio', $preview));
        $matching->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-1/10');
        $this->assertSame('01', $matching->streamedContent());

        $stale = $this->withHeaders([
            'Range' => 'bytes=0-1',
            'If-Range' => '"stale-validator"',
        ])->get(route('store.preview.audio', $preview));
        $stale->assertOk()->assertHeader('Content-Length', '10');
        $this->assertFalse($stale->headers->has('Content-Range'));
        $this->assertSame('0123456789', $stale->streamedContent());
    }

    public function test_audio_preview_honors_a_matching_if_range_http_date(): void
    {
        $preview = $this->makeAudioPreview();
        $initial = $this->get(route('store.preview.audio', $preview));
        $lastModified = (string) $initial->headers->get('Last-Modified');
        $this->assertNotSame('', $lastModified);

        $response = $this->withHeaders([
            'Range' => 'bytes=2-5',
            'If-Range' => $lastModified,
        ])->get(route('store.preview.audio', $preview));

        $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 2-5/10');
        $this->assertSame('2345', $response->streamedContent());
    }
}
