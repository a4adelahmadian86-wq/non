@extends('layouts.app')
@section('content')
@php($isEdit = $product->exists)
<section class="store-page" dir="rtl">
  <div class="store-container">
    <div class="store-section-head">
      <div>
        <span class="eyebrow">مدیریت فروشگاه</span>
        <h1>{{ $isEdit ? 'ویرایش محصول' : 'محصول جدید' }}</h1>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="store-outline-btn" href="{{ route('admin.store.index') }}">لیست محصولات</a>
        @if($isEdit)<a class="store-outline-btn" href="{{ route('store.product', $product->slug) }}" target="_blank">مشاهده در فروشگاه</a>@endif
      </div>
    </div>

    @if(session('status'))<div class="store-alert success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="store-alert error">{{ $errors->first() }}</div>@endif

    <form method="post" action="{{ $isEdit ? route('admin.store.update',$product) : route('admin.store.store') }}" class="store-panel" style="display:grid;gap:14px">
      @csrf
      @if($isEdit) @method('PUT') @endif

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <label>عنوان *
          <input name="title" value="{{ old('title', $product->title) }}" required maxlength="220" style="width:100%;padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">
        </label>
        <label>SKU *
          <input name="sku" value="{{ old('sku', $product->sku) }}" required maxlength="80" style="width:100%;padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">
        </label>
        <label>اسلاگ
          <input name="slug" value="{{ old('slug', $product->slug) }}" maxlength="240" style="width:100%;padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">
        </label>
        <label>دسته *
          <select name="category_id" required style="width:100%;padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">
            @foreach($categories as $c)
              <option value="{{ $c->id }}" @selected(old('category_id',$product->category_id)==$c->id)>{{ $c->name }}</option>
            @endforeach
          </select>
        </label>
        <label>قیمت (ریال) *
          <input type="number" name="price_rials" value="{{ old('price_rials', $product->price_rials ?? 0) }}" min="0" required style="width:100%;padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">
        </label>
        <label>قیمت مقایسه‌ای (ریال)
          <input type="number" name="compare_at_price_rials" value="{{ old('compare_at_price_rials', $product->compare_at_price_rials) }}" min="0" style="width:100%;padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">
        </label>
        <label>وضعیت *
          <select name="status" required style="width:100%;padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">
            @foreach(['draft'=>'پیش‌نویس','published'=>'منتشر شده','archived'=>'بایگانی'] as $val=>$lab)
              <option value="{{ $val }}" @selected(old('status',$product->status)===$val)>{{ $lab }}</option>
            @endforeach
          </select>
        </label>
        <label>تعداد صفحات پیش‌نمایش
          <input type="number" name="preview_pages" value="{{ old('preview_pages', $product->preview_pages ?? 3) }}" min="0" max="50" style="width:100%;padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">
        </label>
        <label>نسخه
          <input name="version" value="{{ old('version', $product->version) }}" maxlength="60" style="width:100%;padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">
        </label>
        <label>نوع مجوز
          <input name="license_type" value="{{ old('license_type', $product->license_type) }}" maxlength="60" style="width:100%;padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">
        </label>
        <label>ترتیب نمایش
          <input type="number" name="sort_order" value="{{ old('sort_order', $product->sort_order ?? 0) }}" min="0" style="width:100%;padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">
        </label>
        <label style="display:flex;align-items:center;gap:8px;margin-top:28px">
          <input type="checkbox" name="featured" value="1" @checked(old('featured', $product->featured))> ویژه / Featured
        </label>
      </div>

      <label>توضیح کوتاه
        <textarea name="short_description" rows="2" maxlength="2000" style="width:100%;padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">{{ old('short_description', $product->short_description) }}</textarea>
      </label>
      <label>توضیحات کامل
        <textarea name="description" rows="6" maxlength="20000" style="width:100%;padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">{{ old('description', $product->description) }}</textarea>
      </label>
      <label>مسیر کاور (اختیاری)
        <input name="cover_path" value="{{ old('cover_path', $product->cover_path) }}" maxlength="500" style="width:100%;padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">
      </label>

      <button type="submit" class="store-primary-btn" style="justify-self:start">{{ $isEdit ? 'ذخیره تغییرات' : 'ایجاد محصول' }}</button>
    </form>

    @if($isEdit)
      <div class="store-panel" style="margin-top:18px">
        <h2>فایل محصول (دانلود پس از خرید)</h2>
        <ul style="list-style:none;padding:0;margin:0 0 14px;font-size:.8rem">
          @forelse($product->files as $file)
            <li style="display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid #edf1f5">
              <span>{{ $file->original_name }} · {{ number_format($file->size_bytes/1024,1) }} KB @if($file->is_primary) <b style="color:#1769ff">اصلی</b>@endif</span>
              <form method="post" action="{{ route('admin.store.files.delete',[$product,$file]) }}" onsubmit="return confirm('حذف فایل؟')">@csrf @method('DELETE')
                <button type="submit" class="store-outline-btn" style="color:#c62828;padding:4px 10px">حذف</button>
              </form>
            </li>
          @empty
            <li style="color:#6b7c93">هنوز فایلی آپلود نشده.</li>
          @endforelse
        </ul>
        <form method="post" action="{{ route('admin.store.files.upload',$product) }}" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          @csrf
          <input type="file" name="file" required>
          <label style="display:flex;gap:6px;align-items:center;font-size:.78rem"><input type="checkbox" name="is_primary" value="1" checked> فایل اصلی</label>
          <button type="submit" class="store-primary-btn">آپلود فایل</button>
        </form>
      </div>

      <div class="store-panel" style="margin-top:18px">
        <h2>پیش‌نمایش (خوانشگر)</h2>
        <p style="font-size:.75rem;color:#6b7c93;margin:0 0 12px">تصویر یا PDF صفحات نمونه — در خوانشگر تمام‌صفحه نمایش داده می‌شود.</p>
        <div class="store-preview-grid" style="margin-bottom:14px">
          @forelse($product->previews as $preview)
            <div style="position:relative">
              @if(($preview->kind ?? '')==='pdf' || str_contains((string)$preview->mime,'pdf'))
                <div style="aspect-ratio:3/4;display:grid;place-items:center;background:#f0f4fa;border-radius:8px;border:1px solid #e2e9f2;font-size:.7rem">PDF · صفحه {{ $preview->page_number }}</div>
              @else
                <img src="{{ route('store.preview',$preview) }}" alt="" style="width:100%;aspect-ratio:3/4;object-fit:cover;border-radius:8px;border:1px solid #e2e9f2">
              @endif
              <form method="post" action="{{ route('admin.store.previews.delete',[$product,$preview]) }}" onsubmit="return confirm('حذف پیش‌نمایش؟')" style="margin-top:6px">@csrf @method('DELETE')
                <button type="submit" class="store-outline-btn" style="width:100%;color:#c62828;padding:4px;font-size:.65rem">حذف</button>
              </form>
            </div>
          @empty
            <div class="store-preview-empty" style="grid-column:1/-1">هنوز پیش‌نمایشی نیست.</div>
          @endforelse
        </div>
        <form method="post" action="{{ route('admin.store.previews.upload',$product) }}" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          @csrf
          <input type="file" name="preview" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf" required>
          <input type="number" name="page_number" min="1" max="500" placeholder="شماره صفحه" style="width:120px;padding:8px;border:1px solid #e2e9f2;border-radius:8px">
          <button type="submit" class="store-primary-btn">آپلود پیش‌نمایش</button>
        </form>
        @if($product->previews->isNotEmpty())
          <p style="margin-top:12px"><a class="store-outline-btn" href="{{ route('store.reader',$product->slug) }}" target="_blank">باز کردن خوانشگر</a></p>
        @endif
      </div>

      <div class="store-panel" style="margin-top:18px">
        <h2>دسته جدید</h2>
        <form method="post" action="{{ route('admin.store.categories.store') }}" style="display:flex;gap:10px;flex-wrap:wrap">
          @csrf
          <input name="name" required placeholder="نام دسته" style="padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">
          <input name="slug" placeholder="اسلاگ (اختیاری)" style="padding:10px;border:1px solid #e2e9f2;border-radius:10px;font:inherit">
          <button type="submit" class="store-outline-btn">افزودن دسته</button>
        </form>
      </div>
    @endif
  </div>
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20260929r">
@endpush
