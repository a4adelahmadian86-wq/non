<?php

namespace App\Http\Controllers;

use App\Models\StoreProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    private function normalizedQuery(Request $request): string
    {
        $raw = $request->query('q', '');
        if (! is_string($raw)) {
            return '';
        }

        $query = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($raw)) ?? '';

        return mb_substr($query, 0, 120, 'UTF-8');
    }

    public function index(Request $request)
    {
        $query = $this->normalizedQuery($request);
        $groups = mb_strlen($query, 'UTF-8') >= 2 ? $this->buildGroups($query, 12) : [];
        $total = array_sum(array_map(static fn (array $group): int => count($group['items']), $groups));

        return view('search.index', [
            'q' => $query,
            'groups' => $groups,
            'total' => $total,
            'title' => $query !== '' ? 'جستجو: '.$query.' | فراست' : 'جستجوی یکپارچه | فراست',
        ]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $query = $this->normalizedQuery($request);
        $groups = mb_strlen($query, 'UTF-8') >= 2 ? $this->buildGroups($query, 5) : [];

        return response()->json([
            'query' => $query,
            'groups' => $groups,
            'all_results_url' => mb_strlen($query, 'UTF-8') >= 2 ? route('search', ['q' => $query]) : null,
        ]);
    }

    private function buildGroups(string $query, int $limit): array
    {
        $normalized = mb_strtolower($query, 'UTF-8');
        $productItems = [];

        if (Schema::hasTable('store_products')) {
            $pattern = '%'.$query.'%';
            $products = StoreProduct::query()
                ->with('category:id,name')
                ->published()
                ->where(function ($builder) use ($pattern) {
                    $builder->where('title', 'like', $pattern)
                        ->orWhere('short_description', 'like', $pattern)
                        ->orWhere('description', 'like', $pattern)
                        ->orWhereHas('tags', fn ($tags) => $tags->where('name', 'like', $pattern));
                })
                ->orderByRaw('CASE WHEN title = ? THEN 0 WHEN title LIKE ? THEN 1 ELSE 2 END', [$query, $query.'%'])
                ->orderByDesc('featured')
                ->latest('published_at')
                ->limit($limit)
                ->get();

            $productItems = $products->map(static function (StoreProduct $product): array {
                $description = trim(strip_tags((string) ($product->short_description ?: $product->description)));

                return [
                    'title' => (string) $product->title,
                    'description' => Str::limit($description, 112),
                    'meta' => $product->category?->name ?: ($product->type === 'course' ? 'دوره آموزشی' : 'محصول دیجیتال'),
                    'url' => route('store.product', ['slug' => $product->slug]),
                    'icon' => 'fa-box-open',
                ];
            })->all();
        }

        $articleItems = collect(BlogController::searchIndex())
            ->filter(static function (array $article) use ($normalized): bool {
                $haystack = mb_strtolower(implode(' ', [
                    $article['title'],
                    $article['excerpt'],
                    $article['category'],
                    implode(' ', $article['tags']),
                ]), 'UTF-8');

                return mb_strpos($haystack, $normalized) !== false;
            })
            ->take($limit)
            ->map(static fn (array $article): array => [
                'title' => $article['title'],
                'description' => Str::limit($article['excerpt'], 112),
                'meta' => $article['category'],
                'url' => route('blog.show', ['slug' => $article['slug']]),
                'icon' => 'fa-book-open',
            ])
            ->values()
            ->all();

        $pageItems = collect($this->searchablePages())
            ->filter(static function (array $page) use ($normalized): bool {
                $haystack = mb_strtolower(implode(' ', [
                    $page['title'],
                    $page['description'],
                    $page['keywords'],
                ]), 'UTF-8');

                return mb_strpos($haystack, $normalized) !== false;
            })
            ->take($limit)
            ->map(static fn (array $page): array => [
                'title' => $page['title'],
                'description' => $page['description'],
                'meta' => 'بخش سایت',
                'url' => $page['url'],
                'icon' => $page['icon'],
            ])
            ->values()
            ->all();

        $groups = [];
        foreach ([
            ['key' => 'products', 'label' => 'محصولات', 'items' => $productItems],
            ['key' => 'articles', 'label' => 'مقاله‌ها و راهنماها', 'items' => $articleItems],
            ['key' => 'pages', 'label' => 'بخش‌های سایت', 'items' => $pageItems],
        ] as $group) {
            if ($group['items'] !== []) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    private function searchablePages(): array
    {
        return [
            [
                'title' => 'فروشگاه محصولات دیجیتال',
                'description' => 'فایل‌ها، منابع آموزشی و دوره‌های دیجیتال را بررسی کنید.',
                'keywords' => 'فروشگاه محصول فایل دانلود خرید کتاب دوره',
                'url' => route('store'),
                'icon' => 'fa-store',
            ],
            [
                'title' => 'تایپ و ویرایش سند',
                'description' => 'برای تایپ، ویرایش، OCR، تبدیل گفتار به متن و کار با سند شروع کنید.',
                'keywords' => 'تایپ ویرایش سند ورد word docx pdf OCR تبدیل صوت گفتار متن هوش مصنوعی',
                'url' => route('editor'),
                'icon' => 'fa-pen-ruler',
            ],
            [
                'title' => 'قیمت و تعرفه خدمات',
                'description' => 'هزینه و جزئیات خدمات را پیش از شروع بررسی کنید.',
                'keywords' => 'قیمت تعرفه هزینه پرداخت اعتبار خدمات',
                'url' => route('pricing'),
                'icon' => 'fa-tags',
            ],
            [
                'title' => 'مجله و راهنماهای فراست',
                'description' => 'مقاله‌ها و راهنماهای کاربردی درباره اسناد و بهره‌وری.',
                'keywords' => 'مجله مقاله راهنما آموزش یادگیری',
                'url' => route('blog'),
                'icon' => 'fa-book-open',
            ],
            [
                'title' => 'انجمن گفتگو',
                'description' => 'پرسش‌ها و تجربه‌های کاربران را دنبال کنید.',
                'keywords' => 'انجمن گفتگو پرسش پاسخ جامعه کاربران',
                'url' => route('community.index'),
                'icon' => 'fa-comments',
            ],
        ];
    }
}
