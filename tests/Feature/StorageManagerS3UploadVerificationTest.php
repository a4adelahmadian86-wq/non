<?php

namespace Tests\Feature;

use App\Models\StorageVolume;
use App\Services\Storage\S3CompatibleAdapter;
use App\Services\Storage\StorageManager;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class StorageManagerS3UploadVerificationTest extends TestCase
{
    public function test_s3_upload_verification_uses_head_metadata_without_full_object_readback(): void
    {
        $volume = new StorageVolume([
            'driver' => 's3',
            'drive_key' => 'iran_primary',
        ]);
        $checksum = hash('sha256', 'farast-content');
        $s3 = Mockery::mock(S3CompatibleAdapter::class);
        $s3->shouldReceive('headObject')->once()->with($volume, 'products/file.pdf')->andReturn([
            'size_bytes' => strlen('farast-content'),
            'sha256' => $checksum,
        ]);

        $manager = new StorageManager($s3);
        $method = new ReflectionMethod($manager, 'verifyWrittenObject');
        $method->setAccessible(true);
        $method->invoke($manager, $volume, 'products/file.pdf', $checksum, strlen('farast-content'));

        $this->assertTrue(true);
    }

    public function test_s3_upload_verification_rejects_missing_or_mismatched_metadata(): void
    {
        $volume = new StorageVolume([
            'driver' => 's3',
            'drive_key' => 'iran_primary',
        ]);
        $s3 = Mockery::mock(S3CompatibleAdapter::class);
        $s3->shouldReceive('headObject')->once()->andReturn([
            'size_bytes' => 99,
            'sha256' => str_repeat('0', 64),
        ]);

        $manager = new StorageManager($s3);
        $method = new ReflectionMethod($manager, 'verifyWrittenObject');
        $method->setAccessible(true);

        try {
            $method->invoke($manager, $volume, 'products/file.pdf', hash('sha256', 'expected'), 14);
            $this->fail('Mismatched remote size or SHA metadata must fail the upload verification.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('Metadata', $exception->getMessage());
        }
    }
}
