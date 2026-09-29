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
}
