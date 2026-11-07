<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\ProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderRegistryCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_includes_groq_with_server_side_configuration_without_claiming_live_verification(): void
    {
        SiteSetting::write('voice_groq_key', 'test-groq-secret', true);
        SiteSetting::write('assistant_groq_model', 'openai/gpt-oss-120b');

        $catalog = collect(app(ProviderRegistry::class)->catalog())->keyBy('id');

        $this->assertTrue($catalog->has('ai:groq'));
        $groq = $catalog->get('ai:groq');

        $this->assertSame('Groq', $groq['name']);
        $this->assertSame('openai/gpt-oss-120b', $groq['model']);
        $this->assertSame(['ai.text', 'speech.stt'], $groq['capabilities']);
        $this->assertSame('configured', $groq['status']);
        $this->assertTrue($groq['credential_configured']);
        $this->assertFalse($groq['connection_verified']);
    }

    public function test_catalog_does_not_report_groq_as_configured_without_a_key(): void
    {
        $catalog = collect(app(ProviderRegistry::class)->catalog())->keyBy('id');

        $this->assertTrue($catalog->has('ai:groq'));
        $this->assertSame('unavailable', $catalog->get('ai:groq')['status']);
        $this->assertFalse($catalog->get('ai:groq')['credential_configured']);
        $this->assertFalse($catalog->get('ai:groq')['connection_verified']);
    }
}
