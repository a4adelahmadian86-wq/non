<?php

namespace Tests\Unit;

use App\Services\Storage\StorageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StorageClassificationTest extends TestCase
{
    #[DataProvider('classificationCases')]
    public function test_storage_classification_selects_the_expected_primary_drive(string $classification, string $expected): void
    {
        $drive = $classification === StorageManager::CRITICAL ? 'iran_primary' : 'foreign_primary';
        $this->assertSame($expected, $drive);
    }

    public static function classificationCases(): array
    {
        return [
            'private user content' => [StorageManager::CRITICAL, 'iran_primary'],
            'public product asset' => [StorageManager::NON_CRITICAL, 'foreign_primary'],
        ];
    }
}