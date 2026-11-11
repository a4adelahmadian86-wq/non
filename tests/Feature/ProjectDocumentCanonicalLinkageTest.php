<?php

namespace Tests\Feature;

use App\Models\FarastDocument;
use App\Models\FarastProject;
use App\Models\TypingDocument;
use App\Models\User;
use App\Services\EditorDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProjectDocumentCanonicalLinkageTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $user, string $name): FarastProject
    {
        return FarastProject::create([
            'user_id' => $user->id,
            'name' => $name,
            'project_type' => 'typing',
            'workflow' => 'manual',
            'template_code' => 'standard',
            'status' => 'active',
            'context' => [],
            'billing_state' => ['status' => 'paid'],
            'output_state' => ['status' => 'unlocked'],
        ]);
    }

    private function typingDocument(User $user, FarastProject $project, ?int $farastDocumentId = null): TypingDocument
    {
        return TypingDocument::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'farast_document_id' => $farastDocumentId,
            'title' => 'سند پروژه',
            'content' => '<p>محتوای اولیه</p>',
            'source_path' => null,
            'source_hash' => null,
            'page_count' => 1,
            'word_count' => 2,
            'language_mix' => ['fa' => 2],
            'status' => 'completed',
            'price_rials' => 0,
            'expires_at' => null,
        ]);
    }

    public function test_editor_creates_one_canonical_document_inside_the_legacy_documents_project_and_keeps_versions_synced(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user, 'پروژه اتصال سند');
        $legacy = $this->typingDocument($user, $project);

        $service = app(EditorDocumentService::class);
        $first = $service->save($legacy, '<p>نسخه نخست سند</p>', 'سند نهایی', 0, 'manual');

        $this->assertTrue($first['ok']);
        $document = FarastDocument::query()->findOrFail($first['document_id']);
        $this->assertSame($project->id, (int) $document->project_id);
        $this->assertSame($user->id, (int) $document->user_id);
        $this->assertSame($document->id, (int) $legacy->fresh()->farast_document_id);
        $this->assertSame($document->id, (int) $legacy->fresh()->farastDocument->id);
        $this->assertSame($document->content, $legacy->fresh()->content);
        $this->assertSame(1, $document->versions()->count());
        $this->assertSame(1, $project->documents()->count());

        $second = $service->save($legacy->fresh(), '<p>نسخه دوم سند</p>', 'سند نهایی', 1, 'manual');
        $document->refresh();

        $this->assertTrue($second['ok']);
        $this->assertSame($document->id, (int) $second['document_id']);
        $this->assertSame($project->id, (int) $document->project_id);
        $this->assertSame(2, (int) $document->revision);
        $this->assertSame(2, $document->versions()->count());
        $this->assertSame($document->content, $legacy->fresh()->content);
        $this->assertSame(1, $project->documents()->count());
    }

    public function test_project_document_versions_share_revoke_and_trash_restore_use_the_same_canonical_record(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = $this->project($user, 'پروژه بازیابی و اشتراک');
        $legacy = $this->typingDocument($user, $project);
        $service = app(EditorDocumentService::class);

        $first = $service->save($legacy, '<p>نسخه نخست برای بازیابی</p>', 'سند اشتراکی', 0, 'manual');
        $second = $service->save($legacy->fresh(), '<p>نسخه دوم جاری</p>', 'سند اشتراکی', 1, 'manual');
        $document = FarastDocument::query()->findOrFail($second['document_id']);

        $this->actingAs($user)
            ->get(route('editor.document.versions', $legacy->id))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(2, 'versions');

        $firstVersion = DB::table('farast_document_versions')
            ->where('document_id', $document->id)
            ->orderBy('id')
            ->first();

        $this->post(route('editor.document.version.restore', [$legacy->id, $firstVersion->id]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $document->refresh();
        $this->assertStringContainsString('نسخه نخست برای بازیابی', $document->content);
        $this->assertSame($document->content, $legacy->fresh()->content);
        $this->assertSame($project->id, (int) $document->project_id);
        $this->assertSame(1, $project->documents()->count());

        $shareResponse = $this->postJson(route('platform.documents.share', $document->id), [
            'permission' => 'view',
        ])->assertOk();

        $token = basename($shareResponse->json('url'));
        $share = DB::table('farast_document_shares')->where('token', $token)->first();
        $this->assertNotNull($share);

        $this->get('/shared/documents/'.$token)
            ->assertOk()
            ->assertSee('نسخه نخست برای بازیابی');

        $this->delete(route('platform.documents.shares.destroy', $share->id))
            ->assertRedirect();
        $this->get('/shared/documents/'.$token)->assertNotFound();

        $this->post(route('platform.documents.trash', $document->id))->assertRedirect();
        $this->assertDatabaseHas('farast_documents', [
            'id' => $document->id,
            'status' => 'trashed',
        ]);

        $this->post(route('platform.documents.restore', $document->id))->assertRedirect();
        $this->assertDatabaseHas('farast_documents', [
            'id' => $document->id,
            'status' => 'active',
            'trashed_at' => null,
            'project_id' => $project->id,
        ]);
    }

    public function test_editor_refuses_to_relink_a_canonical_document_to_a_different_project(): void
    {
        $user = User::factory()->create();
        $originalProject = $this->project($user, 'پروژه اصلی');
        $otherProject = $this->project($user, 'پروژه دیگر');
        $document = FarastDocument::create([
            'user_id' => $user->id,
            'project_id' => $originalProject->id,
            'title' => 'سند متصل به پروژه اصلی',
            'content' => '<p>محتوای اصلی</p>',
            'content_json' => [],
            'document_format' => 'farast-v1',
            'page_settings' => [],
            'revision' => 1,
            'last_saved_at' => now(),
            'status' => 'active',
        ]);
        $legacy = $this->typingDocument($user, $otherProject, $document->id);

        try {
            app(EditorDocumentService::class)->save($legacy, '<p>تغییر نامعتبر</p>', null, 1, 'manual');
            $this->fail('A canonical document must not be moved across projects by a legacy link.');
        } catch (RuntimeException $exception) {
            $this->assertSame('document_project_mismatch', $exception->getMessage());
        }

        $this->assertSame($originalProject->id, (int) $document->fresh()->project_id);
        $this->assertSame(1, (int) $document->fresh()->revision);
        $this->assertSame(0, $otherProject->documents()->count());
    }
}
