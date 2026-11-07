<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderConnectionConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_groq_connection_test_uses_config_cached_credentials(): void
    {
        config(['services.groq.key' => 'config-only-groq-secret']);

        Http::fake([
            'https://api.groq.com/openai/v1/models' => Http::response([
                'data' => [
                    ['id' => 'openai/gpt-oss-120b'],
                    ['id' => 'whisper-large-v3'],
                ],
            ], 200),
        ]);

        $admin = User::create([
            'name' => 'Provider Admin',
            'email' => 'provider-admin@example.test',
            'mobile' => '09123456788',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->actingAs($admin)
            ->get('/admin/providers/groq/test')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'api_reachable' => true,
                'connection_verified' => true,
                'ready' => true,
                'chat_model_available' => true,
                'speech_model_available' => true,
            ]);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return $request->url() === 'https://api.groq.com/openai/v1/models'
                && $request->hasHeader('Authorization', 'Bearer config-only-groq-secret');
        });
    }
    public function test_groq_probe_persists_verified_status_and_key_rotation_invalidates_it(): void
    {
        config(['services.groq.key' => 'config-only-groq-secret']);

        Http::fake([
            'https://api.groq.com/openai/v1/models' => Http::response([
                'data' => [
                    ['id' => 'openai/gpt-oss-120b'],
                    ['id' => 'whisper-large-v3'],
                ],
            ], 200),
        ]);

        $admin = User::create([
            'name' => 'Provider Health Admin',
            'email' => 'provider-health-admin@example.test',
            'mobile' => '09123456789',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->actingAs($admin)
            ->get('/admin/providers/groq/test')
            ->assertOk()
            ->assertJsonPath('ready', true);

        $this->get('/admin/integrations')
            ->assertOk()
            ->assertViewHas('providers', function ($providers): bool {
                $groq = collect($providers)->firstWhere('provider', 'groq');

                return $groq !== null
                    && $groq['status'] === 'healthy'
                    && $groq['connection_verified'] === true
                    && $groq['ready'] === true;
            });

        config(['services.groq.key' => 'rotated-groq-secret']);

        $this->get('/admin/integrations')
            ->assertOk()
            ->assertViewHas('providers', function ($providers): bool {
                $groq = collect($providers)->firstWhere('provider', 'groq');

                return $groq !== null
                    && $groq['status'] === 'configured'
                    && $groq['connection_verified'] === false
                    && $groq['ready'] === false;
            });
    }

    public function test_groq_api_reachability_is_separate_from_model_readiness(): void
    {
        config(['services.groq.key' => 'config-only-groq-secret']);

        Http::fake([
            'https://api.groq.com/openai/v1/models' => Http::response([
                'data' => [
                    ['id' => 'whisper-large-v3'],
                ],
            ], 200),
        ]);

        $admin = User::create([
            'name' => 'Provider Partial Admin',
            'email' => 'provider-partial-admin@example.test',
            'mobile' => '09123456780',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->actingAs($admin)
            ->get('/admin/providers/groq/test')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('api_reachable', true)
            ->assertJsonPath('connection_verified', true)
            ->assertJsonPath('ready', false)
            ->assertJsonPath('chat_model_available', false)
            ->assertJsonPath('speech_model_available', true);

        $this->get('/admin/integrations')
            ->assertOk()
            ->assertViewHas('providers', function ($providers): bool {
                $groq = collect($providers)->firstWhere('provider', 'groq');

                return $groq !== null
                    && $groq['status'] === 'degraded'
                    && $groq['connection_verified'] === true
                    && $groq['ready'] === false;
            });
    }

}
