<?php

namespace Tests\Unit;

use App\Services\ProductDocumentAudioService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductDocumentAudioServiceSnippetTest extends TestCase
{
    public function test_selection_synthesis_uses_the_dedicated_document_voice_endpoint(): void
    {
        config([
            'services.document_audio.url' => 'https://document-audio.example/v1/synthesize',
            'services.document_audio.token' => 'test-token',
            'services.document_audio.voice_id' => 'farast-document-fa-male-37-v1',
        ]);
        $pcm = str_repeat("\0", 800);
        $format = pack('vvVVvv', 1, 1, 16000, 32000, 2, 16);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('V', 16).$format.'data'.pack('V', strlen($pcm)).$pcm;
        Http::fake(['document-audio.example/*' => Http::response($wav, 200, ['Content-Type' => 'audio/wav'])]);

        $result = app(ProductDocumentAudioService::class)->synthesizeSnippet('سلام');
        $this->assertStringStartsWith('RIFF', $result);
        Http::assertSent(fn ($request) => $request->url() === 'https://document-audio.example/v1/synthesize'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['text'] === 'سلام'
            && $request['voice_id'] === 'farast-document-fa-male-37-v1');
    }

    public function test_selection_synthesis_rejects_text_over_two_thousand_characters(): void
    {
        config([
            'services.document_audio.url' => 'https://document-audio.example/v1/synthesize',
            'services.document_audio.token' => 'test-token',
        ]);
        $this->expectException(\RuntimeException::class);
        app(ProductDocumentAudioService::class)->synthesizeSnippet(str_repeat('ا', 2001));
    }
}
