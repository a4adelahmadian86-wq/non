<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FarastAiGatewayHealthProbeTest extends TestCase
{
    use RefreshDatabase;

    private function configureGateway(User $admin): void
    {
        SiteSetting::write('ai_gateway_url', 'https://gateway.example.test');
        SiteSetting::write('ai_gateway_token', 'test-gateway-secret', true);
    }

    private function readyPayload(): array
    {
        return [
            'ok' => true,
            'provider' => 'cloudflare-workers-ai',
            'models' => [
                'text' => '@cf/openai/gpt-oss-20b',
                'review' => '@cf/openai/gpt-oss-120b',
                'vision' => '@cf/moondream/moondream3.1-9B-A2B',
                'audio' => '@cf/openai/whisper-large-v3-turbo',
            ],
        ];
    }

    public function test_gateway_probe_uses_server_side_token_and_persists_verified_model_state(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->configureGateway($admin);

        Http::fake([
            'https://gateway.example.test/ready' => Http::response($this->readyPayload(), 200),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.providers.ai.test'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('configured', true)
            ->assertJsonPath('api_reachable', true)
            ->assertJsonPath('connection_verified', true)
            ->assertJsonPath('ready', true)
            ->assertJsonPath('models.review', '@cf/openai/gpt-oss-120b');

        Http::assertSent(fn ($request) =>
            $request->hasHeader('Authorization', 'Bearer test-gateway-secret')
            && ! str_contains($request->url(), 'test-gateway-secret')
        );

        $this->get(route('admin.integrations'))
            ->assertOk()
            ->assertViewHas('providers', function ($providers): bool {
                $gateway = collect($providers)->firstWhere('provider', 'farast-ai-gateway');

                return $gateway !== null
                    && $gateway['status'] === 'healthy'
                    && $gateway['connection_verified'] === true
                    && $gateway['ready'] === true;
            });
    }

    public function test_gateway_probe_does_not_send_requests_when_configuration_is_missing(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.providers.ai.test'))
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('configured', false);

        Http::assertNothingSent();
    }

    public function test_gateway_probe_sanitizes_rate_limit_errors_and_marks_status_degraded(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->configureGateway($admin);

        Http::fake([
            'https://gateway.example.test/ready' => Http::response([
                'error' => ['message' => 'sensitive upstream response'],
            ], 429),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.providers.ai.test'))
            ->assertStatus(429)
            ->assertJsonPath('ok', false)
            ->assertJsonMissingPath('error');

        $this->get(route('admin.integrations'))
            ->assertOk()
            ->assertViewHas('providers', function ($providers): bool {
                $gateway = collect($providers)->firstWhere('provider', 'farast-ai-gateway');

                return $gateway !== null
                    && $gateway['status'] === 'degraded'
                    && $gateway['connection_verified'] === false;
            });
    }

    public function test_gateway_probe_uses_cached_environment_credentials_when_database_settings_are_absent(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        config([
            'services.ai_gateway.url' => 'https://gateway.example.test',
            'services.ai_gateway.token' => 'config-gateway-secret',
        ]);

        Http::fake([
            'https://gateway.example.test/ready' => Http::response($this->readyPayload(), 200),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.providers.ai.test'))
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer config-gateway-secret'));
    }

    public function test_integrations_page_exposes_the_farast_gateway_test_control(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.integrations'))
            ->assertOk()
            ->assertSee('تست اتصال درگاه FARAST');
    }
}
