<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PricingRule;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOverviewScopeRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_dashboard_does_not_expose_global_settings_or_pricing(): void
    {
        config(['farast_admin.multi_role_console' => true]);

        $organization = Organization::create([
            'name' => 'Scoped Org',
            'slug' => 'scoped-org',
            'status' => 'active',
        ]);

        $manager = User::create([
            'name' => 'Scoped Manager',
            'mobile' => '09123456782',
            'email' => 'scoped-manager@example.test',
            'password' => 'secret',
            'role' => User::ROLE_ORGANIZATION_MANAGER,
            'organization_id' => $organization->id,
        ]);

        SiteSetting::write('gemini_model', 'private-scope-model');
        SiteSetting::write('gemini_api_key', 'private-scope-secret', true);
        PricingRule::create([
            'key' => 'scope-private-price',
            'value' => 987654,
            'label' => 'Private global price',
            'active' => true,
        ]);

        $response = $this->actingAs($manager)->get('/admin')->assertOk();

        $response->assertViewHas('rules', fn ($rules) => $rules->isEmpty());
        $response->assertViewHas('settings', function (array $settings): bool {
            return ($settings['gemini_model'] ?? null) === ''
                && ($settings['weekly_free_pages'] ?? null) === ''
                && ($settings['max_file_mb'] ?? null) === '';
        });
        $response->assertViewHas('secretStatus', fn (array $status): bool => $status === [
            'gemini' => false,
            'files' => false,
            'groq' => false,
        ]);
        $response->assertViewHas('systemHealth', fn (array $health): bool => $health === []);
    }
}
