<?php

namespace Tests\Feature;

use App\Models\FarastStorageObject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FarastStorageObjectIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_long_object_paths_use_a_fixed_length_hash_for_disk_scoped_uniqueness(): void
    {
        $path = str_repeat('segment/', 112).'file.bin';
        $checksum = str_repeat('a', 64);

        $first = FarastStorageObject::create([
            'disk' => 'private',
            'path' => $path,
            'checksum' => $checksum,
        ]);

        $this->assertSame(hash('sha256', $path), $first->fresh()->path_hash);

        $otherDisk = FarastStorageObject::create([
            'disk' => 'archive',
            'path' => $path,
            'checksum' => $checksum,
        ]);
        $this->assertSame(hash('sha256', $path), $otherDisk->fresh()->path_hash);

        $this->expectException(QueryException::class);

        FarastStorageObject::create([
            'disk' => 'private',
            'path' => $path,
            'checksum' => $checksum,
        ]);
    }
}
