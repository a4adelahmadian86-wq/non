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

    public function test_dashboard_counts_recent_errors_from_a_bounded_json_log_tail(): void
    {
        $directory = storage_path('framework/testing/observability-logs');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $basePath = $directory.'/farast.log';
        $dailyPath = $directory.'/farast-'.now()->format('Y-m-d').'.log';
        file_put_contents($dailyPath, implode("\n", [
            json_encode(['datetime' => now()->subMinutes(2)->toIso8601String(), 'level' => 400, 'level_name' => 'ERROR', 'message' => 'safe error']),
            json_encode(['datetime' => now()->subMinutes(1)->toIso8601String(), 'level' => 200, 'level_name' => 'INFO', 'message' => 'normal request']),
            json_encode(['datetime' => now()->subMinutes(20)->toIso8601String(), 'level' => 500, 'level_name' => 'CRITICAL', 'message' => 'old error']),
        ])."\n");

        config([
            'logging.default' => 'daily',
            'logging.channels.daily.path' => $basePath,
            'logging.channels.daily.days' => 14,
            'services.product_supervisor.url' => '',
            'services.product_supervisor.token' => '',
            'services.document_audio.url' => '',
            'services.document_audio.token' => '',
        ]);
        Http::fake();

        $snapshot = app(FarastObservabilityService::class)->snapshot();

        $this->assertSame(1, $snapshot['components']['logging']['daily_error_count_last_15_minutes']);
        $this->assertTrue($snapshot['components']['logging']['error_count_complete']);

        @unlink($dailyPath);
        @rmdir($directory);
    }
}
