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
            'Content-Security-Policy' => "frame-ancestors 'self'",
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
        abort_unless(class_exists(\PhpOffice\PhpWord\IOFactory::class), 503, 'موتور نمایش Word در دسترس نیست.');

        $sourcePath = tempnam(sys_get_temp_dir(), 'farast-docx-');
        $htmlPath = tempnam(sys_get_temp_dir(), 'farast-html-');

        try {
            file_put_contents($sourcePath, $docxBytes);
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
            $marks = str_repeat('<span>'.$watermark.' · پیش‌نمایش فراست</span>', 12);

            $page = '<!doctype html><html lang="fa" dir="auto"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><style>
                *{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Tahoma,Arial,sans-serif;color:#1f2937;background:#fff}
                body{padding:clamp(18px,4vw,54px);line-height:1.9;user-select:none;-webkit-user-select:none}.docx-content{max-width:980px;margin:0 auto;position:relative;z-index:1;overflow-wrap:anywhere}
                .docx-content table{border-collapse:collapse;max-width:100%;width:100%;margin:1em 0}.docx-content p{margin:.55em 0}.docx-content mark{background:#ffe08a;color:#172033;padding:0 2px}.docx-content td,.docx-content th{border:1px solid #cbd5e1;padding:7px;vertical-align:top}
                .docx-content img{max-width:100%;height:auto}.docx-content h1,.docx-content h2,.docx-content h3{line-height:1.5;margin:1.3em 0 .6em}
                .docx-watermark{position:fixed;inset:0;z-index:5;pointer-events:none;display:grid;grid-template-columns:repeat(3,1fr);grid-template-rows:repeat(4,1fr);align-items:center;justify-items:center;opacity:.16;overflow:hidden}
                .docx-watermark span{font:700 12px Tahoma,Arial;color:#334155;white-space:nowrap;transform:rotate(-25deg);background:rgba(255,255,255,.22);padding:5px}
                @media print{body{padding:0}.docx-watermark{opacity:.28}}
            </style></head><body><div class="docx-watermark">'.$marks.'</div><main class="docx-content">'.$body.'</main></body></html>';

            return response($page, 200, $headers + ['Content-Type' => 'text/html; charset=UTF-8']);
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
                            $safeImage = $name === 'src' && str_starts_with($value, 'data:image/');
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
