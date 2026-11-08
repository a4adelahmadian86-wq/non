<?php

namespace App\Services;

use App\Http\Controllers\StoreController;
use App\Models\StoreProductDocument;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProductDocumentConversionService
{
    public function prepare(StoreProductDocument $document): array
    {
        $extension = strtolower((string) $document->extension);
        $source = [
            'disk' => $document->storage_disk,
            'path' => $document->storage_path,
            'mime' => $document->detected_mime ?: 'application/octet-stream',
            'kind' => $this->readerKind($extension),
            'converted' => false,
        ];

        if (in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'mp3', 'm4a', 'wav', 'ogg', 'mp4', 'mov', 'webm'], true)) {
            $document->update([
                'reader_asset_disk' => $source['disk'],
                'reader_asset_path' => $source['path'],
                'reader_asset_mime' => $source['mime'],
                'reader_asset_kind' => $source['kind'],
            ]);
            return $source;
        }

        try {
            $bytes = Storage::disk($document->storage_disk)->get($document->storage_path);
            if (! is_string($bytes) || $bytes === '') throw new RuntimeException('document_source_read_failed');
            $pdf = $this->toPdf($extension, $bytes);
            if (! is_string($pdf) || ! str_starts_with($pdf, '%PDF-') || strlen($pdf) > 50 * 1024 * 1024) {
                throw new RuntimeException('document_conversion_output_invalid');
            }

            $diskName = 'private';
            $path = 'store/product-derived/'.$document->product_id.'/'.$document->id.'-v'.$document->content_version.'.pdf';
            if (! Storage::disk($diskName)->put($path, $pdf)) throw new RuntimeException('document_conversion_storage_failed');

            $result = [
                'disk' => $diskName,
                'path' => $path,
                'mime' => 'application/pdf',
                'kind' => 'pdf',
                'converted' => true,
            ];
            $metadata = (array) $document->technical_metadata;
            $metadata['reader_conversion'] = [
                'status' => 'completed',
                'source_extension' => $extension,
                'engine' => $extension === 'docx' ? 'phpword-dompdf' : ($extension === 'xlsx' ? 'farast-xlsx-renderer-dompdf' : 'dompdf-text-renderer'),
            ];
            $document->update([
                'reader_asset_disk' => $diskName,
                'reader_asset_path' => $path,
                'reader_asset_mime' => 'application/pdf',
                'reader_asset_kind' => 'pdf',
                'technical_metadata' => $metadata,
            ]);
            return $result;
        } catch (Throwable $exception) {
            $metadata = (array) $document->technical_metadata;
            $metadata['reader_conversion'] = [
                'status' => 'unavailable',
                'source_extension' => $extension,
                'reason' => class_basename($exception),
            ];
            $document->update(['technical_metadata' => $metadata]);
            return $source;
        }
    }

    public function toPdf(string $extension, string $bytes): ?string
    {
        if (! class_exists(Dompdf::class)) return null;

        if ($extension === 'docx') {
            if (! class_exists(\PhpOffice\PhpWord\IOFactory::class) || ! class_exists(\ZipArchive::class)) return null;
            return app(StoreController::class)->prepareDocxPreview($bytes);
        }

        if ($extension === 'xlsx') {
            if (! class_exists(\ZipArchive::class)) return null;
            $html = app(StoreXlsxPreviewRenderer::class)->render($bytes, '');
            return $this->renderHtml($html);
        }

        if (in_array($extension, ['txt', 'md'], true)) {
            $text = mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
            $html = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11pt;line-height:2;direction:rtl}pre{white-space:pre-wrap;overflow-wrap:anywhere}</style></head><body><pre>'
                .htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre></body></html>';
            return $this->renderHtml($html);
        }

        if ($extension === 'csv') {
            $handle = fopen('php://temp', 'w+b');
            if (! is_resource($handle)) return null;
            fwrite($handle, $bytes);
            rewind($handle);
            $rows = [];
            while (($row = fgetcsv($handle, 0, ',')) !== false && count($rows) < 2000) {
                $rows[] = array_slice($row, 0, 40);
            }
            fclose($handle);
            if ($rows === []) return null;
            $html = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:9pt}table{border-collapse:collapse;width:100%}td,th{border:1px solid #cbd5e1;padding:5px;text-align:right;overflow-wrap:anywhere}</style></head><body><table>';
            foreach ($rows as $index => $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $tag = $index === 0 ? 'th' : 'td';
                    $html .= '<'.$tag.'>'.htmlspecialchars((string) $cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</'.$tag.'>';
                }
                $html .= '</tr>';
            }
            $html .= '</table></body></html>';
            return $this->renderHtml($html);
        }

        return null;
    }

    private function renderHtml(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    private function readerKind(string $extension): ?string
    {
        if ($extension === 'pdf') return 'pdf';
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) return 'image';
        if (in_array($extension, ['mp3', 'm4a', 'wav', 'ogg'], true)) return 'audio';
        if (in_array($extension, ['mp4', 'mov', 'webm'], true)) return 'video';
        return null;
    }
}
