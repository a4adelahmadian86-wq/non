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
                'chat_model_available' => true,
                'speech_model_available' => true,
            ]);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return $request->url() === 'https://api.groq.com/openai/v1/models'
                && $request->hasHeader('Authorization', 'Bearer config-only-groq-secret');
        });
    }
}
