<?php

namespace App\\Services;

use Illuminate\\Http\\UploadedFile;
use RuntimeException;
use ZipArchive;

class LocalFileInspector
{
    private const MAX_ARCHIVE_ENTRIES = 1000;
    private const MAX_ARCHIVE_UNPACKED_BYTES = 268435456;
    private const MAX_ENTRY_BYTES = 67108864;

    public function inspect(UploadedFile $upload): array
    {
        $path = $upload->getRealPath();
        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('intake_source_unavailable');
        }

        $size = (int) $upload->getSize();
        if ($size < 1 || $size > 50 * 1024 * 1024) {
            throw new RuntimeException('intake_size_out_of_range');
        }

        $originalName = trim(str_replace(["\\0", "\\r", "\\n"], '', (string) $upload->getClientOriginalName()));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = strtolower((string) (new \\finfo(FILEINFO_MIME_TYPE))->file($path));
        $sha256 = hash_file('sha256', $path);
        if (! is_string($sha256)) {
            throw new RuntimeException('intake_checksum_failed');
        }

        $details = [
            'original_name' => mb_substr($originalName, 0, 255),
            'extension' => $extension,
            'mime' => $mime,
            'size_bytes' => $size,
            'size_human' => $this->humanSize($size),
            'sha256' => $sha256,
            'detected_type' => 'unknown',
            'page_count' => null,
            'word_count' => null,
            'image' => null,
            'archive' => null,
            'document' => [],
            'warnings' => [],
        ];

        $header = file_get_contents($path, false, null, 0, 16);
        $header = is_string($header) ? $header : '';

        if (str_starts_with($header, '%PDF-')) {
            $details['detected_type'] = 'pdf';
            $this->inspectPdf($path, $details);
        } elseif (in_array($extension, ['zip','docx','xlsx','pptx'], true) || str_starts_with($header, "PK\\x03\\x04")) {
            $details['archive'] = $this->inspectZip($path);
            $details['detected_type'] = $this->detectOfficeType($extension, $details['archive']['entries']);
            if ($details['detected_type'] === 'zip') {
                $details['document'] = ['entry_count' => $details['archive']['entry_count']];
            } else {
                $this->inspectOfficeMetadata($path, $details);
            }
        } elseif (in_array($extension, ['jpg','jpeg','png','webp','gif'], true)) {
            $details['detected_type'] = 'image';
            $image = @getimagesize($path);
            if (! is_array($image)) {
                throw new RuntimeException('intake_invalid_image');
            }
            $details['image'] = ['width' => (int) $image[0], 'height' => (int) $image[1], 'mime' => (string) ($image['mime'] ?? $mime)];
            if ($image[0] * $image[1] > 50000000) {
                throw new RuntimeException('intake_image_dimensions_too_large');
            }
        } elseif (in_array($extension, ['txt','md','csv'], true) && str_starts_with($mime, 'text/')) {
            $details['detected_type'] = 'text';
            $sample = file_get_contents($path, false, null, 0, min($size, 1048576));
            $details['document'] = [
                'characters_sampled' => is_string($sample) ? mb_strlen($sample, 'UTF-8') : 0,
                'encoding_valid' => is_string($sample) && mb_check_encoding($sample, 'UTF-8'),
                'lines_sampled' => is_string($sample) ? substr_count($sample, "\\n") + 1 : 0,
            ];
            if (! $details['document']['encoding_valid']) {
                $details['warnings'][] = 'بخشی از متن با UTF-8 معتبر نیست؛ تبدیل نویسه‌ها در مرحله پردازش بررسی می‌شود.';
            }
        } elseif (str_starts_with($mime, 'audio/')) {
            $details['detected_type'] = 'audio';
            $details['document'] = ['duration_ms' => null, 'duration_note' => 'مدت دقیق در مرحله پردازش رسانه تعیین می‌شود.'];
        } elseif (str_starts_with($mime, 'video/')) {
            $details['detected_type'] = 'video';
            $details['document'] = ['duration_ms' => null, 'duration_note' => 'مدت دقیق در مرحله پردازش رسانه تعیین می‌شود.'];
        } else {
            throw new RuntimeException('intake_unsupported_format');
        }

        return $details;
    }

    private function inspectPdf(string $path, array &$details): void
    {
        $bytes = file_get_contents($path);
        if (! is_string($bytes) || strlen($bytes) > 50 * 1024 * 1024) {
            throw new RuntimeException('intake_pdf_read_failed');
        }
        preg_match_all('/\\/Type\\s*\\/Page\\b/', $bytes, $matches);
        $details['page_count'] = count($matches[0]);
        $details['document'] = [
            'encrypted' => preg_match('/\\/Encrypt\\b/', $bytes) === 1,
            'has_outline' => str_contains($bytes, '/Outlines'),
            'has_forms' => str_contains($bytes, '/AcroForm'),
            'has_embedded_files' => str_contains($bytes, '/EmbeddedFiles'),
            'has_javascript' => preg_match('/\\/(?:JavaScript|JS)\\b/i', $bytes) === 1,
            'has_annotations' => str_contains($bytes, '/Annots'),
        ];
        if ($details['document']['encrypted']) {
            $details['warnings'][] = 'سند رمزگذاری شده است؛ بررسی کامل محتوا ممکن است به رمز نیاز داشته باشد.';
        }
        if ($details['document']['has_javascript'] || $details['document']['has_embedded_files']) {
            $details['warnings'][] = 'ساختار PDF دارای محتوای فعال یا فایل تعبیه‌شده است و در تأیید امنیتی جداگانه بررسی می‌شود.';
        }
        if ($details['page_count'] < 1) {
            $details['warnings'][] = 'تعداد صفحات از ساختار اولیه PDF قابل تشخیص قطعی نبود.';
        }
    }

    private function inspectZip(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('intake_zip_support_unavailable');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('intake_invalid_archive');
        }

        $entries = [];
        $total = 0;
        try {
            if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                throw new RuntimeException('intake_archive_entry_limit');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    throw new RuntimeException('intake_archive_entry_invalid');
                }
                $name = str_replace('\\\\', '/', (string) ($stat['name'] ?? ''));
                if ($name === '' || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) || in_array('..', explode('/', $name), true)) {
                    throw new RuntimeException('intake_archive_unsafe_path');
                }
                $size = (int) ($stat['size'] ?? 0);
                $compressed = (int) ($stat['comp_size'] ?? 0);
                if ($size > self::MAX_ENTRY_BYTES) {
                    throw new RuntimeException('intake_archive_entry_too_large');
                }
                $total += $size;
                if ($total > self::MAX_ARCHIVE_UNPACKED_BYTES) {
                    throw new RuntimeException('intake_archive_expansion_limit');
                }
                if ($size > 0 && ($compressed === 0 || $size / max(1, $compressed) > 100)) {
                    throw new RuntimeException('intake_archive_suspicious_ratio');
                }
                $entries[] = [
                    'name' => mb_substr($name, 0, 240),
                    'size_bytes' => $size,
                    'compressed_bytes' => $compressed,
                    'is_directory' => str_ends_with($name, '/'),
                ];
            }
        } finally {
            $zip->close();
        }

        return [
            'entry_count' => count($entries),
            'uncompressed_bytes' => $total,
            'uncompressed_human' => $this->humanSize($total),
            'entries' => array_slice($entries, 0, 200),
            'truncated' => count($entries) > 200,
        ];
    }

    private function detectOfficeType(string $extension, array $entries): string
    {
        $names = array_column($entries, 'name');
        if (in_array('[Content_Types].xml', $names, true)) {
            if (in_array('word/document.xml', $names, true)) return 'docx';
            if (in_array('xl/workbook.xml', $names, true)) return 'xlsx';
            if (in_array('ppt/presentation.xml', $names, true)) return 'pptx';
        }
        if ($extension === 'zip') return 'zip';
        throw new RuntimeException('intake_office_signature_mismatch');
    }

    private function inspectOfficeMetadata(string $path, array &$details): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('intake_office_container_invalid');
        }
        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (is_array($stat)) $entries[] = (string) $stat['name'];
            }

            if ($details['detected_type'] === 'docx') {
                $xml = $zip->getFromName('word/document.xml');
                $details['document'] = [
                    'paragraphs' => is_string($xml) ? preg_match_all('/<w:p(?:\\s|>)/', $xml) : 0,
                    'tables' => is_string($xml) ? preg_match_all('/<w:tbl(?:\\s|>)/', $xml) : 0,
                    'images' => count(array_filter($entries, fn ($name) => str_starts_with($name, 'word/media/'))),
                    'has_comments' => in_array('word/comments.xml', $entries, true),
                    'has_macros' => in_array('word/vbaProject.bin', $entries, true),
                ];
            } elseif ($details['detected_type'] === 'xlsx') {
                $xml = $zip->getFromName('xl/workbook.xml');
                $details['document'] = [
                    'sheets' => is_string($xml) ? preg_match_all('/<sheet\\b/', $xml) : 0,
                    'charts' => count(array_filter($entries, fn ($name) => str_starts_with($name, 'xl/charts/'))),
                    'images' => count(array_filter($entries, fn ($name) => str_starts_with($name, 'xl/media/'))),
                ];
            } else {
                $details['document'] = [
                    'slides' => count(array_filter($entries, fn ($name) => preg_match('#^ppt/slides/slide[0-9]+\\.xml$#', $name))),
                    'images' => count(array_filter($entries, fn ($name) => str_starts_with($name, 'ppt/media/'))),
                    'charts' => count(array_filter($entries, fn ($name) => str_starts_with($name, 'ppt/charts/'))),
                ];
            }
        } finally {
            $zip->close();
        }
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2).' MB';
        return number_format($bytes / 1024, 1).' KB';
    }
}
