<?php

namespace Tests\Feature;

use App\Models\FarastStorageObject;
use App\Models\User;
use App\Models\UserFile;
use App\Services\UploadSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EditorUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function cleanInspection(UploadedFile $file): array
    {
        return [
            'safe' => true,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'checksum' => hash('sha256', 'scanned-clean-file'),
            'scan' => 'clean',
        ];
    }

    public function test_public_editor_upload_fails_closed_when_scanner_is_not_configured(): void
    {
        Storage::fake('private');
        $previousEnv = getenv('FARAST_CLAMSCAN_BINARY');
        $previousServer = $_SERVER['FARAST_CLAMSCAN_BINARY'] ?? null;
        $previousSuperglobal = $_ENV['FARAST_CLAMSCAN_BINARY'] ?? null;

        try {
            putenv('FARAST_CLAMSCAN_BINARY=');
            $_SERVER['FARAST_CLAMSCAN_BINARY'] = '';
            $_ENV['FARAST_CLAMSCAN_BINARY'] = '';

            $this->postJson('/editor/upload', [
                'source' => UploadedFile::fake()->image('source.png', 2, 2),
            ])->assertStatus(503);

            $this->assertSame([], Storage::disk('private')->allFiles());
            $this->assertDatabaseCount('farast_storage_objects', 0);
        } finally {
            if ($previousEnv === false) putenv('FARAST_CLAMSCAN_BINARY');
            else putenv('FARAST_CLAMSCAN_BINARY='.$previousEnv);
            if ($previousServer === null) unset($_SERVER['FARAST_CLAMSCAN_BINARY']);
            else $_SERVER['FARAST_CLAMSCAN_BINARY'] = $previousServer;
            if ($previousSuperglobal === null) unset($_ENV['FARAST_CLAMSCAN_BINARY']);
            else $_ENV['FARAST_CLAMSCAN_BINARY'] = $previousSuperglobal;
        }
    }

    public function test_quarantined_source_object_cannot_enter_ocr_pipeline(): void
    {
        Storage::fake('private');
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $path = 'typing/'.$user->id.'/quarantined.pdf';
        $bytes = 'not scanned';
        Storage::disk('private')->put($path, $bytes);
        FarastStorageObject::create([
            'user_id' => $user->id,
            'disk' => 'private',
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'status' => 'quarantined',
            'classification' => 'upload',
            'provenance' => ['scan' => 'not_configured'],
        ]);
        session([
            'pending_upload' => ['path' => $path, 'mime' => 'application/pdf', 'name' => 'quarantined.pdf'],
            'typing_preflight_accepted' => ['path' => $path, 'hash' => hash('sha256', $bytes), 'pages' => 1],
        ]);

        $this->actingAs($user)
            ->postJson('/editor/analyze', ['path' => $path, 'mime' => 'application/pdf', 'source_name' => 'quarantined.pdf'])
            ->assertStatus(423);
    }

    public function test_editor_upload_creates_approved_storage_object_only_after_clean_scan(): void
    {
        Storage::fake('private');
        $file = UploadedFile::fake()->image('source.png', 2, 2);
        $this->instance(UploadSecurityService::class, \Mockery::mock(UploadSecurityService::class, function ($mock) use ($file) {
            $mock->shouldReceive('inspect')->once()->andReturn($this->cleanInspection($file));
        }));

        $response = $this->postJson('/editor/upload', ['source' => $file])->assertOk();
        $path = $response->json('path');

        $this->assertDatabaseHas('farast_storage_objects', [
            'path' => $path,
            'classification' => 'upload',
            'status' => 'approved',
        ]);
        Storage::disk('private')->assertExists($path);
    }

    public function test_user_file_upload_is_scanned_before_document_parsing_and_saved_as_approved(): void
    {
        Storage::fake('private');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('library.png', 2, 2);
        $this->instance(UploadSecurityService::class, \Mockery::mock(UploadSecurityService::class, function ($mock) use ($file) {
            $mock->shouldReceive('inspect')->once()->andReturn($this->cleanInspection($file));
        }));

        $response = $this->actingAs($user)->postJson('/editor/files', ['file' => $file])->assertOk();
        $path = $response->json('file.id')
            ? UserFile::where('user_id', $user->id)->value('path')
            : null;

        $this->assertNotNull($path);
        $this->assertDatabaseHas('farast_storage_objects', [
            'user_id' => $user->id,
            'path' => $path,
            'classification' => 'user_file',
            'status' => 'approved',
        ]);
    }

    public function test_user_file_selection_requires_approved_unchanged_source_and_creates_approved_ocr_object(): void
    {
        Storage::fake('private');
        $user = User::factory()->create();
        $path = 'user-files/'.$user->id.'/source.png';
        Storage::disk('private')->put($path, 'clean scanned bytes');
        $checksum = hash('sha256', 'clean scanned bytes');
        $file = UserFile::create([
            'user_id' => $user->id,
            'original_name' => 'source.png',
            'path' => $path,
            'disk' => 'private',
            'mime' => 'image/png',
            'size_bytes' => strlen('clean scanned bytes'),
            'page_count' => 1,
            'estimated_price_rials' => 1000,
            'status' => 'local',
            'uploaded_at' => now(),
            'local_expires_at' => now()->addDays(14),
            'remote_expires_at' => now()->addDays(90),
        ]);
        FarastStorageObject::create([
            'user_id' => $user->id,
            'disk' => 'private',
            'path' => $path,
            'mime' => 'image/png',
            'size' => strlen('clean scanned bytes'),
            'checksum' => $checksum,
            'status' => 'approved',
            'classification' => 'user_file',
            'provenance' => ['scan' => 'clean'],
        ]);

        $response = $this->actingAs($user)
            ->postJson('/editor/files/'.$file->id.'/select')
            ->assertOk();

        $this->assertDatabaseHas('farast_storage_objects', [
            'user_id' => $user->id,
            'path' => $response->json('path'),
            'classification' => 'upload',
            'status' => 'approved',
        ]);
    }
}
