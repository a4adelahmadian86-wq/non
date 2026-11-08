<?php

namespace Tests\Unit;

use App\Services\ProductDocumentAudioService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductDocumentAudioServiceTest extends TestCase
{
    public function test_audio_segments_are_joined_and_keep_source_time_offsets(): void
    {
        config([
            'services.document_audio.url' => 'https://document-audio.example.test/v1/synthesize',
            'services.document_audio.token' => 'test-token',
            'services.document_audio.voice_id' => 'farast-document-fa-male-37-v1',
        ]);

        $pcm = str_repeat("\0", 1600);
        $format = pack('vvVVvv', 1, 1, 16000, 32000, 2, 16);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('V', 16).$format.'data'.pack('V', strlen($pcm)).$pcm;
        Http::fake(['document-audio.example.test/*' => Http::response($wav, 200, ['Content-Type' => 'audio/wav'])]);

        $result = app(ProductDocumentAudioService::class)->generate([
            ['chunk_index' => 0, 'chunk_type' => 'page_text', 'locator' => ['page' => 1], 'content_text' => 'متن صفحه اول. جمله دوم.'],
            ['chunk_index' => 1, 'chunk_type' => 'page_text', 'locator' => ['page' => 2], 'content_text' => 'متن صفحه دوم'],
        ]);

        $this->assertNotNull($result);
        $this->assertSame('audio/wav', $result['mime']);
        $this->assertSame(150, $result['duration_ms']);
        $this->assertSame(3, count($result['segments']));
        $this->assertSame(0, $result['segments'][0]['start_ms']);
        $this->assertSame(50, $result['segments'][0]['end_ms']);
        $this->assertSame(50, $result['segments'][1]['start_ms']);
        $this->assertSame(['page' => 2], $result['segments'][2]['locator']);
        $this->assertSame('متن', $result['segments'][0]['words'][0]['text']);
        $this->assertGreaterThan($result['segments'][0]['words'][0]['start_ms'], $result['segments'][0]['words'][0]['end_ms']);
        $this->assertStringStartsWith('RIFF', $result['audio']);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === 'https://document-audio.example.test/v1/synthesize'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['voice_id'] === 'farast-document-fa-male-37-v1'
            && $request['language'] === 'fa-IR');
    }


    public function test_phoneme_alignment_from_wav_metadata_is_preserved_for_each_word(): void
    {
        config([
            'services.document_audio.url' => 'https://document-audio.example.test/v1/synthesize',
            'services.document_audio.token' => 'test-token',
            'services.document_audio.voice_id' => 'farast-document-fa-male-37-v1',
        ]);

        $pcm = str_repeat("\0", 1600);
        $format = pack('vvVVvv', 1, 1, 16000, 32000, 2, 16);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('V', 16).$format.'data'.pack('V', strlen($pcm)).$pcm;
        $metadata = json_encode([
            'version' => 1,
            'alignment' => 'phoneme',
            'words' => [
                ['text' => 'متن', 'start_ms' => 0, 'end_ms' => 20],
                ['text' => 'صفحه', 'start_ms' => 20, 'end_ms' => 50],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $wav .= 'FARA'.pack('V', strlen($metadata)).$metadata.(strlen($metadata) % 2 ? "\0" : '');
        $wav = substr($wav, 0, 4).pack('V', strlen($wav) - 8).substr($wav, 8);

        Http::fake(['document-audio.example.test/*' => Http::response($wav, 200, ['Content-Type' => 'audio/wav'])]);

        $result = app(ProductDocumentAudioService::class)->generate([
            ['chunk_index' => 0, 'chunk_type' => 'page_text', 'locator' => ['page' => 1], 'content_text' => 'متن صفحه'],
        ]);

        $this->assertNotNull($result);
        $this->assertSame('phoneme', $result['segments'][0]['alignment_quality']);
        $this->assertSame([
            ['text' => 'متن', 'start_ms' => 0, 'end_ms' => 20],
            ['text' => 'صفحه', 'start_ms' => 20, 'end_ms' => 50],
        ], $result['segments'][0]['words']);
    }

    public function test_transient_render_cold_start_response_is_retried_before_synthesis(): void
    {
        config([
            'services.document_audio.url' => 'https://document-audio.example.test/v1/synthesize',
            'services.document_audio.token' => 'test-token',
        ]);

        $pcm = str_repeat("\0", 1600);
        $format = pack('vvVVvv', 1, 1, 16000, 32000, 2, 16);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('V', 16).$format.'data'.pack('V', strlen($pcm)).$pcm;
        Http::fake([
            'document-audio.example.test/*' => Http::sequence()
                ->push('service is waking up', 503)
                ->push($wav, 200, ['Content-Type' => 'audio/wav']),
        ]);

        $result = app(ProductDocumentAudioService::class)->generate([
            ['chunk_index' => 0, 'chunk_type' => 'page_text', 'locator' => ['page' => 1], 'content_text' => 'متن آماده'],
        ]);

        $this->assertNotNull($result);
        Http::assertSentCount(2);
    }

    public function test_audio_job_uses_existing_default_queue_and_visibility_exceeds_job_timeout(): void
    {
        $job = new \\App\\Jobs\\GenerateProductDocumentAudio(123);

        $this->assertSame('default', $job->queue);
        $this->assertSame(900, $job->timeout);
        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
    }

    public function test_document_pipeline_classes_are_autoloadable(): void
    {
        $this->assertTrue(class_exists(\App\Jobs\ProcessProductDocument::class));
        $this->assertTrue(class_exists(\App\Jobs\GenerateProductDocumentAudio::class));
        $this->assertTrue(class_exists(\App\Services\ProductKnowledgeService::class));
        $this->assertTrue(class_exists(\App\Console\Commands\PruneProductIngestions::class));
    }

    public function test_audio_generation_requires_configured_tts_endpoint(): void
    {
        config(['services.document_audio.url' => '', 'services.document_audio.token' => '']);

        $this->assertNull(app(ProductDocumentAudioService::class)->generate([
            ['content_text' => 'متن آزمایشی'],
        ]));
    }
}
