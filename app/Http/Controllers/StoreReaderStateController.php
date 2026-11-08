<?php

namespace App\Http\Controllers;

use App\Models\StoreProduct;
use App\Models\StoreReaderState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreReaderStateController extends Controller
{
    public function show(Request $request, StoreProduct $product): JsonResponse
    {
        $this->assertReadableProduct($product);

        $state = StoreReaderState::query()
            ->where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->first();

        return response()->json([
            'progress' => $state?->progress,
            'bookmarks' => $state?->bookmarks ?? [],
            'updated_at' => $state?->updated_at?->toISOString(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, StoreProduct $product): JsonResponse
    {
        $this->assertReadableProduct($product);

        $data = $request->validate([
            'progress' => ['nullable', 'array'],
            'progress.section' => ['required_with:progress', 'integer', 'min:0', 'max:500'],
            'progress.page' => ['required_with:progress', 'integer', 'min:1', 'max:10000'],
            'bookmarks' => ['sometimes', 'array', 'max:100'],
            'bookmarks.*.section' => ['required', 'integer', 'min:0', 'max:500'],
            'bookmarks.*.page' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        $sectionCount = $product->previews()->where('is_active', true)->count();
        $progress = $data['progress'] ?? null;
        if ($progress && $progress['section'] >= $sectionCount) {
            abort(422, 'موقعیت مطالعه معتبر نیست.');
        }

        $bookmarks = collect($data['bookmarks'] ?? [])
            ->filter(fn (array $item) => $item['section'] < $sectionCount)
            ->unique(fn (array $item) => $item['section'].':'.$item['page'])
            ->take(100)
            ->values()
            ->all();

        $state = StoreReaderState::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'product_id' => $product->id],
            ['progress' => $progress, 'bookmarks' => $bookmarks]
        );

        return response()->json([
            'saved' => true,
            'progress' => $state->progress,
            'bookmarks' => $state->bookmarks ?? [],
            'updated_at' => $state->updated_at?->toISOString(),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function assertReadableProduct(StoreProduct $product): void
    {
        abort_unless(
            $product->status === 'published'
                && $product->published_at?->lte(now())
                && $product->previews()->where('is_active', true)->exists(),
            404
        );
    }
}
