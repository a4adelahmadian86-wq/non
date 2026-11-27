<?php

namespace Tests\Feature;

use App\Http\Controllers\UserFileController;
use App\Models\User;
use App\Models\UserFile;
use App\Services\CapabilityService;
use App\Services\PricingService;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class UserFileStorageClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_editor_upload_uses_critical_iranian_storage_classification(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $file = UploadedFile::fake()->create('private-document.pdf', 8, 'application/pdf');
        $request = Request::create('/editor/files', 'POST', [], [], ['file' => $file]);
        $request->setUserResolver(fn () => $user);

        $pricing = Mockery::mock(PricingService::class);
        $pricing->shouldReceive('estimateByPages')->once()->andReturn(['price_rials' => 1000]);

        $capabilities = Mockery::mock(CapabilityService::class);
        $capabilities->shouldReceive('forUser')->once()->with($user)->andReturn(['max_file_mb' => 50]);

        $storage = Mockery::mock(StorageManager::class);
        $storage->shouldReceive('storeUploadedFile')
            ->once()
            ->withArgs(fn ($uploaded, $classification, $module, $referenceType, $referenceId, $productId, $visibility) =>
                $uploaded === $file
                && $classification === StorageManager::CRITICAL
                && $module === 'editor'
                && $referenceType === UserFile::class
                && $visibility === 'private'
            )
            ->andReturn([
                'path' => 'editor/1/private-document.pdf',
                'disk' => 'storage_volume:1',
                'volume_id' => 1,
                'managed_file_id' => 77,
            ]);
        $storage->shouldReceive('bindReference')->once()->with(77, UserFile::class, Mockery::type('int'));

        $response = app(UserFileController::class)->upload($request, $pricing, $capabilities, $storage);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('remote', $response->getData(true)['file']['status']);
        $this->assertDatabaseHas('user_files', [
            'user_id' => $user->id,
            'path' => 'editor/1/private-document.pdf',
            'status' => 'remote',
        ]);
    }
}
