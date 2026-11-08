<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class UploadSecurityService
{
    private const MAX_ARCHIVE_ENTRIES = 500;
    private const MAX_ARCHIVE_EXPANDED_BYTES = 250 * 1024 * 1024;
    private const MAX_ARCHIVE_RATIO = 100;
    private const MAX_IMAGE_PIXELS = 50000000;

    private array $blockedExtensions = [
        'php','phar','phtml','pht','exe','dll','so','dylib','msi','bat','cmd','ps1',
        'sh','com','scr','jar','apk','app','js','mjs','cjs','vbs','hta','py','rb',
        'pl','html','htm','xhtml','svg','docm','xlsm','pptm',
    ];

    private array $allowedExtensions = [
        'pdf','zip','tar','tar.gz','tar.bz2','docx','xlsx','pptx','txt','csv','md',
        'jpg','jpeg','png','webp','gif','mp3','wav','ogg','flac','m4a','mp4','webm','mov',
    ];

    public function inspect(string $path, ?string $expectedMime = null, ?int $maxBytes = null, ?array $allowedMimes = null): array
    {
        $disk = Storage::disk('private');
        if (! $disk->exists($path)) {
            throw new RuntimeException('upload_not_found');
        }

        $size = (int) $disk->size($path);
        $limit = $maxBytes ?? (int) config('farast.security.max_upload_bytes', 50 * 1024 * 1024);
        if ($size < 1 || $size > $limit) {
            throw new RuntimeException($size < 1 ? 'upload_empty' : 'upload_too_large');
        }

        $absolutePath = $disk->path($path);
        $originalName = basename(str_replace('\\', '/', $path));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($extension, $this->blockedExtensions, true) || ! in_array($extension, $this->allowedExtensions, true)) {
            throw new RuntimeException('upload_extension_rejected');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string) $finfo->file($absolutePath));
        if ($mime === '' || $mime === 'application/x-dosexec' || $mime === 'text/html' || $mime === 'image/svg+xml') {
            throw new RuntimeException('upload_mime_rejected');
        }
        if ($expectedMime && strtolower($expectedMime) !== $mime && ! $this->mimeCompatible($extension, $mime)) {
            throw new RuntimeException('upload_mime_mismatch');
        }
        if ($allowedMimes && ! in_array($mime, $allowedMimes, true) && ! $this->mimeCompatible($extension, $mime)) {
            throw new RuntimeException('upload_mime_rejected');
        }

        $handle = fopen($absolutePath, 'rb');
        if (! is_resource($handle)) {
            throw new RuntimeException('upload_read_failed');
        }
        $header = fread($handle, 512) ?: '';
        fclose($handle);

        $inspection = [
            'safe' => true,
            'mime' => $mime,
            'size' => $size,
            'checksum' => hash_file('sha256', $absolutePath),
            'scan' => 'farast_static_v1',
            'scan_label' => 'بررسی ایستای داخلی فراست',
            'risk_level' => 'low',
            'detected_type' => $this->detectType($extension, $header),
            'page_count' => null,
            'dimensions' => null,
            'archive_entries' => [],
            'archive_entry_count' => 0,
            'findings' => [],
            'warnings' => [],
            'storage' => 'private',
        ];

        if (! is_string($inspection['checksum']) || strlen($inspection['checksum']) !== 64) {
            throw new RuntimeException('upload_checksum_failed');
        }

        if ($inspection['detected_type'] === 'archive') {
            if (in_array($extension, ['zip','docx','xlsx','pptx'], true)) {
                $archive = $this->inspectZip($absolutePath);
                $members = array_column($archive['archive_entries'], 'name');
                $requiredOfficeMembers = [
                    'docx' => ['[Content_Types].xml', 'word/document.xml'],
                    'xlsx' => ['[Content_Types].xml', 'xl/workbook.xml'],
                    'pptx' => ['[Content_Types].xml', 'ppt/presentation.xml'],
                ];
                if (isset($requiredOfficeMembers[$extension])) {
                    foreach ($requiredOfficeMembers[$extension] as $requiredMember) {
                        if (! in_array($requiredMember, $members, true)) {
                            throw new RuntimeException('upload_office_container_invalid');
                        }
                    }
                    if (in_array('word/vbaProject.bin', $members, true) || in_array('xl/vbaProject.bin', $members, true) || in_array('ppt/vbaProject.bin', $members, true)) {
                        throw new RuntimeException('upload_office_macro_rejected');
                    }
                    $inspection['detected_type'] = 'office';
                    if ($extension === 'xlsx') {
                        $inspection['sheet_count'] = count(array_filter($members, static fn ($name) => preg_match('~^xl/worksheets/sheet[0-9]+\\.xml$~i', $name) === 1));
                    } elseif ($extension === 'pptx') {
                        $inspection['page_count'] = count(array_filter($members, static fn ($name) => preg_match('~^ppt/slides/slide[0-9]+\\.xml$~i', $name) === 1));
                        $inspection['page_count_label'] = 'تعداد اسلاید';
                    } elseif ($extension === 'docx') {
                        $inspection['document_parts'] = [
                            'headers' => count(array_filter($members, static fn ($name) => str_starts_with($name, 'word/header') && str_ends_with($name, '.xml'))),
                            'footers' => count(array_filter($members, static fn ($name) => str_starts_with($name, 'word/footer') && str_ends_with($name, '.xml'))),
                            'footnotes' => in_array('word/footnotes.xml', $members, true),
                            'endnotes' => in_array('word/endnotes.xml', $members, true),
                        ];
                    }
                }
            } else {
                $archive = $this->inspectTar($absolutePath, $extension);
            }
            $inspection = array_merge($inspection, $archive);
        } elseif ($inspection['detected_type'] === 'pdf') {
            if ($this->pdfContainsActiveContent($absolutePath)) {
                throw new RuntimeException('upload_pdf_active_content_rejected');
            }
            $inspection['page_count'] = $this->countPdfPages($absolutePath);
            $inspection['page_count_method'] = 'static_pdf_object_scan';
        } elseif ($inspection['detected_type'] === 'image') {
            $inspection['dimensions'] = $this->inspectImage($absolutePath);
        } elseif ($inspection['detected_type'] === 'office') {
            $inspection['warnings'][] = 'تعداد صفحات سند آفیس تا زمان تبدیل کنترل‌شده به PDF قطعی نیست.';
        }

        $inspection['safe'] = $inspection['risk_level'] !== 'blocked';
        $inspection['sandbox_required'] = true;
        $inspection['security_notice'] = 'این نتیجه بررسی ایستای داخلی است و تضمین کشف همه بدافزارهای ناشناخته نیست. فایل هرگز اجرا نمی‌شود.';

        return $inspection;
    }

    private function mimeCompatible(string $extension, string $mime): bool
    {
        $groups = [
            'pdf' => ['application/pdf'],
            'zip' => ['application/zip','application/x-zip','application/x-zip-compressed','application/octet-stream'],
            'tar' => ['application/x-tar','application/tar','application/octet-stream'],
            'tar.gz' => ['application/gzip','application/x-gzip','application/octet-stream'],
            'tar.bz2' => ['application/x-bzip2','application/bzip2','application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip','application/octet-stream'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip','application/octet-stream'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation','application/zip','application/octet-stream'],
            'doc' => ['application/msword','application/x-ole-storage','application/octet-stream'],
            'xls' => ['application/vnd.ms-excel','application/x-ole-storage','application/octet-stream'],
            'ppt' => ['application/vnd.ms-powerpoint','application/x-ole-storage','application/octet-stream'],
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
            'png' => ['image/png'], 'webp' => ['image/webp'], 'gif' => ['image/gif'],
            'mp3' => ['audio/mpeg','application/octet-stream'],
            'wav' => ['audio/wav','audio/x-wav','application/octet-stream'],
            'ogg' => ['audio/ogg','application/ogg'],
            'flac' => ['audio/flac','audio/x-flac','application/octet-stream'],
            'm4a' => ['audio/mp4','video/mp4','application/octet-stream'],
            'mp4' => ['video/mp4','application/mp4','application/octet-stream'],
            'mov' => ['video/quicktime','video/mp4','application/octet-stream'],
            'webm' => ['video/webm','application/octet-stream'],
            'txt' => ['text/plain'], 'csv' => ['text/plain','text/csv','application/csv'],
            'md' => ['text/plain','text/markdown','application/octet-stream'],
        ];

        return in_array($mime, $groups[$extension] ?? [], true);
    }

    private function detectType(string $extension, string $header): string
    {
        if ($extension === 'pdf' && str_starts_with($header, '%PDF-')) return 'pdf';
        if (in_array($extension, ['zip','docx','xlsx','pptx'], true) && str_starts_with($header, "PK\x03\x04")) return 'archive';
        if ($extension === 'tar' && substr($header, 257, 5) === 'ustar') return 'archive';
        if ($extension === 'tar.gz' && str_starts_with($header, "\x1F\x8B")) return 'archive';
        if ($extension === 'tar.bz2' && str_starts_with($header, 'BZh')) return 'archive';
        if (in_array($extension, ['doc','xls','ppt'], true) && str_starts_with($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) return 'office';
        if (in_array($extension, ['jpg','jpeg'], true) && str_starts_with($header, "\xFF\xD8\xFF")) return 'image';
        if ($extension === 'png' && str_starts_with($header, "\x89PNG\r\n\x1A\n")) return 'image';
        if ($extension === 'gif' && (str_starts_with($header, 'GIF87a') || str_starts_with($header, 'GIF89a'))) return 'image';
        if ($extension === 'webp' && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') return 'image';
        if (in_array($extension, ['mp3','wav','ogg','flac','m4a'], true)) return 'audio';
        if (in_array($extension, ['mp4','webm','mov'], true)) return 'video';
        if (in_array($extension, ['txt','csv','md'], true)) return 'text';
        throw new RuntimeException('upload_signature_mismatch');
    }

    private function pdfContainsActiveContent(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            throw new RuntimeException('upload_pdf_unreadable');
        }

        $carry = '';
        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);
                if (! is_string($chunk) || $chunk === '') break;
                $window = $carry.$chunk;
                if (preg_match('/\\/(?:JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA)\\b/i', $window) === 1) {
                    return true;
                }
                $carry = substr($window, -64);
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    private function countPdfPages(string $path): ?int
    {
        if (! is_file($path) || ! str_starts_with((string) file_get_contents($path, false, null, 0, 8), '%PDF-')) {
            throw new RuntimeException('upload_pdf_signature_invalid');
        }

        if (! class_exists(\setasign\Fpdi\Fpdi::class)) {
            throw new RuntimeException('upload_pdf_inspector_unavailable');
        }

        try {
            $reader = new \setasign\Fpdi\Fpdi();
            $count = (int) $reader->setSourceFile($path);
        } catch (\Throwable) {
            throw new RuntimeException('upload_pdf_structure_unreadable');
        }

        if ($count < 1 || $count > 5000) {
            throw new RuntimeException('upload_pdf_page_limit_exceeded');
        }

        return $count;
    }

    private function inspectImage(string $path): array
    {
        $info = @getimagesize($path);
        if (! is_array($info) || empty($info[0]) || empty($info[1])) {
            throw new RuntimeException('upload_image_decode_failed');
        }
        $pixels = (int) $info[0] * (int) $info[1];
        if ($pixels > self::MAX_IMAGE_PIXELS) {
            throw new RuntimeException('upload_image_dimensions_too_large');
        }
        return ['width' => (int) $info[0], 'height' => (int) $info[1], 'pixels' => $pixels];
    }

    private function inspectTar(string $path, string $extension): array
    {
        $sourceSize = (int) filesize($path);
        if ($extension === 'tar') {
            $stream = fopen($path, 'rb');
            $read = static fn (int $length) => fread($stream, $length);
            $close = static fn () => fclose($stream);
        } elseif ($extension === 'tar.gz') {
            $stream = @gzopen($path, 'rb');
            if (! is_resource($stream)) throw new RuntimeException('upload_tar_compression_unsupported');
            $read = static fn (int $length) => gzread($stream, $length);
            $close = static fn () => gzclose($stream);
        } elseif ($extension === 'tar.bz2') {
            if (! function_exists('bzopen')) throw new RuntimeException('upload_tar_compression_unsupported');
            $stream = @bzopen($path, 'r');
            if (! is_resource($stream)) throw new RuntimeException('upload_tar_compression_unsupported');
            $read = static fn (int $length) => bzread($stream, $length);
            $close = static fn () => bzclose($stream);
        } else {
            throw new RuntimeException('upload_archive_format_unsupported');
        }

        if (! is_resource($stream)) throw new RuntimeException('upload_archive_read_failed');
        $readExact = static function (int $length) use ($read): string {
            $buffer = '';
            while (strlen($buffer) < $length) {
                $part = $read($length - strlen($buffer));
                if (! is_string($part) || $part === '') break;
                $buffer .= $part;
            }
            return $buffer;
        };

        $entries = [];
        $totalExpanded = 0;
        $risk = 'low';
        $findings = [];
        try {
            $finished = false;
            for ($index = 0; $index < self::MAX_ARCHIVE_ENTRIES; $index++) {
                $header = $readExact(512);
                if ($header === '') {
                    $finished = true;
                    break;
                }
                if (strlen($header) !== 512) throw new RuntimeException('upload_tar_header_invalid');
                if ($header === str_repeat("\0", 512)) {
                    $finished = true;
                    break;
                }

                $checksumText = trim(substr($header, 148, 8), "\0 ");
                if ($checksumText === '' || preg_match('/^[0-7]+$/', $checksumText) !== 1) {
                    throw new RuntimeException('upload_tar_checksum_invalid');
                }
                $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
                $checksum = array_sum(unpack('C*', $checksumHeader));
                if ($checksum !== octdec($checksumText)) throw new RuntimeException('upload_tar_checksum_invalid');

                $name = trim(substr($header, 0, 100), "\0");
                $prefix = trim(substr($header, 345, 155), "\0");
                $name = $prefix !== '' ? $prefix.'/'.$name : $name;
                $sizeText = trim(substr($header, 124, 12), "\0 ");
                if ($sizeText !== '' && preg_match('/^[0-7]+$/', $sizeText) !== 1) {
                    throw new RuntimeException('upload_tar_size_invalid');
                }
                $entrySize = $sizeText === '' ? 0 : octdec($sizeText);
                $type = substr($header, 156, 1);
                $normalized = str_replace('\\', '/', $name);
                $parts = explode('/', $normalized);
                $entryExtension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));

                if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) || in_array('..', $parts, true) || str_contains($normalized, "\0")) {
                    throw new RuntimeException('upload_archive_path_or_link_rejected');
                }
                if (in_array($type, ['1','2','3','4','6','7','x','g','L','K'], true)) {
                    throw new RuntimeException('upload_tar_link_or_special_entry_rejected');
                }
                if (! in_array($type, ["\0", '0', '5'], true)) {
                    throw new RuntimeException('upload_tar_entry_type_unsupported');
                }
                if (in_array($entryExtension, $this->blockedExtensions, true)) {
                    throw new RuntimeException('upload_archive_executable_rejected');
                }

                $totalExpanded += $entrySize;
                if ($totalExpanded > self::MAX_ARCHIVE_EXPANDED_BYTES || $entrySize > 100 * 1024 * 1024) {
                    throw new RuntimeException('upload_archive_expansion_limit');
                }
                if ($entryExtension !== '' && in_array($entryExtension, ['zip','rar','7z','tar','gz','bz2','xz'], true)) {
                    $risk = 'warning';
                    $findings[] = 'آرشیو تو در تو شناسایی شد؛ محتوای داخلی آن باز یا اجرا نمی‌شود.';
                }

                $entries[] = [
                    'name' => mb_substr($normalized, 0, 240),
                    'size_bytes' => (int) $entrySize,
                    'compressed_bytes' => null,
                    'type' => $type === '5' ? 'directory' : 'file',
                ];

                $padding = (int) (ceil($entrySize / 512) * 512);
                if ($padding > 0 && strlen($readExact($padding)) !== $padding) {
                    throw new RuntimeException('upload_tar_entry_truncated');
                }
            }
            if (! $finished) throw new RuntimeException('upload_archive_limits_exceeded');
        } finally {
            $close();
        }

        if (in_array($extension, ['tar.gz', 'tar.bz2'], true) && $totalExpanded > 0
            && $totalExpanded / max(1, $sourceSize) > self::MAX_ARCHIVE_RATIO) {
            throw new RuntimeException('upload_archive_ratio_suspicious');
        }

        return [
            'archive_entries' => $entries,
            'archive_entry_count' => count($entries),
            'archive_expanded_bytes' => $totalExpanded,
            'risk_level' => $risk,
            'findings' => array_values(array_unique($findings)),
        ];
    }

    private function inspectZip(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) throw new RuntimeException('upload_archive_read_failed');
        $size = filesize($path);
        $tailLength = min($size, 65557);
        fseek($handle, -$tailLength, SEEK_END);
        $tail = fread($handle, $tailLength);
        $eocdPosition = is_string($tail) ? strrpos($tail, "PK\x05\x06") : false;
        if ($eocdPosition === false || strlen($tail) < $eocdPosition + 22) {
            fclose($handle);
            throw new RuntimeException('upload_zip_directory_missing');
        }
        $eocd = unpack('vdisk/vcentral_disk/vdisk_entries/ventries/Vcentral_size/Vcentral_offset/vcomment_length', substr($tail, $eocdPosition + 4, 18));
        if (! is_array($eocd) || $eocd['disk'] !== 0 || $eocd['central_disk'] !== 0 || $eocd['entries'] > self::MAX_ARCHIVE_ENTRIES || $eocd['entries'] !== $eocd['disk_entries']) {
            fclose($handle);
            throw new RuntimeException('upload_archive_limits_exceeded');
        }
        if ($eocd['central_offset'] + $eocd['central_size'] > $size) {
            fclose($handle);
            throw new RuntimeException('upload_zip_directory_invalid');
        }

        fseek($handle, $eocd['central_offset']);
        $entries = [];
        $totalExpanded = 0;
        $risk = 'low';
        $findings = [];
        for ($i = 0; $i < $eocd['entries']; $i++) {
            $fixed = fread($handle, 46);
            if (! is_string($fixed) || strlen($fixed) !== 46 || substr($fixed, 0, 4) !== "PK\x01\x02") {
                fclose($handle);
                throw new RuntimeException('upload_zip_entry_invalid');
            }
            $meta = unpack('vversion_made/vversion_needed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vexpanded/vname_len/vextra_len/vcomment_len/vdisk/vinternal/Vexternal/Vlocal_offset', substr($fixed, 4));
            if (! is_array($meta) || ($meta['flags'] & 0x1) !== 0 || ! in_array($meta['method'], [0,8], true) || $meta['disk'] !== 0) {
                fclose($handle);
                throw new RuntimeException('upload_archive_encrypted_or_unsupported');
            }
            $name = fread($handle, $meta['name_len']);
            if (! is_string($name) || strlen($name) !== $meta['name_len']) {
                fclose($handle);
                throw new RuntimeException('upload_zip_entry_name_invalid');
            }
            if ($meta['extra_len'] > 0) fseek($handle, $meta['extra_len'], SEEK_CUR);
            if ($meta['comment_len'] > 0) fseek($handle, $meta['comment_len'], SEEK_CUR);

            $normalized = str_replace('\\', '/', $name);
            $parts = explode('/', $normalized);
            $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
            $mode = ($meta['external'] >> 16) & 0170000;
            if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) || in_array('..', $parts, true) || str_contains($normalized, "\0") || $mode === 0120000) {
                fclose($handle);
                throw new RuntimeException('upload_archive_path_or_link_rejected');
            }
            if (in_array($extension, $this->blockedExtensions, true)) {
                fclose($handle);
                throw new RuntimeException('upload_archive_executable_rejected');
            }
            if (in_array($extension, ['zip','rar','7z','gz','tgz','tar'], true)) {
                $risk = 'warning';
                $findings[] = 'آرشیو تو در تو شناسایی شد؛ محتوای داخلی آن باز یا اجرا نمی‌شود.';
            }
            if (($meta['flags'] & 0x40) !== 0) {
                fclose($handle);
                throw new RuntimeException('upload_archive_strong_encryption_rejected');
            }
            $totalExpanded += (int) $meta['expanded'];
            if ($totalExpanded > self::MAX_ARCHIVE_EXPANDED_BYTES || $meta['expanded'] > 100 * 1024 * 1024) {
                fclose($handle);
                throw new RuntimeException('upload_archive_expansion_limit');
            }
            if ($meta['expanded'] > 0 && ($meta['compressed'] === 0 || $meta['expanded'] / max(1, $meta['compressed']) > self::MAX_ARCHIVE_RATIO)) {
                fclose($handle);
                throw new RuntimeException('upload_archive_ratio_suspicious');
            }
            $entries[] = [
                'name' => mb_substr($normalized, 0, 240),
                'size_bytes' => (int) $meta['expanded'],
                'compressed_bytes' => (int) $meta['compressed'],
                'type' => str_ends_with($normalized, '/') ? 'directory' : 'file',
            ];
        }
        fclose($handle);

        return [
            'archive_entries' => $entries,
            'archive_entry_count' => count($entries),
            'archive_expanded_bytes' => $totalExpanded,
            'risk_level' => $risk,
            'findings' => array_values(array_unique($findings)),
        ];
    }
}
