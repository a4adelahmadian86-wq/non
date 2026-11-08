<?php

namespace App\Http\Controllers;

use App\Models\StoreCategory;
use App\Models\StoreCourseLesson;
use App\Models\StoreProduct;
use App\Models\StoreProductPreview;
use App\Models\StoreLibraryItem;
use App\Models\StoreWishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StoreController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $category = $request->query('category');
        $sort = (string) $request->query('sort', 'newest');
        $price = (string) $request->query('price', 'all');

        $base = StoreProduct::query()->with(['category', 'images'])->published();

        $productsQuery = (clone $base)
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('title', 'like', '%'.$q.'%')
                    ->orWhere('short_description', 'like', '%'.$q.'%')
                    ->orWhere('description', 'like', '%'.$q.'%')
                    ->orWhereHas('tags', fn ($tagQuery) => $tagQuery->where('name', 'like', '%'.$q.'%'));
            }))
            ->when($category, fn ($query) => $query->whereHas('category', fn ($cat) => $cat->where('slug', $category)->where('is_active', true)))
            ->when($price === 'free', fn ($query) => $query->where('price_rials', 0))
            ->when($price === 'paid', fn ($query) => $query->where('price_rials', '>', 0));

        $productsQuery = match ($sort) {
            'price_asc' => $productsQuery->orderBy('price_rials')->orderBy('title'),
            'price_desc' => $productsQuery->orderByDesc('price_rials')->orderBy('title'),
            'title' => $productsQuery->orderBy('title'),
            'featured' => $productsQuery->orderByDesc('featured')->orderBy('sort_order'),
            default => $productsQuery->orderByDesc('featured')->orderBy('sort_order')->latest('published_at'),
        };

        $products = $productsQuery->paginate(24)->withQueryString();

        $categories = StoreCategory::query()->where('is_active', true)->orderBy('sort_order')->get();
        $featured = (clone $base)->where('featured', true)->orderBy('sort_order')->limit(12)->get();
        $free = (clone $base)->where('price_rials', 0)->orderBy('sort_order')->limit(12)->get();
        $latest = (clone $base)->latest('published_at')->limit(12)->get();

        $recentIds = collect($request->session()->get('store_recent_ids', []))->take(12)->all();
        $recent = $recentIds
            ? StoreProduct::with(['category', 'images'])->published()->whereIn('id', $recentIds)->get()->sortBy(fn ($p) => array_search($p->id, $recentIds))->values()
            : collect();

        $isBrowsing = $q !== '' || $category || $price !== 'all' || $sort !== 'newest';

        return view('store.index', compact(
            'products', 'categories', 'q', 'category', 'featured', 'free', 'latest', 'recent', 'isBrowsing', 'sort', 'price'
        ));
    }

    public function product(Request $request, string $slug)
    {
        $product = StoreProduct::with(['category', 'images', 'previews', 'tags', 'related', 'course.sections.lessons' => fn ($query) => $query->publishable()])
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $ids = collect($request->session()->get('store_recent_ids', []))
            ->reject(fn ($id) => (int) $id === (int) $product->id)
            ->prepend($product->id)
            ->unique()
            ->take(12)
            ->values()
            ->all();
        $request->session()->put('store_recent_ids', $ids);

        $related = $product->related()
            ->with(['category', 'images'])
            ->published()
            ->orderByDesc('featured')
            ->limit(8)
            ->get();

        if ($related->isEmpty()) {
            $related = StoreProduct::with(['category', 'images'])
                ->published()
                ->where('id', '!=', $product->id)
                ->when($product->category_id, fn ($q) => $q->where('category_id', $product->category_id))
                ->orderByDesc('featured')
                ->limit(8)
                ->get();
        }

        $alsoViewed = StoreProduct::with(['category', 'images'])
            ->published()
            ->where('id', '!=', $product->id)
            ->whereIn('id', collect($ids)->reject(fn ($id) => (int) $id === (int) $product->id)->take(8))
            ->get();

        $inWishlist = false;
        $inLater = false;
        if ($request->user()) {
            $lists = StoreWishlist::where('user_id', $request->user()->id)
                ->where('product_id', $product->id)
                ->pluck('list_type');
            $inWishlist = $lists->contains('wishlist');
            $inLater = $lists->contains('later');
        }

        // Compute the preview collection in the controller so the view receives a stable,
        // explicit contract instead of relying on template-local assignment order.
        $showPreviews = $product->previews
            ->where('is_active', true)
            ->where('access_level', '!=', 'full')
            ->take(max(1, (int) ($product->preview_pages ?: 5)))
            ->values();
        $hasPreview = $showPreviews->isNotEmpty();
        $title = $product->seo_title ?: $product->title;

        return view('store.product', compact('product', 'related', 'alsoViewed', 'inWishlist', 'inLater', 'hasPreview', 'showPreviews', 'title'));
    }

    public function coursePreview(StoreProduct $product, StoreCourseLesson $lesson)
    {
        abort_unless(
            $product->type === 'course'
                && $product->status === 'published'
                && $product->published_at?->lte(now())
                && $product->course
                && $lesson->section()->where('course_id', $product->course->id)->exists()
                && $lesson->is_preview
                && StoreCourseLesson::query()->publishable()->whereKey($lesson->id)->exists(), 
            404
        );

        return view('store.course-preview', compact('product', 'lesson'));
    }

    public function category(string $slug)
    {
        $cat = StoreCategory::where('slug', $slug)->where('is_active', true)->firstOrFail();

        return redirect()->route('store', ['category' => $cat->slug]);
    }

    public function preview(StoreProductPreview $preview)
    {
        $preview->load('product');
        abort_unless($preview->is_active && $preview->product && $preview->product->status === 'published' && $preview->product->published_at?->lte(now()), 404);
        if (($preview->access_level ?? 'sample') === 'full') {
            $user = request()->user();
            abort_unless($user && ($preview->product->download_policy ?? 'signed') === 'reader_only', 403);
            abort_unless(StoreLibraryItem::query()->where('user_id', $user->id)->where('product_id', $preview->product_id)->whereNull('revoked_at')->exists(), 403);
        }
        $disk = Storage::disk($preview->disk ?: 'private');
        abort_unless($disk->exists($preview->path), 404);

        $headers = [
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Content-Disposition' => 'inline',
        ];

        $kind = strtolower((string) $preview->kind);
        if ($kind === 'html' || strtolower(pathinfo($preview->path, PATHINFO_EXTENSION)) === 'html') {
            $headers['Content-Type'] = 'text/html; charset=UTF-8';
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'";
            return $disk->response($preview->path, null, $headers);
        }

        abort_unless(!in_array($kind, ['docx', 'xlsx'], true), 409, 'این پیش‌نمایش هنوز آماده‌سازی نشده است؛ نسخهٔ آماده را دوباره بارگذاری کنید.');
        $headers['Content-Security-Policy'] = "default-src 'none'; frame-ancestors 'self'";
        return $disk->response($preview->path, null, $headers);
    }

    public function reader(string $slug)
    {
        $product = StoreProduct::with(['previews' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $user = request()->user();
        $samplePreviews = $product->previews->where('access_level', '!=', 'full')->values();
        $hasReaderEntitlement = $user
            && ($product->download_policy ?? 'signed') === 'reader_only'
            && StoreLibraryItem::query()->where('user_id', $user->id)->where('product_id', $product->id)->whereNull('revoked_at')->exists();
        $fullPreviews = $hasReaderEntitlement
            ? $product->previews->where('access_level', 'full')->values()
            : collect();
        $previews = $fullPreviews->isNotEmpty() ? $fullPreviews : $samplePreviews;
        abort_if($previews->isEmpty(), 404, 'پیش‌نمایش برای این محصول موجود نیست.');

        $readerIdentity = $user
            ? (trim((string) $user->mobile) !== '' ? trim((string) $user->mobile) : 'حساب #'.$user->id)
            : (string) request()->ip();

        return response()
            ->view('store.reader', compact('product', 'previews', 'readerIdentity'))
            ->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'")
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
    /**
     * Convert an uploaded DOCX preview to a read-only, sanitized HTML document.
     * The source document is never exposed through a public storage URL.
     */
    public function prepareDocxPreview(string $docxBytes): string
    {
        return $this->renderDocxPreview($docxBytes);
    }

    private function renderDocxPreview(string $docxBytes): string
    {
        abort_unless(
            class_exists(\PhpOffice\PhpWord\IOFactory::class)
                && class_exists(\Dompdf\Dompdf::class)
                && class_exists(\Dompdf\Options::class),
            503,
            'موتور نمایش Word در دسترس نیست.'
        );

        $sourcePath = tempnam(sys_get_temp_dir(), 'farast-docx-');
        $htmlPath = tempnam(sys_get_temp_dir(), 'farast-html-');

        try {
            if (file_put_contents($sourcePath, $docxBytes) === false) {
                abort(500, 'ذخیره موقت سند Word ممکن نشد.');
            }
            $this->assertSafeDocxArchive($sourcePath);
            $document = \PhpOffice\PhpWord\IOFactory::createReader('Word2007')->load($sourcePath);
            $writer = \PhpOffice\PhpWord\IOFactory::createWriter($document, 'HTML');
            $writer->save($htmlPath);
            $html = file_get_contents($htmlPath) ?: '';
            $html = $this->applyDocxPagination($html, $sourcePath);
            $pageLayout = $this->docxPageLayout($sourcePath);
            $body = $this->sanitizeDocxHtml($html);

            // Convert the source document once into a neutral PDF. The reader paints
            // the current account identity into each rendered page canvas at view time.
            $marks = '';
            $page = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><style>
                @page{size:'.$pageLayout['width'].'pt '.$pageLayout['height'].'pt;margin:'.$pageLayout['top'].'pt '.$pageLayout['right'].'pt '.$pageLayout['bottom'].'pt '.$pageLayout['left'].'pt}
                *{box-sizing:border-box}html,body{margin:0;font-family:"DejaVu Sans",Tahoma,sans-serif;color:#1f2937;background:#fff}
                body{font-size:10pt;line-height:1.85;overflow-wrap:anywhere}
                .docx-content{width:100%;direction:rtl}
                .docx-content table{border-collapse:collapse;width:100%;margin:1em 0;page-break-inside:auto}
                .docx-content tr{page-break-inside:avoid;page-break-after:auto}
                .docx-content thead{display:table-header-group}.docx-content tfoot{display:table-footer-group}
                .docx-content p{margin:.45em 0;orphans:2;widows:2}
                .docx-content li{margin:.2em 0}
                .docx-content td,.docx-content th{border:1px solid #cbd5e1;padding:6px;vertical-align:top}
                .docx-content img{max-width:100%;height:auto}
                .docx-content h1,.docx-content h2,.docx-content h3,.docx-content h4{line-height:1.5;margin:1.2em 0 .5em;page-break-after:avoid}
                .docx-content blockquote{margin:1em 1.5em;padding:0 1em;border-right:3px solid #94a3b8;color:#475569}
                .docx-watermark{position:fixed;inset:0;z-index:20;opacity:.15;pointer-events:none}.docx-watermark span{position:absolute;display:block;transform:rotate(-25deg)}
                .docx-watermark span{font:700 10px "DejaVu Sans",Tahoma,sans-serif;color:#334155;white-space:nowrap;transform:rotate(-25deg);background:rgba(255,255,255,.18);padding:5px}
            </style></head><body><div class="docx-watermark">'.$marks.'</div><main class="docx-content">'.$body.'</main></body></html>';

            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('isJavascriptEnabled', false);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('dpi', 96);

            $renderer = new \Dompdf\Dompdf($options);
            $renderer->setPaper([0, 0, $pageLayout['width'], $pageLayout['height']]);
            $renderer->loadHtml($page, 'UTF-8');
            $renderer->render();

            $canvas = $renderer->getCanvas();
            $font = $renderer->getFontMetrics()->getFont('DejaVu Sans', 'normal');
            $canvas->page_text(max(12, ($pageLayout['width'] / 2) - 25), max(12, $pageLayout['height'] - 16), '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.38, 0.43, 0.52]);

            return $renderer->output();
        } catch (\Throwable $exception) {
            report($exception);
            abort(422, 'نمایش این فایل Word ممکن نیست. لطفاً فایل DOCX سالم‌تری بارگذاری کنید.');
        } finally {
            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }
            if (is_file($htmlPath)) {
                @unlink($htmlPath);
            }
        }
    }

    /**
     * Restore pagination cues that PHPWord's HTML writer does not emit.
     * Paragraph text is matched in document order so empty page-break paragraphs
     * do not shift the mapping between DOCX and generated HTML.
     */
    private function applyDocxPagination(string $html, string $docxPath): string
    {
        $archive = new \ZipArchive();
        if ($archive->open($docxPath) !== true) {
            return $html;
        }

        try {
            $xmlBytes = $archive->getFromName('word/document.xml');
        } finally {
            $archive->close();
        }
        if (!is_string($xmlBytes) || $xmlBytes === '') {
            return $html;
        }

        $wordNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $source = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $source->loadXML($xmlBytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $html;
        }

        $sourceXPath = new \DOMXPath($source);
        $sourceXPath->registerNamespace('w', $wordNs);
        $sourceParagraphs = $sourceXPath->query('//w:body//w:p');
        $sectionProperties = $sourceXPath->query('//w:sectPr');
        $finalSectionProperties = $sectionProperties && $sectionProperties->length > 0
            ? $sectionProperties->item($sectionProperties->length - 1)
            : null;
        if (!$sourceParagraphs || $sourceParagraphs->length === 0) {
            return $html;
        }

        $htmlDocument = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $htmlLoaded = $htmlDocument->loadHTML('<?xml encoding="UTF-8"><div id="farast-docx-pagination-root">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$htmlLoaded) {
            return $html;
        }

        $htmlXPath = new \DOMXPath($htmlDocument);
        $htmlParagraphs = $htmlXPath->query('//*[@id="farast-docx-pagination-root"]//p');
        if (!$htmlParagraphs || $htmlParagraphs->length === 0) {
            return $html;
        }

        $styles = [];
        $htmlCursor = 0;
        $pendingPageBreak = false;
        foreach ($sourceParagraphs as $sourceIndex => $sourceParagraph) {
            $sourceText = '';
            foreach ($sourceXPath->query('.//w:t', $sourceParagraph) as $textNode) {
                $sourceText .= $textNode->textContent;
            }
            $sourceText = $this->normalizeDocxParagraphText($sourceText);

            $pageBreakBefore = false;
            $beforeNode = $sourceXPath->query('./w:pPr/w:pageBreakBefore', $sourceParagraph)->item(0);
            if ($beforeNode instanceof \DOMElement) {
                $value = strtolower($beforeNode->getAttributeNS($wordNs, 'val'));
                $pageBreakBefore = !in_array($value, ['0', 'false', 'off', 'none'], true);
            }

            $pageBreakAfter = $sourceXPath->query('.//w:br[@w:type="page"] | .//w:lastRenderedPageBreak', $sourceParagraph)->length > 0;
            $sectionBreak = $sourceXPath->query('./w:pPr/w:sectPr', $sourceParagraph)->item(0);
            if ($sectionBreak instanceof \DOMElement
                && !($finalSectionProperties instanceof \DOMNode
                    && $sectionBreak->getNodePath() === $finalSectionProperties->getNodePath())) {
                $sectionType = $sourceXPath->query('./w:type', $sectionBreak)->item(0);
                $sectionValue = $sectionType instanceof \DOMElement
                    ? strtolower($sectionType->getAttributeNS($wordNs, 'val'))
                    : 'nextpage';
                $pageBreakAfter = $pageBreakAfter || !in_array($sectionValue, ['continuous'], true);
            }

            if ($sourceText === '') {
                $pendingPageBreak = $pendingPageBreak || $pageBreakBefore || $pageBreakAfter;
                continue;
            }

            $matchedIndex = null;
            for ($index = $htmlCursor; $index < $htmlParagraphs->length; $index++) {
                $htmlText = $this->normalizeDocxParagraphText($htmlParagraphs->item($index)->textContent);
                if ($htmlText === '') {
                    continue;
                }
                if ($htmlText === $sourceText
                    || (mb_strlen($sourceText) >= 3 && str_contains($htmlText, $sourceText))
                    || (mb_strlen($htmlText) >= 3 && str_contains($sourceText, $htmlText))) {
                    $matchedIndex = $index;
                    break;
                }
            }

            if ($matchedIndex === null) {
                $matchedIndex = min($sourceIndex, $htmlParagraphs->length - 1);
            }
            if ($pendingPageBreak || $pageBreakBefore) {
                $styles[$matchedIndex]['before'] = true;
            }
            if ($pageBreakAfter) {
                $styles[$matchedIndex]['after'] = true;
            }
            $pendingPageBreak = false;
            $htmlCursor = $matchedIndex + 1;
        }

        if ($styles === []) {
            return $html;
        }

        $paragraphIndex = 0;
        return preg_replace_callback('/<p\b([^>]*)>/i', function (array $matches) use (&$paragraphIndex, $styles): string {
            $index = $paragraphIndex++;
            if (!isset($styles[$index])) {
                return $matches[0];
            }

            $attributes = $matches[1];
            $style = '';
            if (preg_match('/\sstyle\s*=\s*(["\'])(.*?)\1/is', $attributes, $styleMatch) === 1) {
                $style = $styleMatch[2];
                $attributes = preg_replace('/\sstyle\s*=\s*(["\'])(.*?)\1/is', '', $attributes, 1) ?? $attributes;
            }
            $declarations = [];
            if (!empty($styles[$index]['before'])) {
                $declarations[] = 'page-break-before:always';
            }
            if (!empty($styles[$index]['after'])) {
                $declarations[] = 'page-break-after:always';
            }
            $style = trim($style);
            $style = ($style !== '' ? rtrim($style, ';').';' : '').implode(';', $declarations);
            $attributes .= ' style="'.htmlspecialchars($style, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';

            return '<p'.$attributes.'>';
        }, $html) ?? $html;
    }

    private function normalizeDocxParagraphText(string $text): string
    {
        $text = str_replace(["\r", "\n", "\t", "\u{00A0}"], ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Preserve the document's first section page size and margins when converting to PDF.
     */
    private function docxPageLayout(string $docxPath): array
    {
        $layout = [
            'width' => 595.28,
            'height' => 841.89,
            'top' => 62.36,
            'right' => 51.02,
            'bottom' => 62.36,
            'left' => 51.02,
        ];
        $archive = new \ZipArchive();
        if ($archive->open($docxPath) !== true) {
            return $layout;
        }
        try {
            $xmlBytes = $archive->getFromName('word/document.xml');
        } finally {
            $archive->close();
        }
        if (!is_string($xmlBytes) || $xmlBytes === '') {
            return $layout;
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xmlBytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $layout;
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $section = $xpath->query('//w:sectPr')->item(0);
        if (!$section instanceof \DOMElement) {
            return $layout;
        }

        $pageSize = $xpath->query('./w:pgSz', $section)->item(0);
        if ($pageSize instanceof \DOMElement) {
            $width = (int) $pageSize->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w');
            $height = (int) $pageSize->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'h');
            if ($width >= 4000 && $width <= 40000 && $height >= 4000 && $height <= 40000) {
                $layout['width'] = $width / 20;
                $layout['height'] = $height / 20;
            }
        }

        $margins = $xpath->query('./w:pgMar', $section)->item(0);
        if ($margins instanceof \DOMElement) {
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                $twips = (int) $margins->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', $side);
                if ($twips >= 0 && $twips <= 7200) {
                    $layout[$side] = $twips / 20;
                }
            }
        }

        return $layout;
    }

    /**
     * Reject malformed or oversized DOCX archives before passing them to PHPWord.
     */
    private function assertSafeDocxArchive(string $path): void
    {
        abort_unless(class_exists(\ZipArchive::class), 503, 'پشتیبانی ZIP در سرور فعال نیست.');

        $archive = new \ZipArchive();
        if ($archive->open($path) !== true) {
            abort(422, 'ساختار فایل Word معتبر نیست.');
        }

        try {
            if ($archive->numFiles < 1 || $archive->numFiles > 5000) {
                abort(422, 'تعداد اجزای فایل Word از حد مجاز بیشتر است.');
            }

            $uncompressedBytes = 0;
            $compressedBytes = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                if (!is_array($entry)) {
                    abort(422, 'ساختار فایل Word قابل خواندن نیست.');
                }

                $name = (string) ($entry['name'] ?? '');
                if ($name === '' || str_starts_with($name, '/') || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $name)) {
                    abort(422, 'مسیر داخلی نامعتبر در فایل Word شناسایی شد.');
                }

                $uncompressedBytes += (int) ($entry['size'] ?? 0);
                $compressedBytes += (int) ($entry['comp_size'] ?? 0);
                if ($uncompressedBytes > 100 * 1024 * 1024) {
                    abort(422, 'حجم بازشده فایل Word از حد مجاز بیشتر است.');
                }
            }

            if ($compressedBytes > 0 && $uncompressedBytes > max(20 * 1024 * 1024, $compressedBytes * 200)) {
                abort(422, 'نسبت فشرده‌سازی فایل Word غیرعادی است.');
            }

            if ($archive->locateName('[Content_Types].xml') === false || $archive->locateName('word/document.xml') === false) {
                abort(422, 'فایل بارگذاری‌شده یک سند DOCX معتبر نیست.');
            }

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                $name = (string) ($entry['name'] ?? '');
                if (!str_ends_with(strtolower($name), '.rels')) {
                    continue;
                }

                $relationships = $archive->getFromIndex($index);
                if (!is_string($relationships)) {
                    continue;
                }
                if (strlen($relationships) > 2 * 1024 * 1024) {
                    abort(422, 'اندازه پیوندهای داخلی فایل Word از حد مجاز بیشتر است.');
                }

                $xml = new \DOMDocument();
                $previous = libxml_use_internal_errors(true);
                $loaded = $xml->loadXML($relationships, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
                if (!$loaded) {
                    abort(422, 'پیوندهای داخلی فایل Word معتبر نیستند.');
                }

                $xpath = new \DOMXPath($xml);
                if ($xpath->query('//*[local-name()="Relationship" and translate(normalize-space(@TargetMode), "EXTERNAL", "external")="external"]')->length > 0) {
                    abort(422, 'پیوند خارجی در فایل Word مجاز نیست.');
                }
            }
        } finally {
            $archive->close();
        }
    }

    /**
     * Keep document structure while dropping active content, remote resources and event attributes.
     */
    private function sanitizeDocxStyle(string $style): string
    {
        $allowed = ['color','background-color','font-family','font-size','font-weight','font-style','text-decoration','text-align','vertical-align','width','height','margin-left','margin-right','margin-top','margin-bottom','padding','border','border-collapse','page-break-before','page-break-after','line-height','direction'];
        $safe = [];
        foreach (explode(';', $style) as $declaration) {
            if (!str_contains($declaration, ':')) {
                continue;
            }
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);
            if (!in_array($property, $allowed, true) || $value === '' || preg_match('/url\\s*\\(|expression|javascript|@import/i', $value)) {
                continue;
            }
            if (!preg_match('/^[#(),.%\\w\\s\\-]+$/u', $value)) {
                continue;
            }
            $safe[] = $property.':'.$value;
        }

        return implode(';', $safe);
    }

    private function sanitizeDocxHtml(string $html): string
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="farast-docx-root">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $allowed = ['div','p','span','table','thead','tbody','tfoot','tr','td','th','ul','ol','li','br','strong','b','em','i','u','h1','h2','h3','h4','h5','h6','blockquote','img'];
        $xpath = new \DOMXPath($dom);
        $root = $xpath->query('//*[@id="farast-docx-root"]')->item(0);
        if (!$root) {
            return '';
        }

        // Keep embedded image work bounded independently of the DOCX ZIP size.
        $embeddedImageBytes = 0;
        $embeddedImageCount = 0;
        $walk = function (\DOMNode $node) use (&$walk, $allowed, &$embeddedImageBytes, &$embeddedImageCount): void {
            for ($child = $node->firstChild; $child; ) {
                $next = $child->nextSibling;
                if ($child instanceof \DOMElement) {
                    $tag = strtolower($child->tagName);
                    if (!in_array($tag, $allowed, true)) {
                        if (in_array($tag, ['script','style','iframe','object','embed','link','meta','form'], true)) {
                            $node->removeChild($child);
                        } else {
                            while ($child->firstChild) {
                                $node->insertBefore($child->firstChild, $child);
                            }
                            $node->removeChild($child);
                        }
                    } else {
                        $keep = [];
                        if (in_array($tag, ['td','th'], true)) {
                            $keep = ['colspan','rowspan','style'];
                        } elseif ($tag === 'img') {
                            $keep = ['src','alt','width','height'];
                        } else {
                            $keep = ['style'];
                        }
                        foreach (iterator_to_array($child->attributes) as $attribute) {
                            $name = strtolower($attribute->name);
                            $value = $attribute->value;
                            $safeImage = false;
                            if ($name === 'src' && strlen($value) <= 6 * 1024 * 1024
                                && preg_match('#^data:image/(?:png|jpe?g|gif|webp);base64,([A-Za-z0-9+/=]+)$#i', $value, $imageMatch) === 1) {
                                $imageBytes = (int) floor(strlen($imageMatch[1]) * 3 / 4);
                                $safeImage = $imageBytes <= 4 * 1024 * 1024
                                    && $embeddedImageCount < 32
                                    && ($embeddedImageBytes + $imageBytes) <= 16 * 1024 * 1024;
                                if ($safeImage) {
                                    $embeddedImageCount++;
                                    $embeddedImageBytes += $imageBytes;
                                }
                            }
                            if (!in_array($name, $keep, true) || ($name === 'src' && !$safeImage)) {
                                $child->removeAttribute($attribute->name);
                            } elseif ($name === 'style') {
                                $child->setAttribute('style', $this->sanitizeDocxStyle($value));
                            }
                        }
                        $walk($child);
                    }
                }
                $child = $next;
            }
        };
        $walk($root);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }

        return $result;
    }

}
