<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceProviderAccount;
use App\Services\ProviderRegistry;
use App\Services\VoiceQuotaManager;
use App\Services\VoiceProviderRouter;
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
    public function test_provider_registry_does_not_call_configured_credentials_a_verified_connection(): void
    {
        $account = new VoiceProviderAccount();
        $account->provider = 'gladia';
        $account->name = 'Gladia';
        $account->credentials_array = ['api_key' => 'configured-secret'];
        $account->enabled = true;
        $account->healthy = true;
        $account->save();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)
            ->get(route('admin.providers.voice.test', 'gladia'))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('connection_verified', false);

        $provider = collect(app(ProviderRegistry::class)->catalog())->firstWhere('provider', 'gladia');

        $this->assertSame('unverified', $provider['status']);
        $this->assertFalse($provider['connection_verified']);

        $account->update(['last_success_at' => now()]);
        $provider = collect(app(ProviderRegistry::class)->catalog())->firstWhere('provider', 'gladia');

        $this->assertSame('healthy', $provider['status']);
        $this->assertTrue($provider['connection_verified']);
    }

    public function test_voice_router_skips_enabled_accounts_with_incomplete_credentials(): void
    {
        config(['services.voice.local_only' => false]);

        $account = new VoiceProviderAccount();
        $account->provider = 'azure';
        $account->name = 'Azure Speech';
        $account->credentials_array = ['subscription_key' => 'partial-secret'];
        $account->enabled = true;
        $account->healthy = true;
        $account->save();

        $this->assertFalse($account->fresh()->credentialsConfigured());
        $this->assertNull(app(VoiceProviderRouter::class)->best('fa-IR'));
    }

    public function test_paid_provider_with_a_monthly_cap_resets_usage_at_period_boundary(): void
    {
        $account = new VoiceProviderAccount();
        $account->provider = 'gladia';
        $account->billing_mode = 'paid';
        $account->quota_limit_seconds = 36000;
        $account->quota_used_seconds = 36000;
        $account->quota_period_started_at = now()->subMonth();
        $account->quota_period_ends_at = now()->subSecond();
        $account->enabled = true;
        $account->healthy = true;
        $account->save();

        $available = app(VoiceQuotaManager::class)->availableSeconds($account);

        $this->assertSame(36000, $available);
        $this->assertSame(0.0, (float) $account->fresh()->quota_used_seconds);
        $this->assertTrue($account->fresh()->quota_period_ends_at->isFuture());
    }

    public function test_rotating_provider_credentials_invalidates_previous_connection_verification(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $account = new VoiceProviderAccount();
        $account->provider = 'gladia';
        $account->name = 'Gladia';
        $account->credentials_array = ['api_key' => 'old-secret'];
        $account->enabled = true;
        $account->healthy = true;
        $account->last_success_at = now();
        $account->save();

        $this->actingAs($admin)
            ->from(route('admin.index'))
            ->post(route('admin.voice.providers.update'), [
                'gladia_api_key' => 'new-secret',
                'gladia_enabled' => '1',
            ])
            ->assertRedirect(route('admin.index'));

        $account->refresh();

        $this->assertSame('new-secret', $account->credentials_array['api_key']);
        $this->assertNull($account->last_success_at);
        $this->assertSame('unverified', collect(app(ProviderRegistry::class)->catalog())->firstWhere('provider', 'gladia')['status']);
    }

}
