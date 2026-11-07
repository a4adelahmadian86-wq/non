<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceProviderAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceProviderDashboardIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_voice_form_persists_encrypted_credentials_and_provider_state(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)
            ->from(route('admin.index'))
            ->post(route('admin.voice.providers.update'), [
                'gladia_api_key' => 'gladia-secret-value',
                'gladia_enabled' => '1',
                'gladia_quota_limit_seconds' => 36000,
                'azure_subscription_key' => 'azure-secret-value',
                'azure_region' => 'eastus',
                'azure_enabled' => '1',
                'azure_quota_limit_seconds' => 72000,
                'google_project_id' => 'farast-test-project',
                'google_client_email' => 'speech-service@example.test',
                'google_private_key' => "-----BEGIN PRIVATE KEY-----\nTEST-KEY\n-----END PRIVATE KEY-----",
                'google_region' => 'us',
                'google_enabled' => '1',
                'google_quota_limit_seconds' => 90000,
            ]);

        $response->assertRedirect(route('admin.index'));

        $gladia = VoiceProviderAccount::where('provider', 'gladia')->firstOrFail();
        $this->assertSame('gladia-secret-value', $gladia->credentials_array['api_key']);
        $this->assertNotSame('gladia-secret-value', $gladia->getRawOriginal('credentials'));
        $this->assertTrue($gladia->enabled);
        $this->assertSame(36000, (int) $gladia->quota_limit_seconds);

        $azure = VoiceProviderAccount::where('provider', 'azure')->firstOrFail();
        $this->assertSame('azure-secret-value', $azure->credentials_array['subscription_key']);
        $this->assertSame('eastus', $azure->credentials_array['region']);
        $this->assertSame('eastus', $azure->metadata['region']);

        $google = VoiceProviderAccount::where('provider', 'google')->firstOrFail();
        $this->assertSame('farast-test-project', $google->credentials_array['project_id']);
        $this->assertSame('speech-service@example.test', $google->credentials_array['client_email']);
        $this->assertStringContainsString('TEST-KEY', $google->credentials_array['private_key']);
        $this->assertNotSame($google->credentials_array['private_key'], $google->getRawOriginal('credentials'));
    }

    public function test_dashboard_cannot_enable_a_voice_provider_without_required_credentials(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)
            ->from(route('admin.index'))
            ->post(route('admin.voice.providers.update'), [
                'gladia_enabled' => '1',
                'azure_enabled' => '0',
                'google_enabled' => '0',
            ]);

        $response->assertRedirect(route('admin.index'))
            ->assertSessionHasErrors('gladia_enabled');

        $this->assertDatabaseMissing('voice_provider_accounts', ['provider' => 'gladia']);
    }
}
