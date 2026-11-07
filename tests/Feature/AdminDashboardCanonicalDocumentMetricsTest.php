<?php

namespace Tests\Feature;

use App\Models\FarastDocument;
use App\Models\TypingDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardCanonicalDocumentMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_metrics_and_charts_count_canonical_documents_without_legacy_duplicates(): void
    {
        $admin = $this->makeAdmin();
        $customer = User::factory()->create();

        $linked = FarastDocument::create([
            'user_id' => $customer->id,
            'title' => 'Canonical linked document',
            'content' => '',
            'status' => 'active',
            'is_favorite' => false,
        ]);

        TypingDocument::create([
            'user_id' => $customer->id,
            'farast_document_id' => $linked->id,
            'title' => 'Legacy editor adapter',
            'content' => '',
            'page_count' => 1,
            'word_count' => 0,
            'status' => 'draft',
            'price_rials' => 0,
        ]);

        FarastDocument::create([
            'user_id' => $customer->id,
            'title' => 'Workspace-only canonical document',
            'content' => '',
            'status' => 'active',
            'is_favorite' => false,
        ]);

        TypingDocument::create([
            'user_id' => $customer->id,
            'title' => 'Legacy-only document',
            'content' => '',
            'page_count' => 1,
            'word_count' => 0,
            'status' => 'draft',
            'price_rials' => 0,
        ]);

        $trashed = FarastDocument::create([
            'user_id' => $customer->id,
            'title' => 'Trashed canonical document',
            'content' => '',
            'status' => 'trashed',
            'trashed_at' => now(),
            'is_favorite' => false,
        ]);

        TypingDocument::create([
            'user_id' => $customer->id,
            'farast_document_id' => $trashed->id,
            'title' => 'Trashed editor adapter',
            'content' => '',
            'page_count' => 1,
            'word_count' => 0,
            'status' => 'draft',
            'price_rials' => 0,
        ]);

        $response = $this->actingAs($admin)->get('/admin')->assertOk();

        $response->assertViewHas('stats', fn (array $stats) =>
            $stats['documents'] === 3 && $stats['new_documents_week'] === 3
        );
        $response->assertViewHas('charts', fn (array $charts) =>
            array_sum($charts['documents']) === 3
        );
        $response->assertViewHas('topConsumers', function ($consumers) use ($customer) {
            $consumer = $consumers->firstWhere('id', $customer->id);

            return $consumer !== null && (int) $consumer->documents === 3;
        });
    }

    public function test_admin_dashboard_and_provider_registry_use_cached_environment_credentials_without_claiming_health(): void
    {
        config([
            'services.gemini.key' => 'configured-gemini-key',
            'services.files.key' => 'configured-files-key',
            'services.groq.key' => 'configured-groq-key',
        ]);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertViewHas('secretStatus', fn (array $status) =>
                $status['gemini'] === true && $status['files'] === true && $status['groq'] === true
            );

        $this->get('/admin/integrations')
            ->assertOk()
            ->assertViewHas('providers', function ($providers) {
                $gemini = collect($providers)->firstWhere('provider', 'gemini');

                return $gemini !== null
                    && $gemini['status'] === 'configured'
                    && $gemini['credential_configured'] === true
                    && $gemini['connection_verified'] === false;
            })
            ->assertSee('کلید ثبت شده · اتصال تأییدنشده');
    }

    private function makeAdmin(): User
    {
        return User::create([
            'name' => 'Dashboard Admin',
            'email' => 'dashboard-admin-'.uniqid().'@example.test',
            'mobile' => '09'.random_int(100000000, 999999999),
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);
    }
}
