<?php

namespace App\Http\Controllers;

use App\Models\StoreCategory;
use App\Models\StoreTag;
use App\Services\AuditEventService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminStoreTaxonomyController extends Controller
{
    public function index()
    {
        $categories = StoreCategory::query()
            ->with('parent:id,name')
            ->withCount(['products', 'children'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $tags = StoreTag::query()
            ->withCount('products')
            ->orderBy('name')
            ->get();

        return view('admin.store.taxonomy', compact('categories', 'tags'));
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'slug' => 'nullable|string|max:180',
            'parent_id' => 'nullable|integer|exists:store_categories,id',
            'description' => 'nullable|string|max:5000',
            'icon' => 'nullable|string|max:80',
            'seo_title' => 'nullable|string|max:180',
            'seo_description' => 'nullable|string|max:320',
            'sort_order' => 'nullable|integer|min:0|max:999999',
        ]);

        $slug = Str::slug($data['slug'] ?: $data['name']) ?: 'cat-'.Str::random(6);
        $category = DB::transaction(function () use ($data, $slug): StoreCategory {
            $category = StoreCategory::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $data['name'],
                    'parent_id' => $data['parent_id'] ?? null,
                    'description' => $data['description'] ?? null,
                    'icon' => $data['icon'] ?? null,
                    'seo_title' => $data['seo_title'] ?? null,
                    'seo_description' => $data['seo_description'] ?? null,
                    'is_active' => true,
                    'sort_order' => (int) ($data['sort_order'] ?? 0),
                ]
            );
            if ($category->wasRecentlyCreated) {
                $this->audit('store.category.created', 'store_category', $category->id, ['slug' => $category->slug]);
            }

            return $category;
        });

        if ($request->expectsJson()) {
            return response()->json(['id' => $category->id, 'name' => $category->name], 201);
        }

        return redirect()->route('admin.store.taxonomy.index')->with('status', 'دسته‌بندی ایجاد شد.');
    }

    public function updateCategory(Request $request, StoreCategory $category)
    {
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'slug' => 'required|string|max:180|unique:store_categories,slug,'.$category->id,
            'parent_id' => 'nullable|integer|exists:store_categories,id',
            'description' => 'nullable|string|max:5000',
            'icon' => 'nullable|string|max:80',
            'seo_title' => 'nullable|string|max:180',
            'seo_description' => 'nullable|string|max:320',
            'is_active' => 'required|boolean',
            'sort_order' => 'required|integer|min:0|max:999999',
        ]);

        DB::transaction(function () use ($category, $data): void {
            $locked = StoreCategory::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $this->assertParentDoesNotCreateCycle($locked, isset($data['parent_id']) ? (int) $data['parent_id'] : null);
            $locked->update($data);
            $this->audit('store.category.updated', 'store_category', $locked->id, [
                'parent_id' => $locked->parent_id,
                'is_active' => $locked->is_active,
                'sort_order' => $locked->sort_order,
            ]);
        }, 3);

        return redirect()->route('admin.store.taxonomy.index')->with('status', 'دسته‌بندی به‌روزرسانی شد؛ محصولات متصل به آن حفظ شدند.');
    }

    public function storeTag(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:120',
        ]);

        $base = Str::slug($data['slug'] ?: $data['name']) ?: 'tag-'.Str::random(6);
        $slug = $base;
        $suffix = 1;
        while (StoreTag::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }
        $tag = DB::transaction(function () use ($data, $slug): StoreTag {
            $tag = StoreTag::firstOrCreate(['slug' => $slug], ['name' => $data['name']]);
            if ($tag->wasRecentlyCreated) {
                $this->audit('store.tag.created', 'store_tag', $tag->id, ['slug' => $tag->slug]);
            }

            return $tag;
        });

        if ($request->expectsJson()) {
            return response()->json(['id' => $tag->id, 'name' => $tag->name], 201);
        }

        return redirect()->route('admin.store.taxonomy.index')->with('status', 'برچسب ایجاد شد.');
    }

    public function updateTag(Request $request, StoreTag $tag)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:120|unique:store_tags,slug,'.$tag->id,
        ]);

        DB::transaction(function () use ($tag, $data): void {
            $locked = StoreTag::query()->whereKey($tag->id)->lockForUpdate()->firstOrFail();
            $locked->update($data);
            $this->audit('store.tag.updated', 'store_tag', $locked->id, ['slug' => $locked->slug]);
        }, 3);

        return redirect()->route('admin.store.taxonomy.index')->with('status', 'برچسب به‌روزرسانی شد و ارتباط آن با محصولات حفظ شد.');
    }

    public function destroyTag(StoreTag $tag)
    {
        DB::transaction(function () use ($tag): void {
            $locked = StoreTag::query()->whereKey($tag->id)->lockForUpdate()->firstOrFail();
            if ($locked->products()->exists()) {
                throw ValidationException::withMessages([
                    'tag' => 'این برچسب به محصول متصل است؛ ابتدا آن را از محصولات جدا کنید یا نامش را تغییر دهید.',
                ]);
            }
            $this->audit('store.tag.deleted', 'store_tag', $locked->id, ['slug' => $locked->slug]);
            $locked->delete();
        }, 3);

        return redirect()->route('admin.store.taxonomy.index')->with('status', 'برچسب استفاده‌نشده حذف شد.');
    }

    private function assertParentDoesNotCreateCycle(StoreCategory $category, ?int $parentId): void
    {
        $visited = [];
        $cursor = $parentId === null
            ? null
            : StoreCategory::query()->whereKey($parentId)->lockForUpdate()->firstOrFail();

        while ($cursor) {
            if ((int) $cursor->id === (int) $category->id || isset($visited[$cursor->id])) {
                throw ValidationException::withMessages([
                    'parent_id' => 'این والد باعث ایجاد چرخه در ساختار دسته‌بندی‌ها می‌شود.',
                ]);
            }
            $visited[$cursor->id] = true;
            $cursor = $cursor->parent_id
                ? StoreCategory::query()->whereKey($cursor->parent_id)->lockForUpdate()->first()
                : null;
        }
    }

    private function audit(string $eventType, string $entityType, int $entityId, array $meta = []): void
    {
        app(AuditEventService::class)->record(
            $eventType,
            auth()->id(),
            null,
            $entityType,
            $entityId,
            $meta,
            'admin_console'
        );
    }
}
