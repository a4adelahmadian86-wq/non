<?php

namespace App\Services;

use App\Models\StoreProduct;
use Illuminate\Support\Collection;

class ProductRelatedProductsService
{
    /**
     * Recommend products using explicit catalog signals: category and shared tags.
     *
     * @return Collection<int, StoreProduct>
     */
    public function recommend(StoreProduct $product, int $limit = 8): Collection
    {
        $tagIds = $product->tags()->pluck('store_tags.id')->map(fn ($id) => (int) $id)->all();
        if (! $product->category_id && $tagIds === []) {
            return collect();
        }

        $candidates = StoreProduct::query()
            ->where('id', '<>', $product->id)
            ->where('status', '!=', 'archived')
            ->where(function ($query) use ($product, $tagIds): void {
                if ($product->category_id) {
                    $query->where('category_id', $product->category_id);
                }

                if ($tagIds !== []) {
                    $query->orWhereHas('tags', fn ($tags) => $tags->whereIn('store_tags.id', $tagIds));
                }
            })
            ->with('tags:id')
            ->get();

        return $candidates
            ->map(function (StoreProduct $candidate) use ($product, $tagIds): array {
                $sharedTags = collect($candidate->tags->pluck('id'))
                    ->map(fn ($id) => (int) $id)
                    ->intersect($tagIds)
                    ->count();
                $score = ($product->category_id && (int) $candidate->category_id === (int) $product->category_id ? 5 : 0)
                    + ($sharedTags * 3)
                    + ($candidate->type === $product->type ? 1 : 0);

                return ['product' => $candidate, 'score' => $score];
            })
            ->filter(fn (array $item) => $item['score'] >= 5)
            ->sort(function (array $left, array $right): int {
                return ($right['score'] <=> $left['score'])
                    ?: ((int) ($right['product']->status === 'published') <=> (int) ($left['product']->status === 'published'))
                    ?: ($right['product']->updated_at <=> $left['product']->updated_at);
            })
            ->take(max(1, min($limit, 12)))
            ->pluck('product')
            ->values();
    }
}
