<?php

namespace App\Services;

use RuntimeException;
use setasign\Fpdi\Fpdi;
use Throwable;

class StorePdfPreviewPreparer
{
    private const MAX_SOURCE_BYTES = 20 * 1024 * 1024;
    private const MAX_SOURCE_PAGES = 5000;
    private const MAX_SAMPLE_PAGES = 50;

    /**
     * Produce a new PDF containing only the approved leading sample pages.
     * The original document is never returned by the reader sample endpoint.
     *
     * @return array{bytes:string,source_page_count:int,included_page_count:int}
     */
    public function prepareSample(string $bytes, int $pageLimit): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('PDF sample source is empty or exceeds the upload limit.');
        }

        $sourcePath = tempnam(sys_get_temp_dir(), 'farast-pdf-source-');
        if ($sourcePath === false) {
            throw new RuntimeException('Unable to allocate temporary PDF storage.');
        }

        try {
            if (file_put_contents($sourcePath, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('Unable to stage PDF sample source.');
            }

            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($sourcePath);
            if ($pageCount < 1 || $pageCount > self::MAX_SOURCE_PAGES) {
                throw new RuntimeException('PDF page count is outside supported limits.');
            }

            $includedPages = min($pageCount, max(1, min(self::MAX_SAMPLE_PAGES, $pageLimit)));
            $pdf->SetCreator('FARAST protected reader');
            $pdf->SetTitle('FARAST sample preview');
            $pdf->SetSubject('Restricted sample pages');
            $pdf->SetCompression(true);

            for ($pageNumber = 1; $pageNumber <= $includedPages; $pageNumber++) {
                $template = $pdf->importPage($pageNumber, \setasign\Fpdi\PdfReader\PageBoundaries::CROP_BOX, true, false);
                $size = $pdf->getTemplateSize($template);
                $orientation = $size['orientation'] ?? ($size['width'] > $size['height'] ? 'L' : 'P');
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }

            $prepared = $pdf->Output('S');
            if (!is_string($prepared) || !str_starts_with($prepared, '%PDF-') || strlen($prepared) < 500) {
                throw new RuntimeException('PDF sample generation returned an invalid document.');
            }

            return [
                'bytes' => $prepared,
                'source_page_count' => $pageCount,
                'included_page_count' => $includedPages,
            ];
        } catch (Throwable $exception) {
            throw new RuntimeException('PDF could not be safely isolated into sample pages.', 0, $exception);
        } finally {
            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }
        }
    }
}
