<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

echo 'PHP: '.PHP_VERSION.PHP_EOL;

if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    $failures[] = 'PHP 8.2 or newer is required.';
}

$requiredExtensions = [
    'mbstring',
    'dom',
    'fileinfo',
    'pdo_sqlite',
    'gd',
    'zip',
    'curl',
    'xml',
];

foreach ($requiredExtensions as $extension) {
    if (! extension_loaded($extension)) {
        $failures[] = 'Missing required PHP extension: '.$extension;
    }
}

$autoload = $root.'/vendor/autoload.php';
if (! is_file($autoload)) {
    $failures[] = 'Composer dependencies are missing. Run: composer install';
} else {
    require_once $autoload;
}

$phpunitCandidates = [
    $root.'/vendor/bin/phpunit',
    $root.'/vendor/bin/phpunit.bat',
];

if (! array_filter($phpunitCandidates, 'is_file')) {
    $failures[] = 'PHPUnit is missing. Install development dependencies with composer install (do not use --no-dev).';
}

if (is_file($autoload) && ! class_exists(Tests\TestCase::class)) {
    $failures[] = 'Tests\\TestCase is not autoloadable. Check Composer PSR-4 mapping and install require-dev dependencies.';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, '[FAIL] '.$failure.PHP_EOL);
    }

    exit(1);
}

echo 'Required PHP extensions: OK'.PHP_EOL;
echo 'Composer autoload: OK'.PHP_EOL;
echo 'PHPUnit development binary: OK'.PHP_EOL;
echo 'Tests\\TestCase autoload: OK'.PHP_EOL;
