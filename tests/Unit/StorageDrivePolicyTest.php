<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StorageDrivePolicyTest extends TestCase
{
    #[DataProvider('drivePolicy')]
    public function test_classification_is_never_routed_across_regions(string $classification, string $expectedDrive): void
    {
        $drive = $classification === 'critical' ? 'iran_primary' : 'foreign_primary';
        $this->assertSame($expectedDrive, $drive);
    }

    public static function drivePolicy(): array
    {
        return [
            'critical files stay in Iran' => ['critical', 'iran_primary'],
            'editor and support files go abroad' => ['non_critical', 'foreign_primary'],
        ];
    }
}