<?php

namespace App\\Console\\Commands;

use App\\Models\\StoreCategory;
use App\\Models\\StoreProduct;
use App\\Models\\StoreProductFile;
use App\\Models\\StoreProductPreview;
use Illuminate\\Console\\Command;
use Illuminate\\Support\\Facades\\Storage;
use Illuminate\\Support\\Str;
use PhpOffice\\PhpWord\\IOFactory;
use PhpOffice\\PhpWord\\PhpWord;
use ZipArchive;

class SeedReaderDemoProduct extends Command
{
    protected $signature = 'farast:seed-reader-demo {--refresh : Rebuild the sample files even when they already exist}';
    protected $description = 'Create an opt-in public demo product with PDF, DOCX and XLSX reader samples';

    public function handle(): int
    {
        if (!class_exists(ZipArchive::class) || !class_exists(\\Dompdf\\Dompdf::class) || !class_exists(IOFactory::class)) {
            $this->error('The ZIP, Dompdf and PHPWord extensions/packages are required.');
            return self::FAILURE;
        }

        $disk = Storage::disk('private');
        $directory = 'store/reader-demo';
        $disk->makeDirectory($directory);
        $assets = [
            'pdf' => [$directory.'/FARAST-Reader-Demo.pdf', 'application/pdf'],
            'docx' => [$directory.'/FARAST-Reader-Demo.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xlsx' => [$directory.'/FARAST-Reader-Demo.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        ];

        if ($this->option('refresh') || !$disk->exists($assets['pdf'][0])) {
            $disk->put($assets['pdf'][0], $this->makePdf());
        }
        if ($this->option('refresh') || !$disk->exists($assets['docx'][0])) {
            $disk->put($assets['docx'][0], $this->makeDocx());
        }
        if ($this->option('refresh') || !$disk->exists($assets['xlsx'][0])) {
            $disk->put($assets['xlsx'][0], $this->makeXlsx());
        }

        $category = StoreCategory::firstOrCreate(
            ['slug' => 'reader-demo'],
            ['name' => 'نمونه‌های خوانشگر', 'description' => 'اسناد نمونه برای آزمایش پیش‌نمایش امن', 'is_active' => true, 'sort_order' => 900]
        );
        $product = StoreProduct::updateOrCreate(
            ['slug' => 'farast-reader-demo'],
            [
                'category_id' => $category->id,
                'title' => 'نمونه آزمایشی خوانشگر FARAST',
                'sku' => 'FARAST-READER-DEMO',
                'type' => 'digital_file',
                'short_description' => 'سه فایل نمونه PDF، Word و Excel برای بررسی تجربه مطالعه امن و فقط‌خواندنی.',
                'description' => "این محصول آزمایشی برای مشاهده عملکرد خوانشگر ساخته شده است.\n\nشامل نمونه PDF، سند Word فارسی و کاربرگ Excel چندبرگه‌ای است. فایل‌ها نمونه‌اند و محتوای تجاری ندارند.",
                'price_rials' => 0,
                'status' => 'published',
                'published_at' => now(),
                'featured' => false,
                'sort_order' => 900,
                'preview_pages' => 3,
                'version' => '1.0.0',
                'seo_title' => 'نمونه آزمایشی خوانشگر PDF، Word و Excel',
                'seo_description' => 'مشاهده نمونه فایل‌های PDF، DOCX و XLSX در خوانشگر امن FARAST.',
            ]
        );

        foreach ($assets as $kind => [$filePath, $mime]) {
            $size = $disk->size($filePath);
            $absolute = $disk->path($filePath);
            $hash = hash_file('sha256', $absolute);
            StoreProductPreview::updateOrCreate(
                ['product_id' => $product->id, 'path' => $filePath],
                ['disk' => 'private', 'mime' => $mime, 'page_number' => 1, 'kind' => $kind, 'watermarked' => true, 'is_active' => true, 'sort_order' => array_search($kind, ['pdf', 'docx', 'xlsx'], true)]
            );
            if ($kind === 'pdf') {
                StoreProductFile::updateOrCreate(
                    ['product_id' => $product->id, 'purpose' => 'product_download', 'original_name' => 'FARAST-Reader-Demo.pdf'],
                    [
                        'version' => $product->version, 'disk' => 'private', 'path' => $filePath,
                        'mime' => $mime, 'size_bytes' => $size, 'sha256' => $hash,
                        'is_primary' => true, 'is_active' => true, 'scan_status' => 'clean',
                        'scan_engine' => 'trusted-demo-fixture-generator', 'scanned_at' => now(),
                    ]
                );
            }
        }

        $this->info('Demo product is ready: '.url('/store/product/'.$product->slug));
        $this->line('Authenticated reader: '.url('/store/product/'.$product->slug.'/reader'));
        $this->line('The generated files live on the private disk; no public file URLs are created.');
        return self::SUCCESS;
    }

    private function makePdf(): string
    {
        $html = '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><style>body{font-family:DejaVu Sans,Tahoma,sans-serif;line-height:2;color:#172033}h1{color:#155e75}table{border-collapse:collapse;width:100%}td,th{border:1px solid #94a3b8;padding:8px}</style><h1>نمونه PDF خوانشگر فراست</h1><p>این فایل برای آزمون نمایش PDF، زوم، جست‌وجو و واترمارک ساخته شده است.</p><p>شناسه نمونه: FARAST-DEMO-001</p><table><tr><th>ویژگی</th><th>وضعیت</th></tr><tr><td>متن فارسی و راست‌چین</td><td>نمونه</td></tr><tr><td>جدول</td><td>فعال</td></tr></table><p style="page-break-before:always">صفحه دوم برای آزمون پیمایش و شماره صفحه.</p><h2>صفحه دوم</h2><p>خوانشگر باید این صفحه را بدون بارگذاری مجدد فایل اصلی نمایش دهد.</p>';
        $options = new \\Dompdf\\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $renderer = new \\Dompdf\\Dompdf($options);
        $renderer->loadHtml($html, 'UTF-8');
        $renderer->setPaper('A4');
        $renderer->render();
        return $renderer->output();
    }

    private function makeDocx(): string
    {
        $word = new PhpWord();
        $word->setDefaultFontName('DejaVu Sans');
        $word->setDefaultFontSize(12);
        $section = $word->addSection(['marginTop' => 900, 'marginBottom' => 900]);
        $section->addTitle('نمونه سند Word فارسی', 1);
        $section->addText('این سند DOCX برای آزمون تبدیل سمت سرور، جدول، متن فارسی و واترمارک ساخته شده است.');
        $section->addTitle('جدول اطلاعات', 2);
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '94A3B8', 'cellMargin' => 100]);
        foreach ([['قابلیت', 'شرح'], ['تبدیل PDF', 'صفحه‌بندی سمت سرور'], ['حفاظت', 'نمایش فقط‌خواندنی'], ['زبان', 'فارسی و انگلیسی']] as $row) {
            $table->addRow();
            foreach ($row as $value) {
                $table->addCell(4500)->addText($value);
            }
        }
        $section->addPageBreak();
        $section->addTitle('صفحه دوم', 1);
        $section->addText('این صفحه برای بررسی شکست صفحه و شماره‌گذاری در خروجی PDF است.');
        $tmp = tempnam(sys_get_temp_dir(), 'farast-demo-docx-');
        try {
            IOFactory::createWriter($word, 'Word2007')->save($tmp);
            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function makeXlsx(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'farast-demo-xlsx-');
        $zip = new ZipArchive();
        try {
            if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
                throw new \\RuntimeException('Unable to create demo workbook.');
            }
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="گزارش" sheetId="1" r:id="rId1"/><sheet name="داده‌ها" sheetId="2" r:id="rId2"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>');
            $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>عنوان</t></is></c><c r="B1" t="inlineStr"><is><t>مقدار</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>تعداد نمونه</t></is></c><c r="B2"><v>3</v></c></row><row r="3"><c r="A3" t="inlineStr"><is><t>وضعیت</t></is></c><c r="B3" t="inlineStr"><is><t>آماده</t></is></c></row></sheetData></worksheet>');
            $zip->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>ماه</t></is></c><c r="B1" t="inlineStr"><is><t>بازدید</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>مهر</t></is></c><c r="B2"><v>120</v></c></row><row r="3"><c r="A3" t="inlineStr"><is><t>آبان</t></is></c><c r="B3"><v>185</v></c></row></sheetData></worksheet>');
            $zip->close();
            return (string) file_get_contents($tmp);
        } finally {
            if ($zip->status === ZipArchive::ER_OK) {
                $zip->close();
            }
            @unlink($tmp);
        }
    }
}
