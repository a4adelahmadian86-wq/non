<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\DashboardNavigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardNavigationArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['farast_admin.multi_role_console' => true]);
        app(AuthorizationService::class)->syncDefaults();
    }

    public function test_navigation_uses_eight_coherent_groups_and_preserves_every_legacy_item_key(): void
    {
        $navigation = config('dashboard.navigation');

        $this->assertSame([
            'workspace',
            'documents',
            'automation',
            'collaboration',
            'support_desk',
            'reports',
            'finance',
            'settings',
        ], array_column($navigation, 'key'));

        $actualKeys = [];
        foreach ($navigation as $group) {
            $this->assertNotEmpty($group['items']);
            foreach ($group['items'] as $item) {
                $actualKeys[] = $item['key'];
            }
        }

        $this->assertCount(count($actualKeys), array_unique($actualKeys));
        $this->assertEqualsCanonicalizing([
            'workspace.overview',
            'workspace.recent',
            'workspace.tasks',
            'workspace.action',
            'workspace.shortcuts',
            'workspace.billing',
            'workspace.status',
            'support.my',
            'support.new',
            'support.manage',
            'support.report',
            'support.sms',
            'support.announcements',
            'documents.all',
            'documents.mine',
            'documents.recent',
            'documents.files',
            'documents.folders',
            'documents.shared',
            'documents.deleted',
            'documents.archive',
            'editor.start',
            'editor.recent',
            'editor.templates',
            'editor.drafts',
            'editor.favorites',
            'automation.workflows',
            'automation.processes',
            'automation.rules',
            'automation.jobs',
            'automation.history',
            'ai.assistant',
            'ai.history',
            'ai.models',
            'ai.usage',
            'collaboration.teams',
            'collaboration.members',
            'collaboration.roles',
            'collaboration.invites',
            'reports.overview',
            'reports.usage',
            'reports.finance',
            'reports.exports',
            'notifications.inbox',
            'notifications.alerts',
            'notifications.manage',
            'notifications.support',
            'access.users',
            'access.roles',
            'access.sessions',
            'organization.profile',
            'organization.billing',
            'organization.plans',
            'security.audit',
            'security.logs',
            'security.alerts',
            'settings.account',
            'settings.preferences',
            'settings.finance',
            'settings.email',
            'settings.integrations',
            'settings.providers',
            'settings.cms',
        ], $actualKeys);
    }

    public function test_each_live_module_is_placed_in_its_matching_workspace_domain(): void
    {
        $groups = collect(config('dashboard.navigation'))->keyBy('key');

        $keysFor = static fn (string $groupKey): array => array_column($groups[$groupKey]['items'], 'key');

        $this->assertContains('workspace.overview', $keysFor('workspace'));
        $this->assertNotContains('workspace.billing', $keysFor('workspace'));
        $this->assertContains('workspace.billing', $keysFor('finance'));

        $this->assertContains('documents.mine', $keysFor('documents'));
        $this->assertContains('editor.start', $keysFor('documents'));

        $this->assertContains('ai.models', $keysFor('automation'));
        $this->assertContains('automation.workflows', $keysFor('automation'));

        $this->assertContains('collaboration.teams', $keysFor('collaboration'));
        $this->assertContains('access.users', $keysFor('collaboration'));
        $this->assertContains('organization.profile', $keysFor('collaboration'));

        $this->assertContains('support.manage', $keysFor('support_desk'));
        $this->assertContains('notifications.inbox', $keysFor('support_desk'));

        $this->assertContains('reports.overview', $keysFor('reports'));
        $this->assertContains('security.audit', $keysFor('reports'));

        $this->assertContains('reports.finance', $keysFor('finance'));
        $this->assertContains('organization.plans', $keysFor('finance'));
        $this->assertContains('settings.finance', $keysFor('finance'));

        $this->assertContains('settings.integrations', $keysFor('settings'));
        $this->assertContains('settings.cms', $keysFor('settings'));
    }

    public function test_navigation_service_hides_planned_and_admin_only_items_for_regular_users(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $groups = app(DashboardNavigationService::class)->forUser($employee);
        $items = collect($groups)->flatMap(fn (array $group) => $group['items']);
        $keys = $items->pluck('key')->all();

        $this->assertNotEmpty($groups);
        $this->assertContains('workspace.overview', $keys);
        $this->assertContains('documents.mine', $keys);
        $this->assertContains('editor.start', $keys);

        foreach ([
            'workspace.tasks',
            'workspace.action',
            'support.manage',
            'support.report',
            'support.sms',
            'notifications.manage',
            'ai.models',
            'access.users',
            'access.roles',
            'organization.profile',
            'organization.plans',
            'reports.finance',
            'security.audit',
            'settings.email',
            'settings.integrations',
            'settings.providers',
            'settings.cms',
        ] as $restrictedOrPlannedKey) {
            $this->assertNotContains($restrictedOrPlannedKey, $keys);
        }

        foreach ($groups as $group) {
            $this->assertNotEmpty($group['items']);
            foreach ($group['items'] as $item) {
                $this->assertNotEmpty($item['href']);
                $this->assertFalse($item['planned']);
            }
        }
    }

    public function test_admin_receives_admin_sections_inside_their_matching_domains(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $groups = app(DashboardNavigationService::class)->forUser($admin);
        $items = collect($groups)->flatMap(fn (array $group) => $group['items']);
        $keys = $items->pluck('key')->all();

        $this->assertContains('support.manage', $keys);
        $this->assertContains('access.users', $keys);
        $this->assertContains('reports.finance', $keys);
        $this->assertContains('organization.profile', $keys);
        $this->assertContains('settings.email', $keys);
    }

    public function test_guest_navigation_is_empty(): void
    {
        $this->assertSame([], app(DashboardNavigationService::class)->forUser(null));
    }
}
