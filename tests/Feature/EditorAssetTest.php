<?php

namespace Tests\\Feature;

use App\\Models\\FarastStorageObject;
use App\\Models\\TypingDocument;
use App\\Models\\User;
use App\\Services\\EditorDocumentService;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Http\\UploadedFile;
use Illuminate\\Support\\Facades\\Storage;
use Tests\\TestCase;

class EditorAssetTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Asset Test',
            'mobile' => '09'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
            'role' => 'user',
            'is_verified' => true,
            'is_blocked' => false,
        ]);
    }

    /**
     * Create a real document resource and its storage-object record.
     * Status changes here simulate a trusted review/scanning decision.
     */
    private function makeAsset(string $status = 'quarantined'): array
    {
        Storage::fake('private');
        $user = $this->makeUser();
        $document = TypingDocument::create([
            'user_id' => $user->id,
            'title' => 'تصویر',
            'content' => '<p><br></p>',
            'status' => 'draft',
        ]);
        $documents = app(EditorDocumentService::class);
        $documents->save($document, '<p><br></p>', 'تصویر', null, 'manual');
        $document = $document->fresh();

        $response = $this->actingAs($user)->postJson('/editor/assets', [
            'document_id' => $document->id,
            'asset' => UploadedFile::fake()->image('test.png', 1, 1),
        ]);
        $response->assertOk()->assertJsonPath('resource.type', 'image');
        $resource = $response->json('resource');

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
        $documents->save($document, '<p>تصویر</p>', 'تصویر', null, 'manual', null, $model);

        $object = FarastStorageObject::query()->where('path', $resource['storage_path'])->firstOrFail();
        if ($status !== 'quarantined') {
            $object->update(['status' => $status]);
        }

        return [$user, $document->fresh(), $resource, $object->fresh()];
    }

    public function test_newly_uploaded_quarantined_asset_is_not_delivered_until_approved(): void
    {
        [$user, , $resource, $object] = $this->makeAsset();

        $this->assertSame('quarantined', $object->status);
        $this->actingAs($user)->get($resource['url'])->assertNotFound();

        $object->update(['status' => 'approved']);
        $this->actingAs($user)
            ->get($resource['url'])
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_rejected_asset_is_never_delivered(): void
    {
        [$user, , $resource] = $this->makeAsset('rejected');

        $this->actingAs($user)->get($resource['url'])->assertNotFound();
    }

    public function test_missing_resource_id_is_not_found(): void
    {
        [$user, , $resource] = $this->makeAsset('approved');
        $url = str_replace($resource['id'], 'asset-not-present', $resource['url']);

        $this->actingAs($user)->get($url)->assertNotFound();
    }

    public function test_asset_cannot_be_read_by_another_user(): void
    {
        [, , $resource] = $this->makeAsset('approved');
        $other = $this->makeUser();

        $this->actingAs($other)->get($resource['url'])->assertNotFound();
    }

    public function test_resource_path_must_match_an_approved_storage_object(): void
    {
        [$user, $document, $resource] = $this->makeAsset('approved');
        $farast = $document->fresh()->farastDocument;
        $model = $farast->content_json;
        $model['resources'][0]['storage_path'] = '../outside/test.png';
        $farast->content_json = $model;
        $farast->save();

        $this->actingAs($user)->get($resource['url'])->assertNotFound();
    }

    public function test_approved_asset_with_missing_physical_file_is_not_delivered(): void
    {
        [$user, , $resource] = $this->makeAsset('approved');
        Storage::disk('private')->delete($resource['storage_path']);

        $this->actingAs($user)->get($resource['url'])->assertNotFound();
    }
}
