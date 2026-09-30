<?php
namespace Tests\Unit;

use App\Services\ApplicationRegistry;
use Tests\TestCase;

class ApplicationRegistryTest extends TestCase
{
    public function test_future_project_types_have_stable_application_classification(): void
    {
        $registry = app(ApplicationRegistry::class);

        $this->assertSame('word_processor', $registry->applicationFor('article'));
        $this->assertSame('presentation', $registry->applicationFor('presentation'));
        $this->assertFalse($registry->canStart('presentation'));
        $this->assertTrue($registry->canStart('typing'));
    }
}
