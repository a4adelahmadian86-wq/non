<?php

namespace Tests\Unit;

use App\Services\Storage\StorageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StorageClassificationTest extends TestCase
{
    #[DataProvider('classificationCases')]
    public function test_only_explicit_critical_files_are_routed_to_iran(string $classification, string $expected): void
    {
        $drive = $classification === StorageManager::CRITICAL ? 'iran_primary' : 'foreign_primary';
        $this->assertSame($expected, $drive);
    }

    public static function classificationCases(): array
    {
        return [
            'critical' => [StorageManager::CRITICAL, 'iran_primary'],
            'editor attachment' => [StorageManager::NON_CRITICAL, 'foreign_primary'],
            'unknown classifications default to non critical' => ['unknown', 'foreign_primary'],
        ];
    }
}