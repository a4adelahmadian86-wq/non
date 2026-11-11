<?php

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;

class CustomizeJsonLogging
{
    public function __invoke($logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter(new JsonFormatter());
        }
    }
}
