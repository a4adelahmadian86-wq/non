<?php

namespace Tests\Feature;

use App\Models\TypingDocument;
use App\Models\User;
use App\Services\EditorDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditorDocumentArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $u = new User;
        $u->name = 'Editor QA';
        $u->email = 'editor-'.uniqid().'@example.test';
        $u->mobile = '09'.str_pad((string) random_int(100000000, 999999999), 9, '0');
        $u->password = bcrypt('secret');
        $u->save();

        return $u;
    }

    public function test_editor_save_creates_canonical_document_and_version(): void
    {
        $user = $this->makeUser();
        $legacy = TypingDocument::create([
            'user_id' => $user->id,
            'title' => 'سند تست',
            'content' => '<p>قدیمی</p>',
            'status' => 'draft',
        ]);

        $result = app(EditorDocumentService::class)->save(
            $legacy,
            '<h1>عنوان</h1><p>سلام جهان</p>',
            'سند جدید',
            null,
            'manual',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['revision']);
        $this->assertNotNull($legacy->fresh()->farast_document_id);
        $this->assertDatabaseHas('farast_documents', [
            'id' => $legacy->fresh()->farast_document_id,
            'user_id' => $user->id,
            'title' => 'سند جدید',
            'revision' => 1,
        ]);
        $this->assertDatabaseHas('farast_document_versions', [
            'document_id' => $legacy->fresh()->farast_document_id,
            'user_id' => $user->id,
        ]);
    }

    public function test_http_save_preserves_space_only_canonical_runs(): void
    {
        $user = $this->makeUser();

        $create = $this->actingAs($user)->postJson('/editor/documents', ['title' => 'فاصله']);
        $create->assertOk();

        $documentId = (int) $create->json('document_id');
        $model = [
            'schema' => 3,
            'type' => 'document',
            'direction' => 'rtl',
            'sections' => [[
                'id' => 'section-1',
                'blocks' => [[
                    'id' => 'block-1',
                    'type' => 'paragraph',
                    'runs' => [
                        ['id' => 'run-1', 'text' => 'سلام'],
                        ['id' => 'run-space', 'text' => ' '],
                        ['id' => 'run-2', 'text' => 'فراست'],
                    ],
                ]],
            ]],
            'comments' => [],
            'review' => [],
        ];

        $response = $this->actingAs($user)->postJson('/editor/save', [
            'document_id' => $documentId,
            'title' => 'فاصله',
            'content' => '<p>سلام فراست</p>',
            'document_model' => $model,
            'revision' => 1,
            'source' => 'manual',
            'page_settings' => ['paper' => 'A4'],
        ]);

        $response->assertOk()->assertJsonPath('revision', 2);

        $saved = TypingDocument::findOrFail($documentId)->farastDocument->fresh()->content_json;
        $runs = $saved['sections'][0]['blocks'][0]['runs'];

        $this->assertSame('سلام فراست', implode('', array_map(fn ($run) => $run['text'], $runs)));
        $this->assertSame(' ', $runs[1]['text']);
        $this->assertSame('سلام فراست', $saved['plain_text']);
    }

    public function test_editor_can_create_and_persist_native_document_model(): void
    {
        $user = $this->makeUser();
        $create = $this->actingAs($user)->postJson('/editor/documents', ['title' => 'سند مدل']);
        $create->assertOk()->assertJsonPath('title', 'سند مدل');
        $id = (int) $create->json('document_id');
        $this->assertDatabaseHas('farast_documents', ['title' => 'سند مدل', 'revision' => 1]);

        $model = ['schema' => 3, 'type' => 'document', 'direction' => 'rtl', 'settings' => ['paper' => 'A4'], 'sections' => [['id' => 'section-1', 'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'runs' => [['text' => 'سلام', 'bold' => true]]]]]], 'comments' => [], 'review' => []];
        $legacy = TypingDocument::findOrFail($id);
        $result = app(EditorDocumentService::class)->save($legacy, '<p>سلام</p>', 'سند مدل', 1, 'manual', ['paper' => 'A4'], $model);
        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['revision']);
        $this->assertSame(3, $legacy->farastDocument->fresh()->content_json['schema']);
        $this->assertSame('b1', $legacy->farastDocument->fresh()->content_json['sections'][0]['blocks'][0]['id']);
    }

    public function test_editor_persistence_sanitizes_script_and_event_handlers(): void
    {
        $user = $this->makeUser();
        $legacy = TypingDocument::create([
            'user_id' => $user->id,
            'title' => 'امنیت',
            'content' => '<p>متن</p>',
            'page_count' => 1,
            'word_count' => 1,
            'language_mix' => ['fa' => true],
            'status' => 'draft',
        ]);
        $result = app(EditorDocumentService::class)->save($legacy, '<p onclick="alert(1)">سلام</p><script>alert(2)</script><a href="javascript:alert(3)">پیوند</a>', 'امنیت', null, 'manual');
        $this->assertTrue($result['ok']);
        $saved = $legacy->farastDocument->fresh();
        $this->assertStringNotContainsString('<script', $saved->content);
        $this->assertStringNotContainsString('onclick=', $saved->content);
        $this->assertStringNotContainsString('javascript:', $saved->content);
        $this->assertStringContainsString('سلام', $saved->content);
    }

    public function test_stale_revision_is_rejected_without_overwriting_canonical_content(): void
    {
        $user = $this->makeUser();
        $legacy = TypingDocument::create([
            'user_id' => $user->id,
            'title' => 'تعارض',
            'content' => '<p>نسخه اول</p>',
            'status' => 'draft',
        ]);

        $service = app(EditorDocumentService::class);
        $first = $service->save($legacy, '<p>نسخه اول ذخیره‌شده</p>', null, null, 'manual');

        $second = $service->save($legacy->fresh(), '<p>نسخه قدیمی</p>', null, $first['revision'] - 1, 'autosave');

        $this->assertTrue($second['conflict']);
        $this->assertDatabaseHas('farast_documents', [
            'id' => $legacy->fresh()->farast_document_id,
            'content' => '<p>نسخه اول ذخیره‌شده</p>',
            'revision' => 1,
        ]);
    }

    public function test_legacy_schema_is_migrated_to_semantic_runs_without_data_loss(): void
    {
        $user = $this->makeUser();
        $legacy = TypingDocument::create([
            'user_id' => $user->id,
            'title' => 'مهاجرت',
            'content' => '<p><strong>سلام</strong> دنیا</p>',
            'status' => 'draft',
        ]);

        $model = [
            'schema' => 2,
            'type' => 'document',
            'direction' => 'rtl',
            'sections' => [[
                'id' => 'section-1',
                'blocks' => [[
                    'id' => 'legacy-block',
                    'type' => 'paragraph',
                    'text' => 'سلام دنیا',
                    'html' => '<p><strong>سلام</strong> دنیا</p>',
                ]],
            ]],
        ];

        $result = app(EditorDocumentService::class)->save(
            $legacy,
            '<p><strong>سلام</strong> دنیا</p>',
            'مهاجرت',
            null,
            'manual',
            null,
            $model,
        );

        $this->assertTrue($result['ok']);
        $saved = $legacy->farastDocument->fresh()->content_json;
        $this->assertSame(3, $saved['schema']);
        $this->assertSame('legacy-block', $saved['sections'][0]['blocks'][0]['id']);
        $this->assertSame('سلام دنیا', implode('', array_map(fn ($run) => $run['text'], $saved['sections'][0]['blocks'][0]['runs'])));
        $this->assertTrue($saved['sections'][0]['blocks'][0]['runs'][0]['bold']);
    }

    public function test_semantic_model_is_rendered_back_to_compatibility_html(): void
    {
        $user = $this->makeUser();
        $legacy = TypingDocument::create([
            'user_id' => $user->id,
            'title' => 'رندر',
            'content' => '<p><br></p>',
            'status' => 'draft',
        ]);

        $model = [
            'schema' => 3,
            'type' => 'document',
            'direction' => 'rtl',
            'sections' => [[
                'id' => 'section-1',
                'blocks' => [[
                    'id' => 'b-render',
                    'type' => 'paragraph',
                    'runs' => [
                        ['text' => 'سلام '],
                        ['text' => 'فراست', 'bold' => true],
                    ],
                ]],
            ]],
        ];

        app(EditorDocumentService::class)->save($legacy, '<p>ignored</p>', 'رندر', null, 'manual', null, $model);
        $saved = $legacy->farastDocument->fresh();
        $this->assertStringContainsString('<strong>فراست</strong>', $saved->content);
        $this->assertSame('سلام فراست', $saved->content_json['plain_text']);
    }


    public function test_lists_and_structural_fields_round_trip_through_canonical_model(): void
    {
        $user = $this->makeUser();
        $legacy = TypingDocument::create([
            'user_id' => $user->id,
            'title' => 'ساختار',
            'content' => '<p><br></p>',
            'status' => 'draft',
        ]);

        $model = [
            'schema' => 3,
            'type' => 'document',
            'direction' => 'rtl',
            'sections' => [[
                'id' => 'section-1',
                'blocks' => [[
                    'id' => 'list-1',
                    'type' => 'list',
                    'ordered' => false,
                    'items' => [
                        ['id' => 'item-1', 'runs' => [['text' => 'اول']]],
                        ['id' => 'item-2', 'runs' => [['text' => 'دوم']]],
                    ],
                ]],
            ]],
            'fields' => [['id' => 'field-1', 'type' => 'pageNumber']],
        ];

        app(EditorDocumentService::class)->save($legacy, '<p>ignored</p>', 'ساختار', null, 'manual', null, $model);
        $saved = $legacy->farastDocument->fresh();
        $canonical = $saved->content_json;

        $this->assertSame('list', $canonical['sections'][0]['blocks'][0]['type']);
        $this->assertCount(2, $canonical['sections'][0]['blocks'][0]['items']);
        $this->assertSame('pageNumber', $canonical['fields'][0]['type']);
        $this->assertStringContainsString('<ul>', $saved->content);
        $this->assertStringContainsString('اول', $saved->content);
    }

    public function test_structural_annotations_and_resources_survive_canonical_sanitization(): void
    {
        $user = $this->makeUser();
        $legacy = TypingDocument::create([
            'user_id' => $user->id,
            'title' => 'نشانه‌ها',
            'content' => '<p>متن</p>',
            'status' => 'draft',
        ]);

        $model = [
            'schema' => 3,
            'type' => 'document',
            'direction' => 'rtl',
            'sections' => [[
                'id' => 'section-1',
                'blocks' => [[
                    'id' => 'b1',
                    'type' => 'paragraph',
                    'runs' => [
                        ['id' => 'run-1', 'text' => 'سلام', 'bold' => true],
                        ['id' => 'run-2', 'text' => ' دنیا'],
                    ],
                ]],
            ]],
            'comments' => [[
                'id' => 'comment-1', 'text' => 'بررسی شود',
                'anchor' => ['start' => ['blockId' => 'b1', 'offset' => 0], 'end' => ['blockId' => 'b1', 'offset' => 4]],
            ]],
            'reviewChanges' => [[
                'id' => 'change-1', 'type' => 'insertion', 'status' => 'pending',
                'blockId' => 'b1', 'start' => 4, 'end' => 8,
                'before' => [], 'after' => [['text' => ' جدید']],
            ]],
            'bookmarks' => [['id' => 'bookmark-1', 'name' => 'مقدمه', 'blockId' => 'b1', 'offset' => 0]],
            'resources' => [['id' => 'res-1', 'type' => 'image', 'mime' => 'image/png', 'name' => 'x.png', 'source' => 'data:image/png;base64,AAAA']],
        ];

        app(EditorDocumentService::class)->save($legacy, '<p>ignored</p>', 'نشانه‌ها', null, 'manual', null, $model);
        $saved = $legacy->farastDocument->fresh()->content_json;

        $this->assertSame('run-1', $saved['sections'][0]['blocks'][0]['runs'][0]['id']);
        $this->assertSame('comment-1', $saved['comments'][0]['id']);
        $this->assertSame('change-1', $saved['reviewChanges'][0]['id']);
        $this->assertSame('bookmark-1', $saved['bookmarks'][0]['id']);
        $this->assertSame('res-1', $saved['resources'][0]['id']);
        $this->assertSame('data:image/png;base64,AAAA', $saved['resources'][0]['source']);
        $this->assertStringContainsString('data:image/png;base64,AAAA', $legacy->farastDocument->fresh()->content);
    }

    public function test_paragraph_layout_semantics_round_trip(): void
    {
        $user = $this->makeUser();
        $legacy = TypingDocument::create(['user_id' => $user->id, 'title' => 'طرح', 'content' => '<p>متن</p>', 'status' => 'draft']);
        $model = [
            'schema' => 3, 'type' => 'document', 'direction' => 'rtl',
            'sections' => [[
                'id' => 'section-1',
                'blocks' => [[
                    'id' => 'layout-1', 'type' => 'paragraph', 'direction' => 'rtl',
                    'alignment' => 'justify', 'lineHeight' => '1.8', 'paragraphSpacing' => 12, 'indent' => 2,
                    'runs' => [['id' => 'run-layout', 'text' => 'متن']],
                ]],
            ]],
        ];
        app(EditorDocumentService::class)->save($legacy, '<p>ignored</p>', 'طرح', null, 'manual', null, $model);
        $saved = $legacy->farastDocument->fresh()->content_json['sections'][0]['blocks'][0];
        $this->assertSame('justify', $saved['alignment']);
        $this->assertSame('1.8', $saved['lineHeight']);
        $this->assertSame(12, $saved['paragraphSpacing']);
        $this->assertSame(2, $saved['indent']);
        $this->assertSame('rtl', $saved['direction']);
    }

}
