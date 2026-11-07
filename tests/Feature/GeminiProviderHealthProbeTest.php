<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiProviderHealthProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_gemini_probe_uses_server_side_key_header_and_checks_selected_model(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        SiteSetting::write('gemini_api_key', 'test-gemini-secret', true);
        SiteSetting::write('gemini_model', 'gemini-3.8-flash');

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models' => Http::response([
                'models' => [
                    ['name' => 'models/gemini-3.8-flash'],
                    ['name' => 'models/gemini-2.5-flash'],
                ],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.providers.ai.test'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('configured', true)
            ->assertJsonPath('model_available', true)
            ->assertJsonPath('model', 'gemini-3.8-flash');

        Http::assertSent(fn ($request) =>
            $request->hasHeader('x-goog-api-key', 'test-gemini-secret')
            && ! str_contains($request->url(), 'test-gemini-secret')
        );
    }

    public function test_gemini_probe_does_not_call_google_when_key_is_missing(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.providers.ai.test'))
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('configured', false);

        Http::assertNothingSent();
    }

    public function test_gemini_probe_sanitizes_provider_rate_limit_errors(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        SiteSetting::write('gemini_api_key', 'test-gemini-secret', true);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models' => Http::response([
                'error' => ['message' => 'sensitive provider response'],
            ], 429),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.providers.ai.test'))
            ->assertStatus(429)
            ->assertJsonPath('ok', false)
            ->assertJsonMissingPath('error');
    }

    public function test_integrations_page_exposes_the_gemini_test_control(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.integrations'))
            ->assertOk()
            ->assertSee('تست اتصال Gemini');
    }
    public function test_gemini_probe_uses_config_cached_credentials_when_database_key_is_absent(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        config([
            'services.gemini.key' => 'config-gemini-secret',
            'services.gemini.model' => 'gemini-3.8-flash',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models' => Http::response([
                'models' => [['name' => 'models/gemini-3.8-flash']],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.providers.ai.test'))
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'config-gemini-secret'));
    }

}
