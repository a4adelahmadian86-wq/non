<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateProductDocumentAudio;
use App\Models\ContentDiscussion;
use App\Models\StoreCategory;
use App\Models\StoreCourseLesson;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\StoreProductDocument;
use App\Models\StoreProductPreview;
use App\Models\StoreLibraryItem;
use App\Models\StoreWishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;

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
        if (Schema::hasTable('content_discussions')) {
            $discussions = ContentDiscussion::query()->where('target_type', 'product')->where('target_key', (string) $product->id)->whereNull('parent_id')->where('status', 'approved')->with(['user', 'replies' => fn ($q) => $q->where('status', 'approved')->with('user')->oldest()])->latest()->limit(30)->get();
            $discussionCounts = [
                'reviews' => ContentDiscussion::query()->where('target_type', 'product')->where('target_key', (string) $product->id)->where('kind', 'review')->whereNull('parent_id')->where('status', 'approved')->count(),
                'questions' => ContentDiscussion::query()->where('target_type', 'product')->where('target_key', (string) $product->id)->where('kind', 'question')->whereNull('parent_id')->where('status', 'approved')->count(),
            ];
            $averageRating = ContentDiscussion::query()->where('target_type', 'product')->where('target_key', (string) $product->id)->where('kind', 'review')->where('status', 'approved')->whereNotNull('rating')->avg('rating');
        } else {
            $discussions = collect();
            $discussionCounts = ['reviews' => 0, 'questions' => 0];
            $averageRating = null;
        }

        return view('store.product', compact('product', 'related', 'alsoViewed', 'inWishlist', 'inLater', 'hasPreview', 'showPreviews', 'title', 'discussions', 'discussionCounts', 'averageRating'));
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
            abort(404, 'نسخه کامل فایل از مسیر پیش‌نمایش ارائه نمی‌شود؛ از خوانشگر صفحه‌ای استفاده کنید.');
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
            $headers['Content-Disposition'] = 'inline; filename="farast-workbook-preview.html"';
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'";

            $html = $disk->get($preview->path);
            abort_unless(is_string($html), 404);
            $user = request()->user();
            $identity = $user
                ? (trim((string) $user->mobile) !== '' ? trim((string) $user->mobile) : 'حساب #'.$user->id)
                : (request()->ip() ?: 'کاربر مهمان');
            $escapedIdentity = htmlspecialchars($identity, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if (str_contains($html, '__FARAST_READER_IDENTITY__')) {
                $html = str_replace('__FARAST_READER_IDENTITY__', $escapedIdentity, $html);
            } else {
                // Backfill the dynamic mark for workbooks prepared before the placeholder was introduced.
                $marks = '';
                for ($index = 0; $index < 12; $index++) {
                    $top = 8 + (($index % 4) * 25);
                    $left = 3 + (intdiv($index, 4) * 32);
                    $marks .= '<span style="top:'.$top.'%;left:'.$left.'%">'.$escapedIdentity.' · پیش‌نمایش فراست</span>';
                }
                $emptyWatermark = '<div class="watermark"></div>';
                if (str_contains($html, $emptyWatermark)) {
                    $html = str_replace($emptyWatermark, '<div class="watermark">'.$marks.'</div>', $html);
                } elseif (str_contains($html, '</body>')) {
                    $html = str_replace('</body>', '<div class="watermark">'.$marks.'</div></body>', $html);
                }
            }

            return response($html, 200, $headers);
        }

        abort_unless(!in_array($kind, ['docx', 'xlsx'], true), 409, 'این پیش‌نمایش هنوز آماده‌سازی نشده است؛ نسخهٔ آماده را دوباره بارگذاری کنید.');
        $headers['Content-Security-Policy'] = "default-src 'none'; frame-ancestors 'self'";
        return $disk->response($preview->path, null, $headers);
    }

    public function previewAudio(StoreProductPreview $preview)
    {
        $preview->load('product');
        abort_unless(
            $preview->is_active
                && $preview->audio_path
                && $preview->product
                && $preview->product->status === 'published'
                && $preview->product->published_at?->lte(now()),
            404
        );

        if (($preview->access_level ?? 'sample') === 'full') {
            $user = request()->user();
            abort_unless($user && ($preview->product->download_policy ?? 'signed') === 'reader_only', 403);
            abort_unless(
                StoreLibraryItem::query()
                    ->where('user_id', $user->id)
                    ->where('product_id', $preview->product_id)
                    ->whereNull('revoked_at')
                    ->exists(),
                403
            );
        }

        $disk = Storage::disk($preview->audio_disk ?: 'private');
        abort_unless($disk->exists($preview->audio_path), 404);
        $size = (int) $disk->size($preview->audio_path);
        abort_unless($size > 0, 404);

        $start = 0;
        $end = $size - 1;
        $status = 200;
        $range = request()->header('Range');
        if (is_string($range) && preg_match('/^bytes=/i', $range)) {
            if (preg_match('/\\Abytes=(\\d*)-(\\d*)\\z/i', trim($range), $matches)) {
                if ($matches[1] === '' && $matches[2] !== '') {
                    $suffixLength = (int) $matches[2];
                    if ($suffixLength <= 0) {
                        return response('', 416, ['Content-Range' => 'bytes */'.$size, 'Accept-Ranges' => 'bytes']);
                    }
                    $suffix = min($size, $suffixLength);
                    $start = $size - $suffix;
                } elseif ($matches[1] !== '') {
                    $start = (int) $matches[1];
                    $end = $matches[2] !== '' ? min($size - 1, (int) $matches[2]) : $end;
                } else {
                    return response('', 416, ['Content-Range' => 'bytes */'.$size, 'Accept-Ranges' => 'bytes']);
                }

                if ($start > $end || $start >= $size) {
                    return response('', 416, ['Content-Range' => 'bytes */'.$size, 'Accept-Ranges' => 'bytes']);
                }
                $status = 206;
            }
            // Malformed or multi-range requests are ignored and served as a full response.
        }

        $length = $end - $start + 1;
        $headers = [
            'Content-Type' => $preview->audio_mime ?: 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="farast-reader-audio"',
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
        ];
        if ($status === 206) {
            $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;
        }

        return response()->stream(function () use ($disk, $preview, $start, $length) {
            $stream = $disk->readStream($preview->audio_path);
            if (! is_resource($stream)) {
                return;
            }
            try {
                $remainingSkip = $start;
                while ($remainingSkip > 0 && ! feof($stream)) {
                    $chunk = fread($stream, min(8192, $remainingSkip));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $remainingSkip -= strlen($chunk);
                }
                $remaining = $length;
                while ($remaining > 0 && ! feof($stream)) {
                    $chunk = fread($stream, min(8192, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                    if (connection_aborted()) {
                        break;
                    }
                }
            } finally {
                fclose($stream);
            }
        }, $status, $headers);
    }

    public function reader(string $slug)
    {
        $product = StoreProduct::with([
            'previews' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order'),
            'files' => fn ($query) => $query->where('purpose', 'product_download')
                ->where('is_primary', true)->where('is_active', true)->where('scan_status', 'clean')->orderByDesc('id'),
        ])->published()->where('slug', $slug)->firstOrFail();

        $user = request()->user();
        $samplePreviews = $product->previews->where('access_level', '!=', 'full')->values();
        $hasReaderEntitlement = $user
            && ($product->download_policy ?? 'signed') === 'reader_only'
            && StoreLibraryItem::query()->where('user_id', $user->id)
                ->where('product_id', $product->id)->whereNull('revoked_at')->exists();

        $readerMode = 'sample';
        $readerDocument = null;
        $readerAudioUrl = null;
        $readerAudioTiming = [];
        $readerAudioPollUrl = null;
        $readerAudioStatus = '';
        $previews = $samplePreviews;

        if ($hasReaderEntitlement && $product->type === 'digital_file') {
            $primaryFile = $product->files->first();
            $document = $primaryFile?->document;
            if ($document) {
                $document->loadMissing(['pages', 'chunks', 'artifacts']);
                $structure = (array) ($document->structure_json ?? []);
                $sourceMatches = (int) $document->product_id === (int) $product->id
                    && (int) $document->product_file_id === (int) $primaryFile->id
                    && filled($primaryFile->sha256)
                    && hash_equals((string) $primaryFile->sha256, (string) $document->source_sha256);
                $readerReady = $sourceMatches
                    && in_array($document->processing_status, ['completed', 'extracted'], true)
                    && (bool) data_get($structure, 'reader.ready', false);

                if ($readerReady && $document->pages->isNotEmpty()) {
                    $readerDocument = $document;
                    $readerMode = 'document';
                    $audioArtifact = $document->artifacts
                        ->where('artifact_type', 'audio_full_wav')
                        ->where('version', '>=', 2)
                        ->sortByDesc('version')
                        ->first(static fn ($artifact) => hash_equals((string) $document->source_sha256, (string) data_get($artifact->metadata_json, 'source_sha256', ''))
                            && Storage::disk($artifact->disk ?: 'private')->exists($artifact->path));
                    $audioArtifactReady = (bool) $audioArtifact;

                    $audioNeedsUpgrade = ! $audioArtifactReady || (int) $audioArtifact->version < 3;
                    $documentAudioConfigured = filled(config('services.document_audio.url'))
                        && filled(config('services.document_audio.token'));
                    if ($audioNeedsUpgrade && $documentAudioConfigured) {
                        GenerateProductDocumentAudio::dispatch((int) $document->id)->afterResponse();
                        $readerAudioPollUrl = route('store.reader.audio', [$product, $document]).'?version=3';
                        $readerAudioStatus = $audioArtifactReady ? 'upgrading' : 'pending';
                    } elseif (! $audioArtifactReady) {
                        $readerAudioStatus = 'unavailable';
                    } else {
                        $readerAudioStatus = 'ready';
                    }

                    if ($audioArtifactReady) {
                        $readerAudioUrl = route('store.reader.audio', [$product, $document]).'?version='.(int) $audioArtifact->version;
                        $segments = (array) data_get($audioArtifact->metadata_json, 'segments', []);
                        $chunksByIndex = $document->chunks->keyBy('chunk_index');
                        foreach ($segments as $segment) {
                            if (! is_array($segment)) continue;
                            $chunk = $chunksByIndex->get((int) ($segment['chunk_index'] ?? -1));
                            if (! $chunk) continue;
                            $locator = (array) ($chunk->locator_json ?? []);
                            $page = (int) ($locator['reader_page'] ?? $locator['page'] ?? 0);
                            $text = trim((string) ($segment['text'] ?? $chunk->content_text));
                            if ($page < 1 || $text === '') continue;
                            $readerAudioTiming[] = [
                                'text' => $text,
                                'words' => array_map(static fn (array $word) => [
                                    'text' => (string) ($word['text'] ?? ''),
                                    'start' => round(((int) ($word['start_ms'] ?? 0)) / 1000, 3),
                                    'end' => round(((int) ($word['end_ms'] ?? 0)) / 1000, 3),
                                ], array_filter((array) ($segment['words'] ?? []), 'is_array')),
                                'alignment_quality' => (string) ($segment['alignment_quality'] ?? 'estimated'),
                                'start' => round(((int) ($segment['start_ms'] ?? 0)) / 1000, 3),
                                'end' => round(((int) ($segment['end_ms'] ?? 0)) / 1000, 3),
                                'page' => $page,
                            ];
                        }
                    }

                    $previews = $document->pages->map(function ($page) use ($product, $readerAudioTiming) {
                        $label = match ($page->page_kind) {
                            'pdf_page' => 'صفحه '.$page->page_number,
                            'slide' => 'اسلاید '.$page->page_number,
                            'sheet' => (string) data_get($page->locator_json, 'sheet', 'برگه '.$page->page_number),
                            'archive_entry' => (string) data_get($page->locator_json, 'archive_entry', 'فایل داخل آرشیو'),
                            default => 'بخش '.$page->page_number,
                        };

                        return (object) [
                            'id' => $page->id,
                            'kind' => 'reader_document',
                            'mime' => 'text/plain',
                            'page_number' => $page->page_number,
                            'page_kind' => $page->page_kind,
                            'page_label' => $label,
                            'reader_page_url' => route('store.reader.page', [$product, $page->page_number]),
                        ];
                    })->values();
                }
            }
        }

        abort_if($previews->isEmpty(), 404, 'پیش‌نمایش یا نسخه آماده خوانش برای این محصول موجود نیست.');

        $readerIdentity = $user
            ? (trim((string) $user->mobile) !== '' ? trim((string) $user->mobile) : 'حساب #'.$user->id)
            : (string) request()->ip();

        return response()
            ->view('store.reader', compact(
                'product',
                'previews',
                'readerIdentity',
                'readerMode',
                'readerDocument',
                'readerAudioUrl',
                'readerAudioTiming',
                'readerAudioPollUrl',
                'readerAudioStatus'
            ))
            ->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; worker-src 'self' blob:")
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function readerPage(StoreProduct $product, int $page)
    {
        $user = request()->user();
        abort_unless(
            $user
                && $product->status === 'published'
                && $product->published_at?->lte(now())
                && $product->type === 'digital_file'
                && ($product->download_policy ?? 'signed') === 'reader_only'
                && StoreLibraryItem::query()->where('user_id', $user->id)
                    ->where('product_id', $product->id)->whereNull('revoked_at')->exists(),
            403
        );

        $file = StoreProductFile::query()
            ->where('product_id', $product->id)
            ->where('purpose', 'product_download')
            ->where('is_primary', true)
            ->where('is_active', true)
            ->where('scan_status', 'clean')
            ->firstOrFail();
        $document = $file->document;
        abort_unless(
            $document
                && hash_equals((string) $file->sha256, (string) $document->source_sha256)
                && in_array($document->processing_status, ['completed', 'extracted'], true)
                && (bool) data_get($document->structure_json, 'reader.ready', false),
            409,
            'نسخه خوانش این فایل هنوز آماده یا تأیید نشده است.'
        );

        $pageRecord = $document->pages()->where('page_number', $page)->firstOrFail();

        return response()->json([
            'page_number' => (int) $pageRecord->page_number,
            'page_count' => (int) data_get($document->structure_json, 'reader.page_count', 0),
            'page_kind' => (string) $pageRecord->page_kind,
            'sheet_name' => $pageRecord->page_kind === 'sheet'
                ? mb_substr((string) data_get($pageRecord->locator_json, 'sheet', ''), 0, 120)
                : null,
            'row_start' => $pageRecord->page_kind === 'sheet'
                ? max(1, min(1000000, (int) data_get($pageRecord->locator_json, 'row_start', 1)))
                : null,
            'row_end' => $pageRecord->page_kind === 'sheet'
                ? max(1, min(1000000, (int) data_get($pageRecord->locator_json, 'row_end', 1)))
                : null,
            'label' => match ($pageRecord->page_kind) {
                'pdf_page' => 'صفحه '.$pageRecord->page_number,
                'slide' => 'اسلاید '.$pageRecord->page_number,
                'sheet' => 'برگه '.(string) data_get($pageRecord->locator_json, 'sheet', $pageRecord->page_number),
                'archive_entry' => (string) data_get($pageRecord->locator_json, 'archive_entry', 'فایل داخل آرشیو'),
                default => 'بخش '.$pageRecord->page_number,
            },
            'content_blocks' => collect(
                data_get($pageRecord->locator_json, 'content_blocks', [])
            )->take(120)->map(function ($block) {
                if (! is_array($block)) {
                    return null;
                }
                $type = (string) ($block['type'] ?? '');
                if (! in_array($type, ['heading', 'paragraph', 'list_item', 'table_row'], true)) {
                    return null;
                }

                $safe = [
                    'type' => $type,
                    'text' => mb_substr((string) ($block['text'] ?? ''), 0, 1800),
                ];
                if ($type === 'heading') {
                    $safe['level'] = max(1, min(6, (int) ($block['level'] ?? 2)));
                } elseif ($type === 'list_item') {
                    $safe['list_kind'] = ($block['list_kind'] ?? '') === 'number' ? 'number' : 'bullet';
                    $safe['list_level'] = max(1, min(5, (int) ($block['list_level'] ?? 1)));
                } elseif ($type === 'table_row') {
                    $safe['cells'] = array_slice(array_map(
                        fn ($cell) => mb_substr((string) $cell, 0, 500),
                        is_array($block['cells'] ?? null) ? $block['cells'] : []
                    ), 0, 50);
                    $safe['table_index'] = max(1, min(100, (int) ($block['table_index'] ?? 1)));
                    $safe['row_index'] = max(1, min(1000, (int) ($block['row_index'] ?? 1)));
                }
                return $safe;
            })->filter()->values()->all(),
            'content_text' => (string) $pageRecord->content_text,
            'has_extracted_text' => (bool) $pageRecord->has_extracted_text,
            'content_sha256' => (string) $pageRecord->content_sha256,
        ])->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Cross-Origin-Resource-Policy', 'same-origin');
    }

    public function readerAudio(StoreProduct $product, StoreProductDocument $document)
    {
        $user = request()->user();
        abort_unless(
            $user
                && $product->status === 'published'
                && $product->published_at?->lte(now())
                && $product->type === 'digital_file'
                && ($product->download_policy ?? 'signed') === 'reader_only'
                && StoreLibraryItem::query()->where('user_id', $user->id)
                    ->where('product_id', $product->id)->whereNull('revoked_at')->exists(),
            403
        );

        $file = StoreProductFile::query()
            ->where('product_id', $product->id)
            ->where('purpose', 'product_download')
            ->where('is_primary', true)
            ->where('is_active', true)
            ->where('scan_status', 'clean')
            ->firstOrFail();
        abort_unless(
            (int) $document->product_id === (int) $product->id
                && (int) $document->product_file_id === (int) $file->id
                && hash_equals((string) $file->sha256, (string) $document->source_sha256)
                && in_array($document->processing_status, ['completed', 'extracted'], true),
            404
        );

        abort_unless((bool) data_get($document->structure_json, 'reader.ready', false), 409);
        $artifactQuery = $document->artifacts()->where('artifact_type', 'audio_full_wav');
        $requestedVersion = (int) request()->query('version', 0);
        if ($requestedVersion >= 2) {
            $artifactQuery->where('version', $requestedVersion);
        } else {
            $artifactQuery->where('version', '>=', 2)->orderByDesc('version');
        }
        $artifact = $artifactQuery->get()
            ->first(static fn ($candidate) => hash_equals((string) $document->source_sha256, (string) data_get($candidate->metadata_json, 'source_sha256', ''))
                && Storage::disk($candidate->disk ?: 'private')->exists($candidate->path));
        abort_unless($artifact, 404);
        abort_unless(hash_equals((string) $document->source_sha256, (string) data_get($artifact->metadata_json, 'source_sha256', '')), 409);
        $disk = Storage::disk($artifact->disk ?: 'private');
        $size = (int) $disk->size($artifact->path);
        $start = 0;
        $end = max(0, $size - 1);
        $status = 200;
        $range = request()->header('Range');

        if ($range !== null) {
            if (! preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches) || $size < 1) {
                return response('', 416, ['Content-Range' => 'bytes */'.$size]);
            }
            if ($matches[1] === '' && $matches[2] !== '') {
                $suffixLength = (int) $matches[2];
                $start = max(0, $size - $suffixLength);
            } else {
                $start = (int) $matches[1];
                if ($matches[2] !== '') $end = min($end, (int) $matches[2]);
            }
            if ($start > $end || $start >= $size) {
                return response('', 416, ['Content-Range' => 'bytes */'.$size]);
            }
            $status = 206;
        }

        $length = max(0, $end - $start + 1);
        $headers = [
            'Content-Type' => $artifact->mime ?: 'audio/wav',
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline; filename="farast-reader-audio.wav"',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if ($status === 206) $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;

        // Readiness polling uses HEAD: return the same protected metadata without opening the WAV.
        if (request()->isMethod('HEAD')) {
            return response('', $status, $headers);
        }

        $stream = $disk->readStream($artifact->path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream, $start, $length) {
            if ($start > 0) fseek($stream, $start);
            $remaining = $length;
            while ($remaining > 0 && ! feof($stream)) {
                $chunk = fread($stream, min(8192, $remaining));
                if (! is_string($chunk) || $chunk === '') break;
                echo $chunk;
                $remaining -= strlen($chunk);
                flush();
            }
            fclose($stream);
        }, $status, $headers);
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
