<?php

namespace Tests\Feature;

use App\Models\FarastDocument;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\DashboardNavigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceDocumentManagementIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(AuthorizationService::class)->syncDefaults();
    }

    public function test_observer_can_read_documents_but_cannot_create_edit_share_or_delete(): void
    {
        $observer = $this->makeUser(User::ROLE_OBSERVER);
        $document = $this->makeDocument($observer, 'Read-only document');

        $this->actingAs($observer)
            ->get(route('platform.documents'))
            ->assertOk()
            ->assertSee('Read-only document')
            ->assertDontSee('عنوان سند جدید')
            ->assertDontSee('ساخت لینک اشتراک')
            ->assertDontSee('سطل زباله');

        $this->post(route('platform.documents.create'), ['title' => 'Forbidden create'])->assertForbidden();
        $this->post(route('platform.documents.update', $document->id), ['title' => 'Forbidden edit', 'content' => 'x'])->assertForbidden();
        $this->post(route('platform.documents.share', $document->id), ['permission' => 'view'])->assertForbidden();
        $this->post(route('platform.documents.trash', $document->id))->assertForbidden();
        $this->get(route('platform.documents', ['shared' => 1]))->assertForbidden();
    }

    public function test_folder_ids_are_always_scoped_to_the_authenticated_owner(): void
    {
        $owner = $this->makeUser(User::ROLE_EMPLOYEE);
        $other = $this->makeUser(User::ROLE_EMPLOYEE);

        $foreignFolderId = DB::table('document_folders')->insertGetId([
            'user_id' => $other->id,
            'name' => 'Foreign folder',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)
            ->post(route('platform.documents.create'), ['title' => 'Must not be created', 'folder_id' => $foreignFolderId])
            ->assertNotFound();

        $document = $this->makeDocument($owner, 'Owned document');

        $this->post(route('platform.documents.update', $document->id), [
            'title' => 'Attempt to move',
            'content' => 'updated',
            'folder_id' => $foreignFolderId,
        ])->assertNotFound();

        $this->post(route('platform.folders.create'), [
            'name' => 'Foreign child folder',
            'parent_id' => $foreignFolderId,
        ])->assertNotFound();

        $this->assertDatabaseMissing('farast_documents', ['user_id' => $owner->id, 'title' => 'Must not be created']);
        $this->assertDatabaseMissing('document_folders', ['user_id' => $owner->id, 'name' => 'Foreign child folder']);
        $this->assertDatabaseHas('farast_documents', ['id' => $document->id, 'title' => 'Owned document']);
    }

    public function test_shared_links_are_real_revocable_and_invalidated_while_document_is_trashed(): void
    {
        $owner = $this->makeUser(User::ROLE_EMPLOYEE);
        $document = $this->makeDocument($owner, 'Shareable document');

        $response = $this->actingAs($owner)
            ->postJson(route('platform.documents.share', $document->id), ['permission' => 'view'])
            ->assertOk()
            ->assertJsonPath('permission', 'view');

        $url = $response->json('url');
        $token = Str::afterLast(parse_url($url, PHP_URL_PATH), '/');
        $shareId = DB::table('farast_document_shares')->where('token', $token)->value('id');

        $this->get('/shared/documents/'.$token)->assertOk()->assertSee('Shareable document');
        $this->get(route('platform.documents', ['shared' => 1]))->assertOk()->assertSee($url);

        $this->post(route('platform.documents.trash', $document->id))->assertRedirect();
        $this->get('/shared/documents/'.$token)->assertNotFound();

        $this->get(route('platform.documents', ['shared' => 1]))
            ->assertOk()
            ->assertSee('سند غیرفعال');

        $this->post(route('platform.documents.restore', $document->id))->assertRedirect();
        $this->get('/shared/documents/'.$token)->assertNotFound();

        $this->delete(route('platform.documents.shares.destroy', $shareId))->assertRedirect();
        $this->assertDatabaseMissing('farast_document_shares', ['id' => $shareId]);
    }

    public function test_navigation_contains_only_real_routes_and_correct_filters(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $groups = app(DashboardNavigationService::class)->forUser($admin);
        $items = collect($groups)->flatMap(fn (array $group) => $group['items'])->keyBy('key');

        $this->assertTrue($items->isNotEmpty());
        $this->assertTrue($items->every(fn (array $item) => is_string($item['href']) && $item['disabled'] === false && $item['planned'] === false));
        $this->assertSame(route('admin.users'), $items['access.users']['href']);
        $this->assertStringContainsString('trashed=1', $items['documents.deleted']['href']);
        $this->assertStringContainsString('shared=1', $items['documents.shared']['href']);
        $this->assertStringContainsString('favorite=1', $items['editor.favorites']['href']);
        $this->assertStringContainsString('status=draft', $items['editor.drafts']['href']);
        $this->assertStringEndsWith('#editor-documents', $items['workspace.recent']['href']);
        $this->assertStringEndsWith('#workspace-shortcuts', $items['workspace.shortcuts']['href']);
        $this->assertStringEndsWith('#voice-settings', $items['settings.providers']['href']);
        $this->assertArrayNotHasKey('workspace.tasks', $items->all());
        $this->assertArrayNotHasKey('documents.archive', $items->all());
        $this->assertArrayNotHasKey('ai.assistant', $items->all());
    }

    public function test_organization_navigation_is_scoped_and_global_admin_links_are_separate(): void
    {
        $manager = $this->makeUser(User::ROLE_ORGANIZATION_MANAGER);
        $managerItems = collect(app(DashboardNavigationService::class)->forUser($manager))
            ->flatMap(fn (array $group) => $group['items'])
            ->keyBy('key');

        $this->assertArrayHasKey('collaboration.teams', $managerItems->all());
        $this->assertArrayHasKey('collaboration.members', $managerItems->all());
        $this->assertArrayNotHasKey('organization.profile', $managerItems->all());
        $this->assertArrayNotHasKey('access.users', $managerItems->all());

        $admin = $this->makeUser(User::ROLE_ADMIN);
        $adminItems = collect(app(DashboardNavigationService::class)->forUser($admin))
            ->flatMap(fn (array $group) => $group['items'])
            ->keyBy('key');

        $this->assertArrayHasKey('organization.profile', $adminItems->all());
        $this->assertArrayHasKey('access.users', $adminItems->all());
        $this->assertArrayNotHasKey('collaboration.teams', $adminItems->all());
        $this->assertArrayNotHasKey('collaboration.members', $adminItems->all());
    }

    public function test_observer_navigation_hides_write_and_share_destinations(): void
    {
        $observer = $this->makeUser(User::ROLE_OBSERVER);
        $groups = app(DashboardNavigationService::class)->forUser($observer);
        $items = collect($groups)->flatMap(fn (array $group) => $group['items'])->keyBy('key');

        $this->assertArrayHasKey('documents.mine', $items->all());
        $this->assertArrayNotHasKey('editor.start', $items->all());
        $this->assertArrayNotHasKey('documents.deleted', $items->all());
        $this->assertArrayNotHasKey('documents.shared', $items->all());
    }

    public function test_observer_dashboard_does_not_offer_project_creation(): void
    {
        $observer = $this->makeUser(User::ROLE_OBSERVER);

        $this->actingAs($observer)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('پروژه جدید');
    }

    public function test_legacy_default_user_role_is_normalized_to_employee(): void
    {
        $user = $this->makeUser('user');

        $this->assertSame(User::ROLE_EMPLOYEE, $user->fresh()->role);
        $this->assertTrue(app(AuthorizationService::class)->allows($user, 'documents.create'));
        $this->assertTrue(app(AuthorizationService::class)->allows($user, 'documents.share'));
        $this->assertTrue(app(AuthorizationService::class)->allows($user, 'documents.delete'));
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => 'Workspace Test User',
            'email' => 'workspace-'.Str::uuid().'@example.test',
            'mobile' => '09'.random_int(100000000, 999999999),
            'password' => 'secret',
            'role' => $role,
            'is_verified' => true,
        ]);
    }

    private function makeDocument(User $owner, string $title): FarastDocument
    {
        return FarastDocument::create([
            'user_id' => $owner->id,
            'title' => $title,
            'content' => '<p>Content</p>',
            'status' => 'active',
            'is_favorite' => false,
        ]);
    }
}
