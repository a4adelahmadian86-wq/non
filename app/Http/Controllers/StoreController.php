<?php

namespace App\Http\Controllers;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductPreview;
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
                    ->orWhere('description', 'like', '%'.$q.'%');
            }))
            ->when($category, fn ($query) => $query->whereHas('category', fn ($cat) => $cat->where('slug', $category)))
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
            ? StoreProduct::with('category')->published()->whereIn('id', $recentIds)->get()->sortBy(fn ($p) => array_search($p->id, $recentIds))->values()
            : collect();

        $isBrowsing = $q !== '' || $category || $price !== 'all' || $sort !== 'newest';

        return view('store.index', compact(
            'products', 'categories', 'q', 'category', 'featured', 'free', 'latest', 'recent', 'isBrowsing', 'sort', 'price'
        ));
    }

    public function product(Request $request, string $slug)
    {
        $product = StoreProduct::with(['category', 'images', 'previews', 'tags', 'related'])
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

        $related = $product->related->isNotEmpty()
            ? $product->related->take(8)
            : StoreProduct::with('category')
                ->published()
                ->where('id', '!=', $product->id)
                ->when($product->category_id, fn ($q) => $q->where('category_id', $product->category_id))
                ->orderByDesc('featured')
                ->limit(8)
                ->get();

        $alsoViewed = StoreProduct::with('category')
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

        $hasPreview = $product->previews->where('is_active', true)->isNotEmpty();

        return view('store.product', compact('product', 'related', 'alsoViewed', 'inWishlist', 'inLater', 'hasPreview'));
    }

    public function category(string $slug)
    {
        $cat = StoreCategory::where('slug', $slug)->where('is_active', true)->firstOrFail();

        return redirect()->route('store', ['category' => $cat->slug]);
    }

    public function preview(StoreProductPreview $preview)
    {
        $preview->load('product');
        abort_unless($preview->is_active && $preview->product && $preview->product->status === 'published', 404);
        $disk = Storage::disk($preview->disk ?: 'private');
        abort_unless($disk->exists($preview->path), 404);

        $headers = [
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Content-Disposition' => 'inline',
        ];

        if (($preview->kind ?? '') === 'docx' || strtolower(pathinfo($preview->path, PATHINFO_EXTENSION)) === 'docx') {
            return $this->renderDocxPreview($disk->get($preview->path), $headers);
        }

        return $disk->response($preview->path, null, $headers);
    }

    public function reader(string $slug)
    {
        $product = StoreProduct::with(['previews' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $previews = $product->previews;
        abort_if($previews->isEmpty(), 404, 'پیش‌نمایش برای این محصول موجود نیست.');

        $user = request()->user();
        $readerIdentity = trim((string) ($user->mobile ?? ''));
        if ($readerIdentity === '') {
            $readerIdentity = 'شناسه کاربر #'.$user->id;
        }

        return response()
            ->view('store.reader', compact('product', 'previews', 'readerIdentity'))
            ->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
    /**
     * Convert an uploaded DOCX preview to a read-only, sanitized HTML document.
     * The source document is never exposed through a public storage URL.
     */
    private function renderDocxPreview(string $docxBytes, array $headers)
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
            $body = $this->sanitizeDocxHtml($html);

            $user = request()->user();
            $identity = trim((string) ($user->mobile ?? ''));
            if ($identity === '') {
                $identity = 'شناسه کاربر #'.$user->id;
            }
            $watermark = e($identity);
            $marks = '';
            for ($mark = 0; $mark < 12; $mark++) {
                $top = 8 + (($mark % 4) * 25);
                $left = 4 + (intdiv($mark, 4) * 32);
                $marks .= '<span style="top:'.$top.'%;left:'.$left.'%;">'.$watermark.' · پیش‌نمایش فراست</span>';
            }

            // Convert Word content to a paginated PDF on the server. The watermark is
            // part of the generated PDF, not a removable browser overlay.
            $page = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><style>
                @page{size:A4;margin:22mm 18mm 22mm}
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
            $renderer->setPaper('A4', 'portrait');
            $renderer->loadHtml($page, 'UTF-8');
            $renderer->render();

            $canvas = $renderer->getCanvas();
            $font = $renderer->getFontMetrics()->getFont('DejaVu Sans', 'normal');
            $canvas->page_text(260, 820, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.38, 0.43, 0.52]);

            $pdfHeaders = $headers;
            $pdfHeaders['Content-Type'] = 'application/pdf';
            $pdfHeaders['Content-Disposition'] = 'inline; filename="farast-word-preview.pdf"';
            $pdfHeaders['Content-Security-Policy'] = "default-src 'none'; frame-ancestors 'self'";

            return response($renderer->output(), 200, $pdfHeaders);
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
                if (!is_string($relationships) || stripos($relationships, 'TargetMode="External"') === false) {
                    continue;
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
                if ($xpath->query('//*[local-name()="Relationship" and translate(@TargetMode, "EXTERNAL", "external")="external"]')->length > 0) {
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

        $walk = function (\DOMNode $node) use (&$walk, $allowed): void {
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
                            $safeImage = $name === 'src'
                                && strlen($value) <= 8 * 1024 * 1024
                                && preg_match('#^data:image/(?:png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$#i', $value) === 1;
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
