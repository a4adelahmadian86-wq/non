<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class StoreXlsxPreviewRenderer
{
    private const MAX_ARCHIVE_ENTRIES = 2000;
    private const MAX_UNCOMPRESSED_BYTES = 80 * 1024 * 1024;
    private const MAX_SHEETS = 10;
    private const MAX_ROWS = 2000;
    private const MAX_COLUMNS = 40;
    private const MAX_CELLS = 80000;

    public function render(string $bytes, string $identity): string
    {
        if (!class_exists(ZipArchive::class) || strlen($bytes) > 20 * 1024 * 1024) {
            throw new RuntimeException('XLSX preview is unavailable or too large.');
        }

        $path = tempnam(sys_get_temp_dir(), 'farast-xlsx-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate temporary workbook storage.');
        }

        try {
            if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('Unable to stage workbook.');
            }

            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new RuntimeException('Invalid XLSX archive.');
            }

            try {
                $this->validateArchive($zip);
                $workbook = $this->loadXml($zip, 'xl/workbook.xml', 2 * 1024 * 1024);
                $relationships = $this->loadXml($zip, 'xl/_rels/workbook.xml.rels', 2 * 1024 * 1024);
                $targets = $this->relationshipTargets($relationships);
                $sharedStrings = $this->readSharedStrings($zip);
                $sheets = (new DOMXPath($workbook))->query('//*[local-name()="sheets"]/*[local-name()="sheet"]');

                if (!$sheets || $sheets->length < 1 || $sheets->length > self::MAX_SHEETS) {
                    throw new RuntimeException('Workbook sheet count is outside supported limits.');
                }

                $safeIdentity = $this->escape($identity !== '' ? $identity : 'حساب کاربر');
                $tables = [];
                $cellCount = 0;

                foreach ($sheets as $sheetIndex => $sheet) {
                    if (!$sheet instanceof DOMElement) {
                        continue;
                    }
                    if (in_array(strtolower($sheet->getAttribute('state')), ['hidden', 'veryhidden'], true)) {
                        continue;
                    }

                    $relationshipId = $sheet->getAttributeNS(
                        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                        'id'
                    );
                    $target = $targets[$relationshipId] ?? null;
                    if (!$target) {
                        throw new RuntimeException('Workbook contains an unresolved worksheet relationship.');
                    }

                    $sheetPath = $this->normalizeWorksheetPath($target);
                    $xml = $this->loadXml($zip, $sheetPath, 20 * 1024 * 1024);
                    $rows = $this->readWorksheet($xml, $sharedStrings, $cellCount);
                    $name = $this->escape($sheet->getAttribute('name') ?: 'Sheet '.($sheetIndex + 1));
                    $tables[] = '<section class="sheet"><h2>'.($sheetIndex + 1).'. '.$name.'</h2>'.$this->table($rows).'</section>';
                }

                if ($tables === []) {
                    throw new RuntimeException('Workbook has no readable worksheets.');
                }

                $marks = '';
                for ($i = 0; $i < 12; $i++) {
                    $top = 8 + (($i % 4) * 25);
                    $left = 3 + (intdiv($i, 4) * 32);
                    $marks .= '<span style="top:'.$top.'%;left:'.$left.'%">'.$safeIdentity.' · پیش‌نمایش فراست</span>';
                }

                return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>پیش‌نمایش Excel</title><style>
                    *{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Tahoma,Arial,sans-serif;color:#172033;background:#f3f6fb}
                    body{padding:24px 18px 50px;font-size:13px;line-height:1.8;user-select:none;-webkit-user-select:none}
                    .workbook{max-width:1200px;margin:auto;position:relative;background:#fff;border:1px solid #dce4ef;border-radius:14px;padding:24px;box-shadow:0 12px 40px rgba(15,35,65,.08);overflow:hidden}
                    .intro{position:relative;z-index:2;padding:0 0 16px;border-bottom:1px solid #e7edf5}.intro h1{font-size:18px;margin:0 0 5px}.intro p{margin:0;color:#64748b;font-size:12px}
                    .sheet{position:relative;z-index:1;margin-top:24px}.sheet h2{font-size:14px;margin:0 0 10px;color:#1d4d79}
                    .table-wrap{width:100%;overflow:auto;border:1px solid #dce4ef;border-radius:8px}
                    table{border-collapse:collapse;width:100%;min-width:520px;font-size:11px;direction:rtl}
                    th,td{border:1px solid #e2e8f0;padding:7px 9px;text-align:right;vertical-align:top;min-width:90px;max-width:280px;overflow-wrap:anywhere}
                    th{background:#edf3f9;color:#334155;font-weight:700;position:sticky;top:0}
                    td.rownum{min-width:42px;width:42px;text-align:center;background:#f8fafc;color:#64748b}
                    tr:nth-child(even) td:not(.rownum){background:#fbfdff}
                    .notice{margin-top:22px;padding:11px 14px;border-radius:8px;background:#f1f6fc;color:#52647b;font-size:11px}
                    .watermark{position:fixed;inset:0;z-index:5;pointer-events:none;opacity:.14;overflow:hidden}.watermark span{position:absolute;white-space:nowrap;transform:rotate(-24deg);font-weight:700;color:#26364b;background:rgba(255,255,255,.24);padding:5px 8px}
                    @media(max-width:700px){body{padding:10px}.workbook{padding:14px}.sheet{margin-top:18px}}
                    @media print{body{background:#fff;padding:0}.workbook{box-shadow:none;border:0}.watermark{position:fixed}}
                </style></head><body><div class="watermark">'.$marks.'</div><main class="workbook"><header class="intro"><h1>پیش‌نمایش کتاب کار Excel</h1><p>حالت فقط‌خواندنی · '.count($tables).' برگه · فرمول‌ها اجرا نمی‌شوند و فقط مقدار ذخیره‌شده نمایش داده می‌شود.</p></header>'.implode('', $tables).'<p class="notice">این سند برای مطالعه ارائه شده است. ویرایش، اجرای فرمول و دریافت فایل از این محیط پشتیبانی نمی‌شود.</p></main></body></html>';
            } finally {
                $zip->close();
            }
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function validateArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw new RuntimeException('XLSX archive entry count exceeds the limit.');
        }

        $uncompressed = 0;
        $compressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            if (!is_array($entry)) {
                throw new RuntimeException('Unreadable XLSX archive entry.');
            }
            $name = (string) ($entry['name'] ?? '');
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                throw new RuntimeException('Unsafe XLSX archive path.');
            }
            $uncompressed += (int) ($entry['size'] ?? 0);
            $compressed += (int) ($entry['comp_size'] ?? 0);
            if ($uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('XLSX archive expands beyond the allowed size.');
            }
        }

        if ($compressed > 0 && $uncompressed > max(16 * 1024 * 1024, $compressed * 150)) {
            throw new RuntimeException('XLSX archive compression ratio is unsafe.');
        }

        if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
            throw new RuntimeException('File is not a valid XLSX workbook.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            $name = (string) ($entry['name'] ?? '');
            if (!str_ends_with(strtolower($name), '.rels')) {
                continue;
            }
            $contents = $zip->getFromIndex($i);
            if (!is_string($contents) || strlen($contents) > 2 * 1024 * 1024) {
                throw new RuntimeException('XLSX relationship part is invalid or too large.');
            }
            $xml = $this->parseXml($contents);
            $xpath = new DOMXPath($xml);
            if ($xpath->query('//*[local-name()="Relationship" and translate(normalize-space(@TargetMode), "EXTERNAL", "external")="external"]')->length > 0) {
                throw new RuntimeException('External workbook relationships are not supported.');
            }
        }
    }

    private function loadXml(ZipArchive $zip, string $path, int $limit): DOMDocument
    {
        $index = $zip->locateName($path);
        if ($index === false) {
            throw new RuntimeException('Required XLSX part is missing.');
        }
        $contents = $zip->getFromIndex($index);
        if (!is_string($contents) || strlen($contents) > $limit) {
            throw new RuntimeException('XLSX XML part is invalid or too large.');
        }

        return $this->parseXml($contents);
    }

    private function parseXml(string $contents): DOMDocument
    {
        if (stripos($contents, '<!DOCTYPE') !== false || stripos($contents, '<!ENTITY') !== false) {
            throw new RuntimeException('DTD and entity declarations are not allowed in XLSX files.');
        }
        $document = new DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new RuntimeException('Malformed XLSX XML part.');
        }

        return $document;
    }

    private function relationshipTargets(DOMDocument $document): array
    {
        $targets = [];
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//*[local-name()="Relationship"]') as $relationship) {
            if (!$relationship instanceof DOMElement) {
                continue;
            }
            if (strtolower($relationship->getAttribute('TargetMode')) === 'external') {
                throw new RuntimeException('External relationships are not allowed.');
            }
            $id = $relationship->getAttribute('Id');
            $target = $relationship->getAttribute('Target');
            if ($id === '' || $target === '' || str_starts_with($target, '/') || str_contains($target, '\\')) {
                throw new RuntimeException('Invalid worksheet relationship.');
            }
            $targets[$id] = $target;
        }

        return $targets;
    }

    private function normalizeWorksheetPath(string $target): string
    {
        $segments = [];
        foreach (explode('/', 'xl/'.$target) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new RuntimeException('Worksheet relationship escapes the workbook directory.');
            }
            $segments[] = $segment;
        }
        $path = implode('/', $segments);
        if (!str_starts_with($path, 'xl/worksheets/')) {
            throw new RuntimeException('Only ordinary worksheet parts are supported.');
        }

        return $path;
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $index = $zip->locateName('xl/sharedStrings.xml');
        if ($index === false) {
            return [];
        }
        $contents = $zip->getFromIndex($index);
        if (!is_string($contents) || strlen($contents) > 20 * 1024 * 1024) {
            throw new RuntimeException('Shared strings part is too large.');
        }
        $xml = $this->parseXml($contents);
        $strings = [];
        $xpath = new DOMXPath($xml);
        foreach ($xpath->query('//*[local-name()="si"]') as $item) {
            if (count($strings) >= 100000) {
                throw new RuntimeException('Workbook shared-string count exceeds the limit.');
            }
            $value = '';
            foreach ((new DOMXPath($xml))->query('.//*[local-name()="t"]', $item) as $text) {
                $value .= $text->textContent;
                if (strlen($value) > 100000) {
                    throw new RuntimeException('A workbook text value is too large.');
                }
            }
            $strings[] = $value;
        }

        return $strings;
    }

    private function readWorksheet(DOMDocument $xml, array $sharedStrings, int &$cellCount): array
    {
        $rows = [];
        $xpath = new DOMXPath($xml);
        $rowNodes = $xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]');
        if (!$rowNodes || $rowNodes->length > self::MAX_ROWS) {
            throw new RuntimeException('Worksheet row count exceeds the limit.');
        }

        foreach ($rowNodes as $rowNode) {
            if (!$rowNode instanceof DOMElement) {
                continue;
            }
            $rowNumber = (int) $rowNode->getAttribute('r');
            if ($rowNumber < 1 || $rowNumber > self::MAX_ROWS) {
                continue;
            }
            $row = [];
            foreach ($xpath->query('./*[local-name()="c"]', $rowNode) as $cell) {
                if (!$cell instanceof DOMElement) {
                    continue;
                }
                if (++$cellCount > self::MAX_CELLS) {
                    throw new RuntimeException('Workbook cell count exceeds the limit.');
                }
                if (!preg_match('/^([A-Z]{1,3})([1-9][0-9]*)$/', strtoupper($cell->getAttribute('r')), $match)) {
                    continue;
                }
                $column = $this->columnIndex($match[1]);
                if ($column < 1 || $column > self::MAX_COLUMNS || (int) $match[2] > self::MAX_ROWS) {
                    continue;
                }

                $type = $cell->getAttribute('t');
                $valueNode = $xpath->query('./*[local-name()="v"]', $cell)->item(0);
                $value = $valueNode?->textContent ?? '';
                if ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = '';
                    foreach ($xpath->query('./*[local-name()="is"]//*[local-name()="t"]', $cell) as $text) {
                        $value .= $text->textContent;
                    }
                } elseif ($type === 'b') {
                    $value = $value === '1' ? 'بله' : 'خیر';
                } elseif ($type === 'e') {
                    $value = 'خطای ذخیره‌شده در فایل';
                }

                // Formula nodes are intentionally never evaluated. Only cached <v> is shown.
                $row[$column] = (string) $value;
            }
            if ($row !== []) {
                $rows[$rowNumber] = $row;
            }
        }

        ksort($rows);

        return $rows;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index;
    }

    private function table(array $rows): string
    {
        if ($rows === []) {
            return '<p class="notice">این برگه داده قابل‌نمایشی ندارد.</p>';
        }

        $maxColumn = 1;
        foreach ($rows as $row) {
            $maxColumn = max($maxColumn, max(array_keys($row)));
        }
        $html = '<div class="table-wrap"><table><tbody>';
        foreach ($rows as $number => $row) {
            $html .= '<tr><td class="rownum">'.$number.'</td>';
            for ($column = 1; $column <= $maxColumn; $column++) {
                $html .= '<td>'.$this->escape((string) ($row[$column] ?? '')).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
