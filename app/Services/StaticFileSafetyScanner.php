<?php

namespace App\\Services;

use RuntimeException;
use ZipArchive;

class StaticFileSafetyScanner
{
    private const MAX_ARCHIVE_ENTRIES = 1000;
    private const MAX_ARCHIVE_UNPACKED_BYTES = 268435456;
    private const MAX_SINGLE_ENTRY_BYTES = 67108864;
    private const MAX_COMPRESSION_RATIO = 100;

    private const BLOCKED_EXTENSIONS = [
        'php','phtml','phar','exe','dll','so','dylib','msi','bat','cmd','ps1','sh','bash',
        'com','scr','jar','apk','app','vbs','vbe','js','mjs','cjs','hta','html','htm',
        'svg','docm','dotm','xlsm','xltm','pptm','potm','ppam','xlam',
    ];

    private const EXECUTABLE_SIGNATURES = [
        "MZ" => 'windows_executable',
        "\x7FELF" => 'elf_executable',
        "\xFE\xED\xFA\xCE" => 'mach_o_executable',
        "\xFE\xED\xFA\xCF" => 'mach_o_executable',
        "\xCE\xFA\xED\xFE" => 'mach_o_executable',
        "\xCF\xFA\xED\xFE" => 'mach_o_executable',
        "#!" => 'script_executable',
    ];

    public function scan(string $absolutePath, string $mime, string $extension): array
    {
        if (! is_file($absolutePath) || is_link($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException('static_scan_file_unavailable');
        }

        $size = filesize($absolutePath);
        if (! is_int($size) || $size < 1) {
            throw new RuntimeException('static_scan_empty_file');
        }

        $extension = strtolower(ltrim($extension, '.'));
        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw new RuntimeException('static_scan_blocked_extension');
        }

        $handle = fopen($absolutePath, 'rb');
        if (! is_resource($handle)) {
            throw new RuntimeException('static_scan_read_failed');
        }

        try {
            $header = (string) fread($handle, 16384);
        } finally {
            fclose($handle);
        }

        foreach (self::EXECUTABLE_SIGNATURES as $signature => $reason) {
            if (str_starts_with($header, $signature)) {
                throw new RuntimeException('static_scan_'.$reason);
            }
        }

        if (str_contains($header, "\0<?php") || preg_match('/<\\?(?:php|=)/i', $header)) {
            throw new RuntimeException('static_scan_embedded_php');
        }

        $findings = [];
        $archive = $this->inspectZipIfApplicable($absolutePath, $extension);
        if ($archive !== null) {
            $findings = array_merge($findings, $archive['findings']);
        }

        if ($mime === 'application/pdf' || $extension === 'pdf') {
            $this->inspectPdf($absolutePath);
        }

        if (in_array($extension, ['docx','xlsx','pptx'], true)) {
            $this->inspectOfficeArchive($archive, $extension);
        }

        $hash = hash_file('sha256', $absolutePath);
        if (! is_string($hash)) {
            throw new RuntimeException('static_scan_checksum_failed');
        }

        return [
            'safe' => true,
            'status' => 'clean',
            'engine' => 'farast-static-policy-v1',
            'sha256' => $hash,
            'size_bytes' => $size,
            'mime' => $mime,
            'findings' => $findings,
            'disclaimer' => 'Static policy checks passed; this is not a signature-based antivirus guarantee.',
        ];
    }

    private function inspectZipIfApplicable(string $path, string $extension): ?array
    {
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            throw new RuntimeException('static_scan_read_failed');
        }
        try {
            $signature = fread($handle, 4);
        } finally {
            fclose($handle);
        }

        $isZip = in_array($signature, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
        if (! $isZip && ! in_array($extension, ['zip','docx','xlsx','pptx'], true)) {
            return null;
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('static_scan_zip_support_unavailable');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('static_scan_invalid_archive');
        }

        $findings = [];
        $entries = [];
        $total = 0;
        try {
            if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                throw new RuntimeException('static_scan_archive_entry_limit');
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    throw new RuntimeException('static_scan_archive_entry_invalid');
                }

                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                $entries[] = $name;
                $parts = explode('/', $name);
                if ($name === '' || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) || in_array('..', $parts, true)) {
                    throw new RuntimeException('static_scan_archive_path_traversal');
                }

                $size = (int) ($stat['size'] ?? 0);
                $compressed = (int) ($stat['comp_size'] ?? 0);
                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $opsys, $attributes) && $opsys === ZipArchive::OPSYS_UNIX) {
                    $fileType = ($attributes >> 16) & 0170000;
                    if ($fileType === 0120000) {
                        throw new RuntimeException('static_scan_archive_symlink');
                    }
                }
                if ($size > 0 && ! str_ends_with($name, '/')) {
                    $entryStream = $zip->getStream($name);
                    if (is_resource($entryStream)) {
                        try {
                            $entryHeader = (string) fread($entryStream, 16);
                        } finally {
                            fclose($entryStream);
                        }
                        foreach (self::EXECUTABLE_SIGNATURES as $signature => $reason) {
                            if (str_starts_with($entryHeader, $signature)) {
                                throw new RuntimeException('static_scan_archive_'.$reason);
                            }
                        }
                    }
                }
                if ($size > self::MAX_SINGLE_ENTRY_BYTES) {
                    throw new RuntimeException('static_scan_archive_entry_too_large');
                }
                $total += $size;
                if ($total > self::MAX_ARCHIVE_UNPACKED_BYTES) {
                    throw new RuntimeException('static_scan_archive_expansion_limit');
                }
                if ($size > 0 && ($compressed === 0 || $size / max(1, $compressed) > self::MAX_COMPRESSION_RATIO)) {
                    throw new RuntimeException('static_scan_archive_suspicious_ratio');
                }

                $entryExtension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($entryExtension, self::BLOCKED_EXTENSIONS, true)) {
                    throw new RuntimeException('static_scan_archive_executable_entry');
                }

                if (preg_match('/(^|\\/)(?:vbaProject\\.bin|activeX|embeddings)\\b/i', $name)) {
                    $findings[] = 'office_active_content_or_embedded_object';
                }
                if (preg_match('/(^|\\/)(?:externalLinks|connections)\\//i', $name)) {
                    $findings[] = 'office_external_relationships_present';
                }
            }
        } finally {
            $zip->close();
        }

        return ['findings' => array_values(array_unique($findings)), 'entries' => $entries ?? []];
    }

    private function inspectOfficeArchive(?array $archive, string $extension): void
    {
        if ($archive === null) {
            throw new RuntimeException('static_scan_office_container_invalid');
        }

        $entries = $archive['entries'] ?? [];
        $required = match ($extension) {
            'docx' => ['[Content_Types].xml', 'word/document.xml'],
            'xlsx' => ['[Content_Types].xml', 'xl/workbook.xml'],
            'pptx' => ['[Content_Types].xml', 'ppt/presentation.xml'],
            default => [],
        };

        foreach ($required as $name) {
            if (! in_array($name, $entries, true)) {
                throw new RuntimeException('static_scan_office_container_invalid');
            }
        }

        foreach ($entries as $name) {
            $lower = strtolower($name);
            if (str_ends_with($lower, 'vbaproject.bin') || str_starts_with($lower, 'word/activex/') || str_starts_with($lower, 'xl/activex/') || str_starts_with($lower, 'ppt/activex/')) {
                throw new RuntimeException('static_scan_office_active_content');
            }
        }
    }

    private function inspectPdf(string $path): void
    {
        $size = filesize($path);
        if (! is_int($size) || $size > 50 * 1024 * 1024) {
            throw new RuntimeException('static_scan_pdf_size_limit');
        }

        $bytes = file_get_contents($path);
        if (! is_string($bytes) || ! str_starts_with(ltrim($bytes), '%PDF-')) {
            throw new RuntimeException('static_scan_pdf_signature_mismatch');
        }

        foreach (['/JavaScript','/JS','/Launch','/EmbeddedFile','/RichMedia','/OpenAction','/AA','/XFA'] as $token) {
            if (stripos($bytes, $token) !== false) {
                throw new RuntimeException('static_scan_pdf_active_content');
            }
        }
    }
}
