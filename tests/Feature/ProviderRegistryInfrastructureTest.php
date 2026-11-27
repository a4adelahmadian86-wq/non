<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use App\Models\VoiceProviderAccount;
use App\Models\VoiceProviderUsage;
use App\Services\ProviderConnectionHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderRegistryInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_voice_provider_accounts_and_usage_have_a_migrated_persistent_schema(): void
    {
        $account = VoiceProviderAccount::create([
            'provider' => 'gladia',
            'name' => 'Gladia',
            'model' => 'whisper-large-v3',
            'billing_mode' => 'monthly_free',
            'enabled' => false,
            'healthy' => false,
        ]);

        $usage = VoiceProviderUsage::create([
            'voice_provider_account_id' => $account->id,
            'usage_date' => now()->toDateString(),
            'audio_seconds' => 12.5,
            'requests' => 1,
            'successful_requests' => 1,
            'failed_requests' => 0,
            'input_bytes' => 100,
            'output_bytes' => 200,
        ]);

        $this->assertDatabaseHas('voice_provider_accounts', [
            'id' => $account->id,
            'provider' => 'gladia',
            'enabled' => 0,
        ]);
        $this->assertDatabaseHas('voice_provider_usages', [
            'id' => $usage->id,
            'voice_provider_account_id' => $account->id,
            'requests' => 1,
        ]);
        $this->assertSame(12.5, (float) $usage->fresh()->audio_seconds);
    }

    public function test_farast_ai_gateway_health_state_is_supported_and_scoped(): void
    {
        $health = app(ProviderConnectionHealth::class);
        $health->recordSuccess(
            'farast-ai-gateway',
            'test-gateway-token',
            '@cf/openai/gpt-oss-20b',
            ['text' => true, 'review' => true]
        );

        $state = $health->state(
            'farast-ai-gateway',
            'test-gateway-token',
            '@cf/openai/gpt-oss-20b',
            ['text', 'review']
        );

        $this->assertTrue($state['configured']);
        $this->assertTrue($state['connection_verified']);
        $this->assertTrue($state['ready']);
        $this->assertSame('healthy', $state['status']);
        $this->assertSame('1', SiteSetting::read('provider_health.farast-ai-gateway.api_reachable'));
    }

    public function test_admin_integrations_page_renders_without_provider_health_schema_errors(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.integrations'))
            ->assertOk()
            ->assertSee('یکپارچه‌سازی و سرویس‌دهنده‌ها');
    }
}
