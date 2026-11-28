<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReaderToolbarIconographyTest extends TestCase
{
    public function test_reader_uses_bundled_fontawesome_assets_and_keeps_toolbar_icons_visible(): void
    {
        $readerStylesheet = public_path('css/farast-reader.css');
        $fontAwesomeStylesheet = public_path('css/fontawesome.css');
        $readerView = resource_path('views/store/reader.blade.php');

        $this->assertFileExists($readerStylesheet);
        $this->assertFileExists($fontAwesomeStylesheet);
        $this->assertFileExists(public_path('fonts/fontawesome/fa-solid-900.woff2'));
        $this->assertFileExists(public_path('fonts/fontawesome/fa-regular-400.woff2'));

        $readerCss = file_get_contents($readerStylesheet);
        $fontAwesomeCss = file_get_contents($fontAwesomeStylesheet);
        $readerBlade = file_get_contents($readerView);

        $this->assertIsString($readerCss);
        $this->assertIsString($fontAwesomeCss);
        $this->assertIsString($readerBlade);

        $localImport = "@import url('/css/fontawesome.css?v=20261115local1');";
        $this->assertStringContainsString($localImport, $readerCss);
        $this->assertLessThan(
            strpos($readerCss, 'html,body'),
            strpos($readerCss, $localImport),
            'The local icon stylesheet import must precede Reader CSS rules.'
        );
        $this->assertStringContainsString('fa-solid-900.woff2', $fontAwesomeCss);
        $this->assertStringContainsString('fa-regular-400.woff2', $fontAwesomeCss);
        $this->assertStringContainsString('href="/css/farast-reader.css', $readerBlade);

        $this->assertStringNotContainsString(
            '.farast-reader i.fa-solid,.farast-reader i.fa-regular{display:none!important}',
            $readerCss
        );
        $this->assertStringNotContainsString(
            '.farast-reader .reader-tool[aria-label]:not(.reader-tool-wide)::after',
            $readerCss
        );
        $this->assertStringNotContainsString(
            '.farast-reader .reader-tool[aria-label]:not(.reader-tool-wide){width:auto;min-width:34px;padding-inline:8px}',
            $readerCss
        );

        $this->assertStringContainsString('.reader-tool:hover,.reader-tool:focus-visible', $readerCss);
        $this->assertStringContainsString('outline:2px solid #568cf0', $readerCss);
    }
}
