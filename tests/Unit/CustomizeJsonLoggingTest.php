<?php

namespace Tests\Unit;

use App\Logging\CustomizeJsonLogging;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class CustomizeJsonLoggingTest extends TestCase
{
    public function test_it_applies_json_formatting_to_each_handler(): void
    {
        $logger = new Logger('farast-test');
        $first = new StreamHandler('php://temp');
        $second = new StreamHandler('php://temp');
        $logger->pushHandler($first);
        $logger->pushHandler($second);

        (new CustomizeJsonLogging())($logger);

        self::assertInstanceOf(JsonFormatter::class, $first->getFormatter());
        self::assertInstanceOf(JsonFormatter::class, $second->getFormatter());
    }
}
