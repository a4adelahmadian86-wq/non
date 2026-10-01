<?php

namespace Tests\Feature;

use App\Models\TypingDocument;
use App\Models\User;
use App\Services\EditorDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EditorAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_image_assets_are_private_stable_references(): void
    {
        Storage::fake('private');

        $user = User::create([
            'name' => 'Asset Test',
            'mobile' => '09'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
            'role' => 'user',
            'is_verified' => true,
            'is_blocked' => false,
        ]);

        $document = TypingDocument::create([
            'user_id' => $user->id,
            'title' => 'تصویر',
            'content' => '<p><br></p>',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($user)->postJson('/editor/assets', [
            'document_id' => $document->id,
            'asset' => UploadedFile::fake()->image('test.png', 1, 1),
        ]);

        $response->assertOk()->assertJsonPath('resource.type', 'image');
        $resource = $response->json('resource');
        $this->assertStringStartsWith('asset-', $resource['id']);
        $this->assertNotEmpty($resource['storage_path']);
        $this->assertStringContainsString('/editor/documents/', $resource['url']);

        $model = [
            'schema' => 3,
            'type' => 'document',
            'direction' => 'rtl',
            'sections' => [[
                'id' => 'section-1',
                'blocks' => [[
                    'id' => 'image-block',
                    'type' => 'image',
                    'resourceId' => $resource['id'],
                ]],
            ]],
            'resources' => [$resource],
        ];

        app(EditorDocumentService::class)->save($document, '<p>ignored</p>', 'تصویر', null, 'manual', null, $model);

        $this->actingAs($user)
            ->get($resource['url'])
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
