<?php

namespace Tests\Feature;

use App\Services\ProductSupervisorClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ProductSupervisorFailoverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.product_supervisor.url' => 'https://online-primary.example',
            'services.product_supervisor.token' => 'primary-secret',
            'services.product_supervisor.fallback_url' => 'https://domestic-node.example',
            'services.product_supervisor.fallback_token' => 'domestic-secret',
            'services.product_supervisor.timeout' => 40,
            'services.product_supervisor.file_timeout' => 540,
        ]);
    }

    public function test_local_only_mode_rejects_public_supervisor_endpoints(): void
    {
        config([
            'services.voice.local_only' => true,
            'services.product_supervisor.url' => 'https://online-primary.example',
            'services.product_supervisor.token' => 'online-primary-token',
            'services.product_supervisor.fallback_url' => 'https://online-fallback.example',
            'services.product_supervisor.fallback_token' => 'online-fallback-token',
        ]);

        Http::fake();

        try {
            app(ProductSupervisorClient::class)->review(['product_id' => '42']);
            $this->fail('Expected public endpoints to be rejected in local-only mode.');
        } catch (RuntimeException $exception) {
            $this->assertSame('product_supervisor_not_configured', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_product_review_falls_back_to_domestic_node_after_primary_server_error(): void
    {
        Http::fake([
            'https://online-primary.example/*' => Http::response(['error' => 'temporarily unavailable'], 503),
            'https://domestic-node.example/*' => Http::response([
                'review_id' => 'fallback-review',
                'product_id' => '42',
                'verdict' => 'needs_changes',
                'score' => 70,
                'findings' => [],
            ]),
        ]);

        $result = app(ProductSupervisorClient::class)->review(['product_id' => '42']);

        $this->assertSame('fallback-review', $result['review_id']);
        $this->assertFalse($result['automated_publication']);

        Http::assertSent(fn (Request $request) =>
            $request->url() === 'https://online-primary.example/v1/reviews/products'
            && $request->hasHeader('Authorization', 'Bearer primary-secret')
        );
        Http::assertSent(fn (Request $request) =>
            $request->url() === 'https://domestic-node.example/v1/reviews/products'
            && $request->hasHeader('Authorization', 'Bearer domestic-secret')
        );
    }

    public function test_file_review_rewinds_stream_and_falls_back_after_primary_server_error(): void
    {
        Http::fake([
            'https://online-primary.example/*' => Http::response(['error' => 'temporarily unavailable'], 503),
            'https://domestic-node.example/*' => Http::response([
                'review_id' => 'fallback-file-review',
                'product_id' => '42',
                'inspection' => ['detected_type' => 'pdf'],
                'security_gate' => 'manual_scan_required',
            ]),
        ]);

        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'complete-document-payload');
        rewind($stream);

        try {
            $result = app(ProductSupervisorClient::class)->reviewFile(
                $stream,
                'document.pdf',
                ['product_id' => '42']
            );
        } finally {
            fclose($stream);
        }

        $this->assertSame('fallback-file-review', $result['review_id']);
        $this->assertFalse($result['publication_authorized']);

        Http::assertSent(fn (Request $request) =>
            $request->url() === 'https://domestic-node.example/v1/reviews/files'
            && $request->hasHeader('Authorization', 'Bearer domestic-secret')
        );
    }
}
