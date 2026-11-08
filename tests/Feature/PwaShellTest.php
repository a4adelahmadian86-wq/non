<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_exposes_the_installable_app_shell(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('rel="manifest" href="/manifest.webmanifest"', false)
            ->assertSee('id="farastInstallApp"', false)
            ->assertSee('/js/farast-pwa.js', false);
    }

    public function test_manifest_has_standalone_display_and_required_icon_sizes(): void
    {
        $manifest = json_decode(
            file_get_contents(public_path('manifest.webmanifest')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);

        $iconsBySize = [];
        foreach ($manifest['icons'] as $icon) {
            $iconsBySize[$icon['sizes']] = $icon;
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }

        $this->assertArrayHasKey('192x192', $iconsBySize);
        $this->assertArrayHasKey('512x512', $iconsBySize);
    }

    public function test_service_worker_limits_persistent_caching_to_public_assets(): void
    {
        $worker = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("request.mode === 'navigate'", $worker);
        $this->assertStringContainsString('const isPublicAsset', $worker);
        $this->assertStringContainsString('if (!isPublicAsset) return;', $worker);
        $this->assertStringContainsString("LEGACY_CACHES = ['farast-shell-v1']", $worker);
    }
}
