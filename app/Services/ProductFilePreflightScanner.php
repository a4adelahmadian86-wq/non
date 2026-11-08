<?php

namespace App\Services;

use RuntimeException;

class ProductFilePreflightScanner
{
    public const ENGINE = 'farast-structural-v1';
    public const MAX_BYTES = 50 * 1024 * 1024;
    public const MAX_ARCHIVE_ENTRIES = 2000;
    public const MAX_EXPANDED_BYTES = 500 * 1024 * 1024;

    private const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'md', 'jpg', 'jpeg', 'png', 'webp', 'gif',
        'mp3', 'm4a', 'wav', 'ogg', 'mp4', 'webm', 'mov',
        'zip', 'tar', 'gz', 'tgz',
    ];

    private const DANGEROUS_EXTENSIONS = [
        'php', 'phtml', 'phar', 'exe', 'dll', 'so', 'dylib', 'com', 'scr',
        'js', 'mjs', 'cjs', 'html', 'htm', 'svg', 'sh', 'bash', 'bat',
        'cmd', 'ps1', 'vbs', 'hta', 'jar', 'msi', 'apk', 'app', 'deb', 'rpm',
    ];

    public function inspect(string $absolutePath, string $originalName, ?string $clientMime = null): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException('preflight_file_unreadable');
        }

        $size = (int) filesize($absolutePath);
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new RuntimeException($size > self::MAX_BYTES ? 'preflight_file_too_large' : 'preflight_empty_file');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (str_ends_with(strtolower($originalName), '.tar.gz')) {
            $extension = 'tgz';
        }
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('preflight_extension_not_allowed');
        }
        if (in_array($extension, self::DANGEROUS_EXTENSIONS, true)) {
            throw new RuntimeException('preflight_dangerous_extension');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = strtolower((string) $finfo->file($absolutePath));
        $prefix = file_get_contents($absolutePath, false, null, 0, min($size, 8192));
        if (! is_string($prefix)) {
            throw new RuntimeException('preflight_signature_read_failed');
        }

        $kind = $this->detectKind($extension, $detectedMime, $prefix);
        $warnings = [];
        $details = [
            'kind' => $kind,
            'extension' => $extension,
            'detected_mime' => $detectedMime,
            'client_mime' => $clientMime,
            'size_bytes' => $size,
            'size_label' => $this->formatBytes($size),
        ];

        if (in_array($kind, ['zip', 'office'], true)) {
            $archive = $this->inspectZip($absolutePath, $size);
            $details['archive'] = $archive;
            if ($archive['suspicious'] !== []) {
                throw new RuntimeException('preflight_archive_suspicious:'.implode(',', $archive['suspicious']));
            }
            if ($archive['nested_archives'] > 0) {
                $warnings[] = 'این آرشیو شامل آرشیو تو‌در‌تو است؛ محتوای داخلی آن در مرحله جداگانه بررسی می‌شود.';
            }
            if ($kind === 'office' && $archive['contains_macros']) {
                throw new RuntimeException('preflight_office_macros_not_allowed');
            }
            if ($kind === 'office') {
                $details['office_type'] = $this->officeType($extension);
            }
        } elseif (in_array($kind, ['tar', 'gzip'], true)) {
            $details['archive'] = $this->inspectTarOrGzip($absolutePath, $extension);
        } elseif ($kind === 'pdf') {
            $details['page_count'] = $this->countPdfPages($absolutePath, $size);
            if ($details['page_count'] === 0) {
                $warnings[] = 'تعداد صفحات از ساختار PDF قابل تشخیص قطعی نبود.';
            }
        } elseif ($kind === 'image') {
            $image = @getimagesize($absolutePath);
            if (! is_array($image)) {
                throw new RuntimeException('preflight_image_decode_failed');
            }
            $details['width'] = (int) $image[0];
            $details['height'] = (int) $image[1];
            $details['image_mime'] = (string) ($image['mime'] ?? $detectedMime);
        } elseif ($kind === 'text') {
            $details['text_preview'] = $this->textPreview($absolutePath);
        } elseif ($kind === 'audio') {
            $details['duration_seconds'] = null;
            $warnings[] = 'مدت دقیق صوت پس از پردازش محتوایی محاسبه می‌شود.';
        } elseif ($kind === 'video') {
            $details['duration_seconds'] = null;
            $warnings[] = 'مدت دقیق ویدئو پس از پردازش محتوایی محاسبه می‌شود.';
        }

        $details['warnings'] = $warnings;
        $details['security'] = [
            'engine' => self::ENGINE,
            'status' => 'structural_clean',
            'external_scanner' => false,
            'limitations' => 'بررسی ساختار و الگوهای خطرناک شناخته‌شده است و معادل آنتی‌ویروس عمومی یا تضمین کشف همه بدافزارها نیست.',
        ];

        return $details;
    }

    private function detectKind(string $extension, string $mime, string $prefix): string
    {
        if (str_starts_with($prefix, '%PDF-')) {
            if ($extension !== 'pdf') {
                throw new RuntimeException('preflight_extension_signature_mismatch');
            }
            return 'pdf';
        }
        if (str_starts_with($prefix, "PK\x03\x04") || str_starts_with($prefix, "PK\x05\x06") || str_starts_with($prefix, "PK\x07\x08")) {
            if (in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
                return 'office';
            }
            if ($extension !== 'zip') {
                throw new RuntimeException('preflight_extension_signature_mismatch');
            }
            return 'zip';
        }
        if (str_starts_with($prefix, "MZ") || str_starts_with($prefix, "\x7fELF")
            || str_starts_with($prefix, "\xfe\xed\xfa") || str_starts_with($prefix, "\xcf\xfa\xed\xfe")) {
            throw new RuntimeException('preflight_executable_signature_rejected');
        }
        if (str_starts_with($prefix, "\x89PNG\r\n\x1a\n") || str_starts_with($prefix, "\xff\xd8\xff")
            || str_starts_with($prefix, 'GIF87a') || str_starts_with($prefix, 'GIF89a')
            || (str_starts_with($prefix, 'RIFF') && substr($prefix, 8, 4) === 'WEBP')) {
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                throw new RuntimeException('preflight_extension_signature_mismatch');
            }
            return 'image';
        }
        if (str_starts_with($prefix, "Rar!\x1a\x07") || str_starts_with($prefix, "7z\xbc\xaf\x27\x1c")) {
            throw new RuntimeException('preflight_archive_format_not_supported_for_safe_listing');
        }
        if (str_starts_with($prefix, "\x1f\x8b")) {
            if (! in_array($extension, ['gz', 'tgz'], true)) {
                throw new RuntimeException('preflight_extension_signature_mismatch');
            }
            return $extension === 'tgz' ? 'tar' : 'gzip';
        }
        if (in_array($extension, ['tar'], true)) {
            return 'tar';
        }
        if (in_array($extension, ['doc', 'xls', 'ppt'], true) && str_starts_with($prefix, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1")) {
            return 'office_legacy';
        }
        if (in_array($extension, ['mp3', 'm4a', 'wav', 'ogg'], true)) {
            if (str_starts_with($prefix, 'ID3') || str_starts_with($prefix, "\xff\xfb")
                || str_starts_with($prefix, 'RIFF') || str_starts_with($prefix, 'OggS')
                || substr($prefix, 4, 4) === 'ftyp') {
                return 'audio';
            }
            throw new RuntimeException('preflight_audio_signature_invalid');
        }
        if (in_array($extension, ['mp4', 'webm', 'mov'], true)) {
            if (substr($prefix, 4, 4) === 'ftyp' || str_starts_with($prefix, "\x1a\x45\xdf\xa3")) {
                return 'video';
            }
            throw new RuntimeException('preflight_video_signature_invalid');
        }
        if (in_array($extension, ['txt', 'csv', 'md'], true) && ! str_contains($prefix, "\0")) {
            return 'text';
        }
        throw new RuntimeException('preflight_file_signature_not_recognized');
    }

    private function inspectZip(string $path, int $size): array
    {
        $bytes = file_get_contents($path);
        if (! is_string($bytes) || strlen($bytes) !== $size) {
            throw new RuntimeException('preflight_archive_read_failed');
        }
        $eocd = strrpos($bytes, "PK\x05\x06");
        if ($eocd === false || $eocd + 22 > strlen($bytes)) {
            throw new RuntimeException('preflight_zip_directory_missing');
        }
        $end = unpack('vdisk/vstart/vdisk_entries/ventries/Vcentral_size/Vcentral_offset/vcomment_length', substr($bytes, $eocd + 4, 18));
        if (! is_array($end) || $end['ventries'] === 0xffff || $end['central_offset'] === 0xffffffff) {
            throw new RuntimeException('preflight_zip64_not_supported');
        }
        if ($end['ventries'] > self::MAX_ARCHIVE_ENTRIES || $end['central_offset'] + $end['central_size'] > strlen($bytes)) {
            throw new RuntimeException('preflight_archive_limits_exceeded');
        }

        $entries = [];
        $suspicious = [];
        $nested = 0;
        $containsMacros = false;
        $expanded = 0;
        $offset = (int) $end['central_offset'];
        for ($index = 0; $index < (int) $end['ventries']; $index++) {
            if (substr($bytes, $offset, 4) !== "PK\x01\x02") {
                throw new RuntimeException('preflight_zip_directory_corrupt');
            }
            $header = unpack('vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Vlocal', substr($bytes, $offset + 4, 42));
            if (! is_array($header)) {
                throw new RuntimeException('preflight_zip_directory_corrupt');
            }
            $name = substr($bytes, $offset + 46, $header['name']);
            $offset += 46 + $header['name'] + $header['extra'] + $header['comment'];
            $normalized = str_replace('\\', '/', $name);
            $trimmed = ltrim($normalized, './');
            $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
            $isDirectory = str_ends_with($normalized, '/');
            if (str_starts_with($normalized, '/') || preg_match('/(^|\/)\.\.(\/|$)/', $normalized) || preg_match('/^[a-z]:\//i', $normalized)) {
                $suspicious[] = 'path_traversal';
            }
            if (! $isDirectory && in_array($extension, self::DANGEROUS_EXTENSIONS, true)) {
                $suspicious[] = 'executable_or_script_entry';
            }
            if (strtolower($normalized) === 'word/vbaproject.bin' || str_contains(strtolower($normalized), 'vbaproject.bin')) {
                $containsMacros = true;
            }
            if (! $isDirectory && in_array($extension, ['zip', 'gz', 'tgz', 'tar', 'rar', '7z'], true)) {
                $nested++;
            }
            if (($header['flags'] & 0x1) !== 0) {
                $suspicious[] = 'encrypted_entry';
            }
            $expanded += (int) $header['uncompressed'];
            if ($header['uncompressed'] > 0 && $header['compressed'] > 0
                && $header['uncompressed'] / $header['compressed'] > 200) {
                $suspicious[] = 'extreme_compression_ratio';
            }
            if (count($entries) < 250 && ! $isDirectory) {
                $entries[] = [
                    'name' => mb_substr($trimmed, 0, 180),
                    'size_bytes' => (int) $header['uncompressed'],
                    'compressed_bytes' => (int) $header['compressed'],
                    'type' => $extension ?: 'file',
                ];
            }
            if ($expanded > self::MAX_EXPANDED_BYTES) {
                $suspicious[] = 'expanded_size_limit';
            }
        }

        return [
            'format' => 'zip',
            'entry_count' => (int) $end['ventries'],
            'expanded_bytes' => $expanded,
            'entries' => $entries,
            'nested_archives' => $nested,
            'contains_macros' => $containsMacros,
            'suspicious' => array_values(array_unique($suspicious)),
            'listing_complete' => true,
        ];
    }

    private function inspectTarOrGzip(string $path, string $extension): array
    {
        $handle = $extension === 'tar' ? fopen($path, 'rb') : gzopen($path, 'rb');
        if (! is_resource($handle)) {
            throw new RuntimeException('preflight_tar_open_failed');
        }
        $entries = [];
        $expanded = 0;
        $count = 0;
        $suspicious = [];
        try {
            while ($count < self::MAX_ARCHIVE_ENTRIES) {
                $header = $extension === 'tar' ? fread($handle, 512) : gzread($handle, 512);
                if (! is_string($header) || strlen($header) < 512 || trim($header, "\0") === '') {
                    break;
                }
                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                if ($prefix !== '') $name = $prefix.'/'.$name;
                $sizeOctal = trim(substr($header, 124, 12), "\0 ");
                $entrySize = $sizeOctal !== '' && preg_match('/^[0-7]+$/', $sizeOctal) ? octdec($sizeOctal) : 0;
                $typeFlag = substr($header, 156, 1);
                $normalized = str_replace('\\', '/', $name);
                $extensionName = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
                if (str_starts_with($normalized, '/') || preg_match('/(^|\/)\.\.(\/|$)/', $normalized)) {
                    $suspicious[] = 'path_traversal';
                }
                if (in_array($extensionName, self::DANGEROUS_EXTENSIONS, true)) {
                    $suspicious[] = 'executable_or_script_entry';
                }
                $expanded += (int) $entrySize;
                if ($expanded > self::MAX_EXPANDED_BYTES) {
                    $suspicious[] = 'expanded_size_limit';
                }
                if ($typeFlag === '2' || $typeFlag === '1') {
                    $suspicious[] = 'links_not_allowed';
                }
                if (count($entries) < 250 && $typeFlag !== '5') {
                    $entries[] = ['name' => mb_substr($normalized, 0, 180), 'size_bytes' => (int) $entrySize, 'type' => $extensionName ?: 'file'];
                }
                $skip = (int) (ceil($entrySize / 512) * 512);
                if ($skip > 0) {
                    $discarded = $extension === 'tar' ? fseek($handle, $skip, SEEK_CUR) : gzread($handle, $skip);
                    if ($extension === 'tar' && $discarded !== 0) break;
                    if ($extension !== 'tar' && (! is_string($discarded) || strlen($discarded) !== $skip)) break;
                }
                $count++;
            }
        } finally {
            $extension === 'tar' ? fclose($handle) : gzclose($handle);
        }
        if ($count >= self::MAX_ARCHIVE_ENTRIES) {
            $suspicious[] = 'entry_count_limit';
        }
        if ($extension !== 'tar' && $count === 0) {
            throw new RuntimeException('preflight_gzip_payload_not_tar');
        }
        return [
            'format' => $extension,
            'entry_count' => $count,
            'expanded_bytes' => $expanded,
            'entries' => $entries,
            'nested_archives' => 0,
            'contains_macros' => false,
            'suspicious' => array_values(array_unique($suspicious)),
            'listing_complete' => true,
        ];
    }

    private function countPdfPages(string $path, int $size): int
    {
        $bytes = file_get_contents($path);
        if (! is_string($bytes) || strlen($bytes) !== $size) return 0;
        preg_match_all('/\/Type\s*\/Page\b/', $bytes, $matches);
        return count($matches[0] ?? []);
    }

    private function textPreview(string $path): string
    {
        $text = file_get_contents($path, false, null, 0, 1200);
        if (! is_string($text)) return '';
        return mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '', 0, 900);
    }

    private function officeType(string $extension): string
    {
        return match ($extension) {
            'docx' => 'Microsoft Word',
            'xlsx' => 'Microsoft Excel',
            'pptx' => 'Microsoft PowerPoint',
            default => 'Office document',
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) return number_format($bytes / 1024 / 1024, 2).' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 1).' KB';
        return $bytes.' B';
    }
}
