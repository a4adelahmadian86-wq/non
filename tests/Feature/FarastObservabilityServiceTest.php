<?php

namespace Tests\Feature;

use App\Services\FarastObservabilityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FarastObservabilityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_dashboard_reuses_existing_product_and_audio_readiness_probes_without_network_when_unconfigured(): void
    {
        config([
            'services.product_supervisor.url' => '',
            'services.product_supervisor.token' => '',
            'services.document_audio.url' => '',
            'services.document_audio.token' => '',
        ]);
        Http::fake();

        $snapshot = app(FarastObservabilityService::class)->snapshot();

        $this->assertArrayHasKey('product_services', $snapshot['components']);
        $this->assertSame('warning', $snapshot['components']['product_services']['status']);
        $this->assertSame('not_configured', $snapshot['components']['product_services']['services']['supervisor']['status']);
        $this->assertSame('not_configured', $snapshot['components']['product_services']['services']['document_audio']['status']);
        Http::assertNothingSent();
    }
}
