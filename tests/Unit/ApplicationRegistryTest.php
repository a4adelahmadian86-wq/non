<?php

namespace Tests\Unit;

use App\Services\ApplicationRegistry;
use PHPUnit\Framework\TestCase;

class ApplicationRegistryTest extends TestCase
{
    public function test_word_processor_future_types_are_classified_without_being_activated(): void
    {
        $registry = new ApplicationRegistry();

        $this->assertSame('word_processor', $registry->applicationFor('report'));
        $this->assertSame('word_processor', $registry->applicationFor('research_document'));
        $this->assertFalse($registry->canStart('report'));
        $this->assertFalse($registry->canStart('research_document'));
    }

    public function test_typing_is_the_only_initially_startable_word_processor_project(): void
    {
        $registry = new ApplicationRegistry();

        $this->assertSame('word_processor', $registry->applicationFor('typing'));
        $this->assertTrue($registry->canStart('typing'));
    }
}
