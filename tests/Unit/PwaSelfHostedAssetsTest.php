<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PwaSelfHostedAssetsTest extends TestCase
{
    public function test_app_shell_uses_self_hosted_typography_and_icon_assets(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = file_get_contents($root.'/resources/views/layouts/app.blade.php');

        self::assertIsString($layout);
        self::assertStringNotContainsString('cdnjs.cloudflare.com', $layout);
        self::assertStringNotContainsString('cdn.jsdelivr.net', $layout);
        self::assertStringContainsString('/css/vazirmatn.css', $layout);
        self::assertStringContainsString('/css/fontawesome.css', $layout);
        self::assertStringContainsString('/fonts/vazirmatn-variable.woff2', $layout);
    }

    public function test_service_worker_precaches_existing_local_shell_assets(): void
    {
        $root = dirname(__DIR__, 2);
        $worker = file_get_contents($root.'/public/sw.js');

        self::assertIsString($worker);
        self::assertMatchesRegularExpression('/const CORE_ASSETS = \[(.*?)\];/s', $worker);
        preg_match('/const CORE_ASSETS = \[(.*?)\];/s', $worker, $matches);
        preg_match_all("/'([^']+)'/", $matches[1], $assets);

        $assetPaths = array_map(
            static fn (string $asset): string => (string) parse_url($asset, PHP_URL_PATH),
            $assets[1]
        );

        foreach ($assetPaths as $path) {
            self::assertStringStartsWith('/', $path);
            self::assertFileExists($root.'/public'.$path, 'Missing precached asset: '.$path);
        }

        foreach ([
            '/css/vazirmatn.css',
            '/css/fontawesome.css',
            '/fonts/vazirmatn-variable.woff2',
            '/fonts/fontawesome/fa-solid-900.woff2',
            '/fonts/fontawesome/fa-regular-400.woff2',
            '/fonts/fontawesome/fa-brands-400.woff2',
            '/fonts/fontawesome/fa-v4compatibility.woff2',
        ] as $required) {
            self::assertContains($required, $assetPaths);
        }
    }

    public function test_self_hosted_font_stylesheets_resolve_to_local_font_files(): void
    {
        $root = dirname(__DIR__, 2);
        $fontAwesome = file_get_contents($root.'/public/css/fontawesome.css');
        $vazirmatn = file_get_contents($root.'/public/css/vazirmatn.css');

        self::assertIsString($fontAwesome);
        self::assertIsString($vazirmatn);
        self::assertStringContainsString('../fonts/fontawesome/fa-solid-900.woff2', $fontAwesome);
        preg_match_all('/url\\(([^)]+)\\)/', $fontAwesome, $fontUrls);
        foreach ($fontUrls[1] as $fontUrl) {
            $fontPath = (string) parse_url($fontUrl, PHP_URL_PATH);
            self::assertStringStartsWith('../fonts/fontawesome/', $fontPath);
            self::assertFileExists(dirname($root.'/public/css/fontawesome.css').'/'.$fontPath);
        }
        self::assertStringContainsString('/fonts/vazirmatn-variable.woff2', $vazirmatn);
    }
}
