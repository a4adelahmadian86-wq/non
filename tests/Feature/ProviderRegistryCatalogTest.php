<?php

namespace Tests\\Feature;

use App\\Models\\SiteSetting;
use App\\Services\\ProviderRegistry;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class ProviderRegistryCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_exposes_one_farast_gateway_without_claiming_live_verification(): void
    {
        SiteSetting::write('ai_gateway_url', 'https://gateway.example.test');
        SiteSetting::write('ai_gateway_token', 'test-gateway-secret', true);

        $catalog = collect(app(ProviderRegistry::class)->catalog())->keyBy('id');

        $this->assertTrue($catalog->has('ai:farast-ai-gateway'));
        $gateway = $catalog->get('ai:farast-ai-gateway');

        $this->assertSame('FARAST AI Gateway', $gateway['name']);
        $this->assertSame('@cf/openai/gpt-oss-20b', $gateway['model']);
        $this->assertSame(
            ['ai.text', 'ai.review', 'ai.vision', 'ai.audio', 'ai.structured_output'],
            $gateway['capabilities']
        );
        $this->assertSame('configured', $gateway['status']);
        $this->assertTrue($gateway['credential_configured']);
        $this->assertFalse($gateway['connection_verified']);
        $this->assertFalse($catalog->has('ai:groq'));
        $this->assertFalse($catalog->has('ai:gemini'));
    }

    public function test_catalog_does_not_report_the_gateway_as_configured_without_url_and_token(): void
    {
        $catalog = collect(app(ProviderRegistry::class)->catalog())->keyBy('id');

        $this->assertTrue($catalog->has('ai:farast-ai-gateway'));
        $this->assertSame('unavailable', $catalog->get('ai:farast-ai-gateway')['status']);
        $this->assertFalse($catalog->get('ai:farast-ai-gateway')['credential_configured']);
        $this->assertFalse($catalog->get('ai:farast-ai-gateway')['connection_verified']);
    }
}
