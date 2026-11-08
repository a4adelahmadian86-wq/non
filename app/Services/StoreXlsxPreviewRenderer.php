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
    private const MAX_STYLES = 10000;

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
                $styles = $this->readStyles($zip);
                $sheets = (new DOMXPath($workbook))->query('//*[local-name()="sheets"]/*[local-name()="sheet"]');

                if (!$sheets || $sheets->length < 1 || $sheets->length > self::MAX_SHEETS) {
                    throw new RuntimeException('Workbook sheet count is outside supported limits.');
                }

                $safeIdentity = $this->escape($identity !== '' ? $identity : 'حساب کاربر');
                $tables = [];
                $tabs = [];
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
                    $rows = $this->readWorksheet($xml, $sharedStrings, $styles, $cellCount);
                    $name = $this->escape($sheet->getAttribute('name') ?: 'Sheet '.($sheetIndex + 1));
                    $sheetId = 'sheet-'.($sheetIndex + 1);
                    $tabs[] = '<a href="#'.$sheetId.'">'.$name.'</a>';
                    $tables[] = '<section class="sheet" id="'.$sheetId.'"><h2>'.($sheetIndex + 1).'. '.$name.'</h2>'.$this->table($rows).'</section>';
                }

                if ($tables === []) {
                    throw new RuntimeException('Workbook has no readable worksheets.');
                }

                $marks = '';
                if ($identity !== '') {
                    for ($i = 0; $i < 12; $i++) {
                        $top = 8 + (($i % 4) * 25);
                        $left = 3 + (intdiv($i, 4) * 32);
                        $marks .= '<span style="top:'.$top.'%;left:'.$left.'%">'.$safeIdentity.' · پیش‌نمایش فراست</span>';
                    }
                }

                return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>پیش‌نمایش Excel</title><style>
                    *{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Tahoma,Arial,sans-serif;color:#172033;background:#f3f6fb}
                    body{padding:24px 18px 50px;font-size:13px;line-height:1.8;user-select:none;-webkit-user-select:none}
                    .workbook{max-width:1200px;margin:auto;position:relative;background:#fff;border:1px solid #dce4ef;border-radius:14px;padding:24px;box-shadow:0 12px 40px rgba(15,35,65,.08);overflow:hidden}
                    .intro{position:relative;z-index:2;padding:0 0 16px;border-bottom:1px solid #e7edf5}.intro h1{font-size:18px;margin:0 0 5px}.intro p{margin:0;color:#64748b;font-size:12px}
                    .sheet-nav{position:relative;z-index:2;display:flex;gap:7px;flex-wrap:wrap;margin-top:14px}.sheet-nav a{display:inline-block;padding:6px 10px;border:1px solid #dce4ef;border-radius:7px;background:#f8fafc;color:#245b87;text-decoration:none;font-size:11px}.sheet-nav a:focus{outline:2px solid #3b82f6;outline-offset:2px}.sheet{position:relative;z-index:1;margin-top:24px;scroll-margin-top:12px}.sheet h2{font-size:14px;margin:0 0 10px;color:#1d4d79}
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
                </style></head><body><div class="watermark">'.$marks.'</div><main class="workbook"><header class="intro"><h1>پیش‌نمایش کتاب کار Excel</h1><p>حالت فقط‌خواندنی · '.count($tables).' برگه · فرمول‌ها اجرا نمی‌شوند و فقط مقدار ذخیره‌شده نمایش داده می‌شود.</p></header><nav class="sheet-nav" aria-label="برگه‌های فایل Excel">'.implode('', $tabs).'</nav>'.implode('', $tables).'<p class="notice">این سند برای مطالعه ارائه شده است. ویرایش، اجرای فرمول و دریافت فایل از این محیط پشتیبانی نمی‌شود. برای هر برگه حداکثر ۲۰۰۰ ردیف و ۴۰ ستون نمایش داده می‌شود؛ قالب‌بندی پیشرفته، نمودارها و تصاویر ممکن است بازتولید نشوند.</p></main></body></html>';
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
            foreach ($xpath->query('.//*[local-name()="t"]', $item) as $text) {
                $value .= $text->textContent;
                if (strlen($value) > 100000) {
                    throw new RuntimeException('A workbook text value is too large.');
                }
            }
            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * Read a deliberately small, safe subset of Excel cell formatting.
     * No workbook-provided CSS or markup is ever copied into the preview.
     */
    private function readStyles(ZipArchive $zip): array
    {
        $index = $zip->locateName('xl/styles.xml');
        if ($index === false) {
            return [];
        }
        $contents = $zip->getFromIndex($index);
        if (!is_string($contents) || strlen($contents) > 4 * 1024 * 1024) {
            throw new RuntimeException('Workbook styles part is too large.');
        }
        $xml = $this->parseXml($contents);
        $xpath = new DOMXPath($xml);
        foreach (['numFmts' => 1024, 'fonts' => 1024, 'fills' => 1024, 'borders' => 1024, 'cellXfs' => self::MAX_STYLES] as $collection => $limit) {
            $nodes = $xpath->query('//*[local-name()="'.$collection.'"]/*');
            if ($nodes && $nodes->length > $limit) {
                throw new RuntimeException('Workbook style collection exceeds the supported limit.');
            }
        }

        $numberFormats = [];
        foreach ($xpath->query('//*[local-name()="numFmts"]/*[local-name()="numFmt"]') as $format) {
            if (!$format instanceof DOMElement) continue;
            $id = filter_var($format->getAttribute('numFmtId'), FILTER_VALIDATE_INT);
            $code = $format->getAttribute('formatCode');
            if ($id !== false && $id >= 164 && strlen($code) <= 128) {
                $numberFormats[$id] = $code;
            }
        }

        $fonts = [];
        foreach ($xpath->query('//*[local-name()="fonts"]/*[local-name()="font"]') as $font) {
            $css = [];
            if ($xpath->query('./*[local-name()="b"]', $font)->length > 0) $css[] = 'font-weight:700';
            if ($xpath->query('./*[local-name()="i"]', $font)->length > 0) $css[] = 'font-style:italic';
            $size = $xpath->query('./*[local-name()="sz"]', $font)->item(0)?->getAttribute('val');
            if (is_numeric($size) && (float) $size >= 6 && (float) $size <= 36) $css[] = 'font-size:'.rtrim(rtrim(number_format((float) $size, 2, '.', ''), '0'), '.').'pt';
            $color = $xpath->query('./*[local-name()="color"]', $font)->item(0);
            $hex = $this->xlsxColor($color instanceof DOMElement ? $color->getAttribute('rgb') : '');
            if ($hex !== null) $css[] = 'color:'.$hex;
            $fonts[] = $css;
        }

        $fills = [];
        foreach ($xpath->query('//*[local-name()="fills"]/*[local-name()="fill"]') as $fill) {
            $pattern = $xpath->query('./*[local-name()="patternFill"]', $fill)->item(0);
            if (!$pattern instanceof DOMElement) {
                $fills[] = [];
                continue;
            }
            $type = $pattern->getAttribute('patternType');
            $color = $xpath->query('./*[local-name()="fgColor"]', $pattern)->item(0);
            $hex = $this->xlsxColor($color instanceof DOMElement ? $color->getAttribute('rgb') : '');
            $fills[] = $type === 'solid' && $hex !== null ? ['background-color:'.$hex] : [];
        }

        $borders = [];
        foreach ($xpath->query('//*[local-name()="borders"]/*[local-name()="border"]') as $border) {
            $css = [];
            foreach (['left','right','top','bottom'] as $edge) {
                $node = $xpath->query('./*[local-name()="'.$edge.'"]', $border)->item(0);
                if (!$node instanceof DOMElement) continue;
                $line = $node->getAttribute('style');
                $allowed = ['thin'=>'1px solid','medium'=>'2px solid','thick'=>'3px solid','dashed'=>'1px dashed','dotted'=>'1px dotted','double'=>'3px double','hair'=>'1px solid'];
                if (!isset($allowed[$line])) continue;
                $colorNode = $xpath->query('./*[local-name()="color"]', $node)->item(0);
                $hex = $this->xlsxColor($colorNode instanceof DOMElement ? $colorNode->getAttribute('rgb') : '');
                $css[] = 'border-'.$edge.':'.$allowed[$line].' '.($hex ?? '#cbd5e1');
            }
            $borders[] = $css;
        }

        $formats = [];
        foreach ($xpath->query('//*[local-name()="cellXfs"]/*[local-name()="xf"]') as $xf) {
            if (!$xf instanceof DOMElement) { $formats[] = ''; continue; }
            $css = [];
            $fontId = (int) $xf->getAttribute('fontId');
            $fillId = (int) $xf->getAttribute('fillId');
            $borderId = (int) $xf->getAttribute('borderId');
            foreach ($fonts[$fontId] ?? [] as $declaration) $css[] = $declaration;
            foreach ($fills[$fillId] ?? [] as $declaration) $css[] = $declaration;
            foreach ($borders[$borderId] ?? [] as $declaration) $css[] = $declaration;
            $alignment = $xpath->query('./*[local-name()="alignment"]', $xf)->item(0);
            if ($alignment instanceof DOMElement) {
                $horizontal = $alignment->getAttribute('horizontal');
                if (in_array($horizontal, ['left','center','right','justify'], true)) $css[] = 'text-align:'.$horizontal;
                $vertical = $alignment->getAttribute('vertical');
                if (in_array($vertical, ['top','center','bottom'], true)) $css[] = 'vertical-align:'.($vertical === 'center' ? 'middle' : $vertical);
                if ($alignment->getAttribute('wrapText') === '1') $css[] = 'white-space:normal';
            }
            $numFmtId = filter_var($xf->getAttribute('numFmtId'), FILTER_VALIDATE_INT);
            $formats[] = [
                'css' => implode(';', array_unique($css)),
                'numFmtId' => $numFmtId !== false ? $numFmtId : 0,
                'formatCode' => $numberFormats[$numFmtId] ?? null,
            ];
        }

        return $formats;
    }

    private function xlsxColor(string $value): ?string
    {
        $value = strtoupper(trim($value));
        if (preg_match('/^(?:[0-9A-F]{2})?([0-9A-F]{6})$/', $value, $match)) {
            return '#'.$match[1];
        }

        return null;
    }

    private function readWorksheet(DOMDocument $xml, array $sharedStrings, array $styles, int &$cellCount): array
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
                    $sharedIndex = filter_var($value, FILTER_VALIDATE_INT);
                    $value = $sharedIndex !== false && $sharedIndex >= 0 ? ($sharedStrings[$sharedIndex] ?? '') : '';
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
                $styleIndex = filter_var($cell->getAttribute('s'), FILTER_VALIDATE_INT);
                $style = $styleIndex !== false && $styleIndex >= 0 ? ($styles[$styleIndex] ?? []) : [];
                $row[$column] = [
                    'value' => $this->formatCellValue((string) $value, $style),
                    'style' => (string) ($style['css'] ?? ''),
                ];
            }
            if ($row !== []) {
                $rows[$rowNumber] = $row;
            }
        }

        ksort($rows);

        return $rows;
    }

    /**
     * Format a safe subset of Excel's built-in and custom numeric formats.
     * Unknown formats deliberately fall back to the cached raw value.
     */
    private function formatCellValue(string $value, array $style): string
    {
        if ($value === '' || !is_numeric($value)) {
            return $value;
        }

        $number = (float) $value;
        $formatId = (int) ($style['numFmtId'] ?? 0);
        $formatCode = is_string($style['formatCode'] ?? null) ? $style['formatCode'] : '';
        $builtIn = [
            9 => '0%', 10 => '0.00%', 14 => 'yyyy-mm-dd',
            15 => 'd-mmm-yy', 16 => 'd-mmm', 17 => 'mmm-yy',
            18 => 'h:mm AM/PM', 19 => 'h:mm:ss AM/PM',
            20 => 'h:mm', 21 => 'h:mm:ss', 22 => 'yyyy-mm-dd h:mm',
        ];
        $code = $formatCode !== '' ? $formatCode : ($builtIn[$formatId] ?? '');
        if ($code === '') {
            return $value;
        }

        $section = explode(';', $code, 2)[0];
        $unquoted = preg_replace('/"[^"]*"|\\\\./', '', $section) ?? $section;
        if (preg_match('/[ymdhs]/i', $unquoted)) {
            $days = (int) floor($number);
            $seconds = (int) round(($number - $days) * 86400);
            if ($seconds >= 86400) { $days++; $seconds -= 86400; }
            try {
                $date = (new \DateTimeImmutable('1899-12-30 00:00:00', new \DateTimeZone('UTC')))
                    ->modify(($days >= 0 ? '+' : '').$days.' days')
                    ->modify(($seconds >= 0 ? '+' : '').$seconds.' seconds');
                if (preg_match('/[hms]/i', $unquoted)) {
                    return $date->format(preg_match('/s/i', $unquoted) ? 'Y-m-d H:i:s' : 'Y-m-d H:i');
                }
                return $date->format('Y-m-d');
            } catch (\Throwable) {
                return $value;
            }
        }

        if (str_contains($section, '%')) {
            preg_match('/\\.([0#?]+)/', $section, $decimalMatch);
            $precision = isset($decimalMatch[1]) ? min(6, strlen($decimalMatch[1])) : 0;
            return number_format($number * 100, $precision, '.', ',').'%';
        }

        if (preg_match('/^[^;]*[0#?][^;]*$/', $section)) {
            preg_match('/\\.([0#?]+)/', $section, $decimalMatch);
            $precision = isset($decimalMatch[1]) ? min(6, strlen($decimalMatch[1])) : 0;
            $thousands = str_contains($section, ',');
            return number_format($number, $precision, '.', $thousands ? ',' : '');
        }

        return $value;
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
        $html = '<div class="table-wrap"><table><thead><tr><th>ردیف</th>';
        for ($column = 1; $column <= $maxColumn; $column++) {
            $html .= '<th>'.$this->columnLabel($column).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $number => $row) {
            $html .= '<tr><td class="rownum">'.$number.'</td>';
            for ($column = 1; $column <= $maxColumn; $column++) {
                $cell = $row[$column] ?? ['value' => '', 'style' => ''];
                $style = (string) ($cell['style'] ?? '');
                $html .= '<td'.($style !== '' ? ' style="'.$style.'"' : '').'>'.$this->escape((string) ($cell['value'] ?? '')).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    private function columnLabel(int $column): string
    {
        $label = '';
        while ($column > 0) {
            $column--;
            $label = chr(65 + ($column % 26)).$label;
            $column = intdiv($column, 26);
        }

        return $label;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
