<?php

namespace Tests\Feature;

use App\Jobs\ReviewProductIntakeFile;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\StoreProduct;
use App\Models\StoreProductIntakeFile;
use App\Models\StoreProductIntakeSession;
use App\Models\User;
use App\Services\Storage\StorageManager;
use App\Services\UploadSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreProductIntakeSessionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر فروشگاه',
            'mobile' => '09120000001',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    public function test_new_product_flow_requires_a_supported_type_and_does_not_create_a_product_shell(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.store.create'))
            ->assertRedirect(route('admin.store.intake.create'));

        $this->actingAs($admin)->post(route('admin.store.intake.store'), [
            'product_type' => 'physical',
        ])->assertSessionHasErrors('product_type');

        $this->assertSame(0, StoreProduct::query()->count());

        $response = $this->actingAs($admin)->post(route('admin.store.intake.store'), [
            'product_type' => 'digital_file',
        ]);

        $session = StoreProductIntakeSession::query()->firstOrFail();
        $response->assertRedirect(route('admin.store.intake.show', $session));
        $this->assertSame('digital_file', $session->product_type);
        $this->assertSame(StoreProductIntakeSession::STATUS_SELECTING_FILES, $session->status);
        $this->assertSame(0, StoreProduct::query()->count());

        $this->actingAs($admin)->get(route('admin.store.intake.show', $session))
            ->assertOk()
            ->assertSee('انتخاب و بازرسی فایل‌ها')
            ->assertSee('product-intake-session.js')
            ->assertSee('انتخاب پوشه');
    }

    public function test_employee_requires_explicit_product_creation_permission(): void
    {
        $employee = User::create([
            'name' => 'کارمند محتوا',
            'mobile' => '09120000002',
            'password' => 'secret',
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $permission = Permission::firstOrCreate(
            ['key' => 'store.products.create'],
            ['label' => 'ثبت محصول و دوره', 'group' => 'store']
        );
        RolePermission::firstOrCreate([
            'role' => User::ROLE_EMPLOYEE,
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($employee)->post(route('admin.store.intake.store'), [
            'product_type' => 'course',
        ])->assertRedirect();

        $this->assertDatabaseHas('store_product_intake_sessions', [
            'user_id' => $employee->id,
            'product_type' => 'course',
        ]);
    }

    public function test_employee_cannot_request_antivirus_without_the_separate_security_permission(): void
    {
        $employee = User::create([
            'name' => 'کارمند بارگذاری',
            'mobile' => '09120000003',
            'password' => 'secret',
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $permission = Permission::firstOrCreate(
            ['key' => 'store.products.create'],
            ['label' => 'ثبت محصول و دوره', 'group' => 'store']
        );
        RolePermission::firstOrCreate([
            'role' => User::ROLE_EMPLOYEE,
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($employee)->post(route('admin.store.intake.store'), [
            'product_type' => 'digital_file',
            'antivirus_requested' => 1,
        ])->assertForbidden();

        $this->assertDatabaseCount('store_product_intake_sessions', 0);
    }

    public function test_manager_can_explicitly_review_a_file_when_automated_review_is_unavailable(): void
    {
        $admin = $this->admin();
        $owner = User::create([
            'name' => 'کارمند بارگذاری',
            'mobile' => '09120000004',
            'password' => 'secret',
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $createPermission = Permission::firstOrCreate(
            ['key' => 'store.products.create'],
            ['label' => 'ثبت محصول و دوره', 'group' => 'store']
        );
        RolePermission::firstOrCreate([
            'role' => User::ROLE_EMPLOYEE,
            'permission_id' => $createPermission->id,
        ]);
        $session = StoreProductIntakeSession::create([
            'id' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'product_type' => 'digital_file',
            'status' => StoreProductIntakeSession::STATUS_FILES_UPLOADED,
            'expires_at' => now()->addHours(12),
        ]);
        $file = StoreProductIntakeFile::create([
            'intake_session_id' => $session->id,
            'user_id' => $owner->id,
            'status' => 'uploaded',
            'supervisor_status' => 'manual_review_required',
            'staging_disk' => 'private',
            'original_name' => 'large-video.mp4',
            'relative_path' => 'course/large-video.mp4',
            'mime' => 'video/mp4',
            'size_bytes' => 30 * 1024 * 1024,
            'sha256' => str_repeat('a', 64),
            'inspection_json' => ['safe' => true, 'risk_level' => 'low'],
            'supervisor_review_json' => [
                'security_gate' => 'manual_scan_required',
                'reason' => 'file_exceeds_supervisor_limit',
                'automated_publication' => false,
            ],
            'expires_at' => now()->addHours(12),
        ]);

        $this->actingAs($admin)->get(route('admin.store.intake.show', $session))
            ->assertOk()
            ->assertSee('ثبت تأیید دستی فایل');

        $this->actingAs($admin)->post(route('admin.store.intake.files.manual-review', [$session, $file]), [
            'manager_notes' => 'فایل از نظر محتوا و تناسب با محصول توسط مدیر بررسی شد.',
        ])->assertRedirect();

        $this->assertSame('completed', $file->fresh()->supervisor_status);
        $this->assertTrue((bool) data_get($file->fresh()->supervisor_review_json, 'manual_review.approved'));
        $this->assertSame('manual_scan_required', data_get($file->fresh()->supervisor_review_json, 'security_gate'));

        $this->actingAs($owner)->get(route('admin.store.intake.details', $session))
            ->assertOk()
            ->assertSee('intake_session_id');
    }

    public function test_approved_file_is_moved_through_storage_manager_and_queued_for_supervisor_review(): void
    {
        Storage::fake('private');
        Queue::fake();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.store.intake.store'), [
            'product_type' => 'digital_file',
        ]);
        $session = StoreProductIntakeSession::query()->firstOrFail();

        $upload = UploadedFile::fake()->createWithContent('notes.txt', 'hello world');
        $checksum = hash_file('sha256', $upload->getRealPath());
        $inspection = [
            'safe' => true,
            'mime' => 'text/plain',
            'size' => 11,
            'checksum' => $checksum,
            'scan' => 'farast_static_v1',
            'scan_label' => 'بررسی ایستای داخلی فراست',
            'risk_level' => 'low',
            'detected_type' => 'text',
            'archive_entries' => [],
            'archive_entry_count' => 0,
            'findings' => [],
            'warnings' => [],
        ];

        $this->mock(UploadSecurityService::class, function ($mock) use ($inspection) {
            $mock->shouldReceive('inspect')->twice()->andReturn($inspection);
        });
        $this->mock(StorageManager::class, function ($mock) {
            $mock->shouldReceive('adoptExistingPrivate')->once()->andReturn([
                'managed_file_id' => 991,
                'disk' => 'private',
                'path' => 'managed/intake/notes.txt',
                'status' => 'stored',
            ]);
            $mock->shouldReceive('bindReference')->once();
        });

        $preflight = $this->actingAs($admin)->post(route('admin.store.intake.files.preflight', $session), [
            'file' => $upload,
            'relative_path' => 'docs/notes.txt',
            'is_primary' => 1,
        ]);
        $preflight->assertCreated()->assertJsonPath('inspection.detected_type', 'text');

        $file = StoreProductIntakeFile::query()->firstOrFail();
        $this->assertSame('docs/notes.txt', $file->relative_path);
        $this->assertSame('inspected', $file->status);

        $this->actingAs($admin)->post(route('admin.store.intake.files.confirm', [$session, $file]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('uploaded', $file->fresh()->status);
        $this->assertSame('pending', $file->fresh()->supervisor_status);
        Queue::assertPushed(ReviewProductIntakeFile::class, fn ($job) => $job->intakeFileId === $file->id);
    }
}
