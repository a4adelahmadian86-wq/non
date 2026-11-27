<?php

namespace Tests\Feature;

use App\Models\FarastStorageObject;
use App\Models\StorageManagedFile;
use App\Models\StorageVolume;
use App\Models\TypingDocument;
use App\Models\User;
use App\Services\EditorDocumentService;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EditorAssetTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (StorageVolume::query()->pluck('id') as $id) {
            File::deleteDirectory(storage_path('app/storage-volumes/'.$id));
        }
        parent::tearDown();
    }

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

    public function test_private_editor_asset_upload_uses_iran_primary_when_configured(): void
    {
        $iran = StorageVolume::create([
            'drive_key' => 'iran_primary', 'name' => 'Iran', 'driver' => 'local',
            'capacity_bytes' => 10485760, 'used_bytes' => 0, 'object_count' => 0,
            'status' => 'healthy', 'is_active' => true, 'priority' => 100,
        ]);

        [$user, , $resource, $object] = $this->makeAsset();
        $object->update(['status' => 'approved']);
        $managed = StorageManagedFile::query()
            ->where('source_type', FarastStorageObject::class)
            ->where('source_id', $object->id)
            ->firstOrFail();

        $this->assertSame($iran->id, $managed->storage_volume_id);
        $this->assertSame('iran_primary', $managed->drive_key);
        $this->assertSame(StorageManager::CRITICAL, $managed->classification);
        $contents = app(StorageManager::class)->get($object->disk, $object->path);
        $this->assertSame($object->checksum, hash('sha256', $contents));
        $this->actingAs($user)->get($resource['url'])->assertOk();
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
    public function test_unknown_asset_status_and_missing_storage_record_fail_closed(): void
    {
        [$user, , $resource, $object] = $this->makeAsset('scanning');
        $this->actingAs($user)->get($resource['url'])->assertNotFound();

        $object->delete();
        $this->actingAs($user)->get($resource['url'])->assertNotFound();
    }

}
