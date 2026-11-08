<?php

namespace App\Services;

use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

class StoreProductSampleBuilder
{
    /**
     * The model selects source page numbers only. Laravel builds the immutable
     * sample derivative locally and stores it once against the source checksum.
     */
    public function build(string $sourcePdf, array $requestedPages, int $maxPages = 3, int $maxBytes = 8 * 1024 * 1024): ?array
    {
        if ($sourcePdf === '' || ! str_starts_with($sourcePdf, '%PDF-')) {
            throw new RuntimeException('product_sample_source_invalid');
        }

        if (! class_exists(Fpdi::class) || ! class_exists(StreamReader::class)) {
            throw new RuntimeException('product_sample_engine_unavailable');
        }

        $reader = new Fpdi();
        try {
            $pageCount = (int) $reader->setSourceFile(StreamReader::createByString($sourcePdf));
        } catch (\Throwable) {
            throw new RuntimeException('product_sample_source_unreadable');
        }

        if ($pageCount < 1 || $pageCount > 5000) {
            throw new RuntimeException('product_sample_page_count_invalid');
        }

        $selected = [];
        foreach (array_slice($requestedPages, 0, 20) as $candidate) {
            if (! is_int($candidate) && !(is_string($candidate) && ctype_digit($candidate))) {
                continue;
            }
            $page = (int) $candidate;
            if ($page >= 1 && $page <= $pageCount && ! in_array($page, $selected, true)) {
                $selected[] = $page;
            }
            if (count($selected) >= max(1, min(10, $maxPages))) {
                break;
            }
        }

        if ($selected === []) {
            return null;
        }

        $output = new Fpdi();
        foreach ($selected as $pageNumber) {
            $template = $output->importPage($pageNumber);
            $size = $output->getTemplateSize($template);
            $output->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $output->useTemplate($template);
        }

        $bytes = $output->Output('S');
        if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF-') || strlen($bytes) > $maxBytes) {
            throw new RuntimeException('product_sample_output_invalid');
        }

        return [
            'bytes' => $bytes,
            'selected_pages' => $selected,
            'source_page_count' => $pageCount,
            'included_page_count' => count($selected),
            'sha256' => hash('sha256', $bytes),
        ];
    }
}
