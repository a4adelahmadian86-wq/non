<?php

namespace Tests\Feature;

use App\Services\ProductInfrastructureHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductInfrastructureHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_readiness_probes_report_live_provider_and_audio_model_state_without_returning_secrets(): void
    {
        config([
            'services.product_supervisor.url' => 'https://supervisor.example.test',
            'services.product_supervisor.token' => 'supervisor-secret',
            'services.document_audio.url' => 'https://audio.example.test',
            'services.document_audio.token' => 'audio-secret',
        ]);

        Http::fake([
            'https://supervisor.example.test/v1/ready' => Http::response([
                'status' => 'ok',
                'providers' => [
                    'gemini' => ['ready_for_product_review' => true],
                    'groq' => ['ready_for_file_review' => true, 'privacy_gate_confirmed' => true],
                ],
            ], 200),
            'https://audio.example.test/v1/ready' => Http::response([
                'status' => 'ready',
                'model_files_present' => true,
                'word_alignment' => true,
            ], 200),
        ]);

        $health = app(ProductInfrastructureHealthService::class)->snapshot();

        $this->assertSame('healthy', $health['supervisor']['status']);
        $this->assertTrue($health['supervisor']['file_review_ready']);
        $this->assertTrue($health['supervisor']['privacy_gate_confirmed']);
        $this->assertSame('healthy', $health['document_audio']['status']);
        $this->assertTrue($health['document_audio']['model_ready']);
        $this->assertArrayNotHasKey('token', $health['supervisor']);
        $this->assertArrayNotHasKey('token', $health['document_audio']);

        Http::assertSent(fn ($request) => $request->url() === 'https://supervisor.example.test/v1/ready'
            && $request->hasHeader('Authorization', 'Bearer supervisor-secret'));
        Http::assertSent(fn ($request) => $request->url() === 'https://audio.example.test/v1/ready'
            && $request->hasHeader('Authorization', 'Bearer audio-secret'));
    }

    public function test_unconfigured_services_are_reported_without_network_requests(): void
    {
        config([
            'services.product_supervisor.url' => '',
            'services.product_supervisor.token' => '',
            'services.document_audio.url' => '',
            'services.document_audio.token' => '',
        ]);
        Http::fake();

        $health = app(ProductInfrastructureHealthService::class)->snapshot();

        $this->assertSame('not_configured', $health['supervisor']['status']);
        $this->assertSame('not_configured', $health['document_audio']['status']);
        Http::assertNothingSent();
    }

    public function test_service_readiness_degrades_when_required_provider_or_audio_alignment_is_missing(): void
    {
        config([
            'services.product_supervisor.url' => 'https://supervisor.example.test',
            'services.product_supervisor.token' => 'supervisor-secret',
            'services.document_audio.url' => 'https://audio.example.test',
            'services.document_audio.token' => 'audio-secret',
        ]);
        Http::fake([
            'https://supervisor.example.test/v1/ready' => Http::response([
                'status' => 'ok',
                'providers' => [
                    'gemini' => ['ready_for_product_review' => true],
                    'groq' => ['ready_for_file_review' => false, 'privacy_gate_confirmed' => false],
                ],
            ], 200),
            'https://audio.example.test/v1/ready' => Http::response([
                'status' => 'ready',
                'model_files_present' => true,
                'word_alignment' => false,
            ], 200),
        ]);

        $health = app(ProductInfrastructureHealthService::class)->snapshot();

        $this->assertSame('degraded', $health['supervisor']['status']);
        $this->assertFalse($health['supervisor']['file_review_ready']);
        $this->assertSame('degraded', $health['document_audio']['status']);
        $this->assertFalse($health['document_audio']['word_alignment_ready']);
    }
}
