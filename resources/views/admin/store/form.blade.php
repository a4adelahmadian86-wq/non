@extends('layouts.app')
@section('content')
@php($isEdit = $product->exists)
@include('admin.store.partials.workspace-nav')
<section class="store-page" dir="rtl">
  <div class="store-container product-editor">
    <div class="store-section-head">
      <div><span class="eyebrow">مرکز مدیریت محصولات</span><h1>{{ $isEdit ? 'ویرایش محصول' : 'ایجاد محصول جدید' }}</h1><p>اطلاعات فروش، محتوا و نوع دسترسی را از یک نقطه مدیریت کنید.</p></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><a class="store-outline-btn" href="{{ route('admin.store.index') }}">فهرست محصولات</a>@if($isEdit)<a class="store-outline-btn" href="{{ route('store.product',$product->slug) }}" target="_blank" rel="noopener">پیش‌نمایش فروشگاه</a>@endif</div>
    </div>
    @if(session('status'))<div class="store-alert success" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="store-alert error" role="alert">{{ $errors->first() }}</div>@endif
    @if($isEdit)@include('admin.store.partials.workflow-checklist')@endif

    <form method="post" action="{{ $isEdit ? route('admin.store.update',$product) : route('admin.store.store') }}" class="store-panel product-editor-form" id="product-details">
      @csrf @if($isEdit) @method('PUT') @endif
      <div class="product-editor-heading"><div><span>اطلاعات پایه</span><h2>مشخصات محصول</h2></div><span class="product-editor-step">گام ۱ از ۳</span></div>
      <div class="product-editor-grid">
        <label class="product-field product-field-wide">نام محصول / دوره *<input name="title" value="{{ old('title',$product->title) }}" required maxlength="220" placeholder="عنوان دقیق و قابل فهم برای مشتری"></label>
        <label class="product-field">نوع محصول *@if($isEdit)<input type="hidden" name="type" value="{{ $product->type }}">@endif<select name="type" required @disabled($isEdit)><option value="digital_file" @selected(old('type',$product->type)==='digital_file')>محصول فایل دانلودی</option><option value="course" @selected(old('type',$product->type)==='course')>دوره آموزشی</option></select><small>{{ $isEdit ? 'نوع محصول پس از ایجاد ثابت است تا سوابق و دسترسی‌ها مخلوط نشوند.' : 'نوع محصول تعیین می‌کند چه ابزارهایی در مرحله بعد نمایش داده شود.' }}</small></label>
        <label class="product-field">شناسه محصول (SKU) *<input name="sku" value="{{ old('sku',$product->sku) }}" required maxlength="80" placeholder="مثلاً COURSE-001"></label>
        <label class="product-field">نشانی اختصاصی (Slug)<input name="slug" value="{{ old('slug',$product->slug) }}" maxlength="240" placeholder="خودکار از عنوان ساخته می‌شود"></label>
        <label class="product-field">دسته‌بندی *<select name="category_id" required><option value="">انتخاب دسته</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id',$product->category_id)==$category->id)>{{ $category->name }}</option>@endforeach</select></label>
        <label class="product-field">قیمت فروش (ریال) *<input type="number" name="price_rials" value="{{ old('price_rials',$product->price_rials ?? 0) }}" min="0" max="999999999999" required></label>
        <label class="product-field">قیمت قبل از تخفیف (ریال)<input type="number" name="compare_at_price_rials" value="{{ old('compare_at_price_rials',$product->compare_at_price_rials) }}" min="0"></label>
        <label class="product-field">وضعیت انتشار *<select name="status" required>@foreach(['draft'=>'پیش‌نویس','published'=>'منتشرشده','archived'=>'بایگانی'] as $value=>$label)<option value="{{ $value }}" @selected(old('status',$product->status)===$value)>{{ $label }}</option>@endforeach</select><small>انتشار فقط پس از تکمیل فایل اصلی یا حداقل یک جلسه منتشرشده مجاز است.</small></label>
        <label class="product-field">نسخه / ویرایش<input name="version" value="{{ old('version',$product->version) }}" maxlength="60" placeholder="مثلاً 1.0"></label>
        <label class="product-field">نوع مجوز استفاده<input name="license_type" value="{{ old('license_type',$product->license_type) }}" maxlength="60" placeholder="مثلاً شخصی / استاندارد"></label>
        <label class="product-field">ترتیب نمایش<input type="number" name="sort_order" value="{{ old('sort_order',$product->sort_order ?? 0) }}" min="0"></label>
        <label class="product-field">تعداد صفحات نمونه<input type="number" name="preview_pages" value="{{ old('preview_pages',$product->preview_pages ?? 3) }}" min="0" max="50"></label>
        <label class="product-field product-field-check"><input type="checkbox" name="featured" value="1" @checked(old('featured',$product->featured))> نمایش به‌عنوان محصول ویژه</label>
      </div>
      <label class="product-field product-field-wide">معرفی کوتاه<textarea name="short_description" rows="2" maxlength="2000" placeholder="در یک یا دو جمله بگویید این محصول چه ارزشی برای مشتری ایجاد می‌کند.">{{ old('short_description',$product->short_description) }}</textarea></label>
      <label class="product-field product-field-wide">توضیحات کامل<textarea name="description" rows="6" maxlength="20000" placeholder="شرح کامل محتوا، کاربردها، پیش‌نیازها و آنچه خریدار دریافت می‌کند.">{{ old('description',$product->description) }}</textarea></label>
      <label class="product-field product-field-wide">عنوان سئو<input name="seo_title" value="{{ old('seo_title',$product->seo_title) }}" maxlength="220" placeholder="عنوان مناسب برای موتورهای جستجو"></label>
      <label class="product-field product-field-wide">توضیحات سئو<textarea name="seo_description" rows="2" maxlength="320" placeholder="خلاصه جذاب و دقیق برای نتیجه جستجو">{{ old('seo_description',$product->seo_description) }}</textarea></label>
      <div class="product-editor-actions"><button type="submit" class="store-primary-btn">{{ $isEdit ? 'ذخیره اطلاعات محصول' : 'ایجاد پیش‌نویس محصول' }}</button><span>محصول جدید ابتدا به‌صورت پیش‌نویس ساخته می‌شود.</span></div>
    </form>

    @if($isEdit)
      <div class="store-panel product-files-panel" id="product-cover">
        <h2>تصویر کاور محصول</h2>
        @if($product->cover_path)<img src="{{ asset($product->cover_path) }}" alt="کاور {{ $product->title }}" style="display:block;width:min(320px,100%);aspect-ratio:16/9;object-fit:cover;border-radius:12px;margin:12px 0">@else<p>هنوز کاوری ثبت نشده است.</p>@endif
        <form method="post" action="{{ route('admin.store.cover.upload',$product) }}" enctype="multipart/form-data" class="product-file-upload">@csrf<input type="file" name="cover" accept=".jpg,.jpeg,.png,.webp" required><button type="submit" class="store-outline-btn">بارگذاری کاور و اسکن امنیتی</button></form>
        <small class="product-help">فرمت‌های JPG، PNG و WebP · حداکثر ۸ مگابایت</small>
      </div>
    @endif

    @if($isEdit && $product->type==='course')
      <div class="store-panel product-next-step"><div><strong>مرحله بعد: طراحی برنامه آموزشی</strong><p>فصل‌ها، جلسات، ویدئوها، فایل‌های ضمیمه و تنظیمات گواهی را مدیریت کنید.</p></div><a class="store-primary-btn" href="{{ route('admin.store.course.edit',$product) }}">ورود به مدیریت دوره <i class="fa-solid fa-arrow-left"></i></a></div>
    @elseif($isEdit)
      <div class="store-panel product-files-panel" id="product-files">
        <div class="product-editor-heading"><div><span>محتوای قابل دریافت</span><h2>فایل‌های محصول</h2></div><span class="product-editor-step">اسکن امنیتی الزامی</span></div>
        <p>فایل‌ها در فضای خصوصی نگهداری می‌شوند. فقط فایل اصلیِ اسکن‌شده و فعال پس از خرید قابل دریافت خواهد بود.</p>
        <div class="product-file-list">@forelse($product->files->where('purpose','product_download') as $file)
          <div class="product-file-row"><span><i class="fa-solid fa-file-arrow-down"></i><b>{{ $file->original_name }}</b><small>{{ number_format($file->size_bytes/1024,1) }} KB · {{ $file->scan_status==='clean'?'اسکن سالم':'نیازمند بررسی مجدد' }} @if($file->is_primary) · فایل اصلی @endif</small></span><form method="post" action="{{ route('admin.store.files.delete',[$product,$file]) }}" onsubmit="return confirm('فایل حذف شود؟')">@csrf @method('DELETE')<button type="submit" class="product-danger">حذف</button></form></div>
        @empty <div class="course-empty">هنوز فایلی بارگذاری نشده است.</div>@endforelse</div>
        <form method="post" action="{{ route('admin.store.files.upload',$product) }}" enctype="multipart/form-data" class="product-file-upload">@csrf<input type="file" name="file" required accept=".pdf,.zip,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.mp3,.mp4,.webm,.m4a"><label><input type="checkbox" name="is_primary" value="1" checked> فایل اصلی محصول</label><button type="submit" class="store-primary-btn">بارگذاری و اسکن فایل</button></form>
        <small class="product-help">حداکثر ۵۰ مگابایت؛ اگر اسکنر ضدبدافزار فعال نباشد، فایل ثبت و قابل انتشار نمی‌شود.</small>
      </div>
      <div class="store-panel product-files-panel" id="product-previews">
        <h2>پیش‌نمایش عمومی محصول</h2><p>تصاویر یا صفحات نمونه برای آشنایی خریدار؛ فایل‌ها پیش از ذخیره اسکن می‌شوند.</p>
        <div class="store-preview-grid">@forelse($product->previews as $preview)<div>@if($preview->kind==='pdf')<div class="store-preview-pdf">PDF · {{ $preview->page_number }}</div>@else<img src="{{ route('store.preview',$preview) }}" alt="" loading="lazy">@endif<form method="post" action="{{ route('admin.store.previews.delete',[$product,$preview]) }}">@csrf @method('DELETE')<button class="product-danger" type="submit">حذف</button></form></div>@empty<div class="course-empty">هنوز پیش‌نمایشی اضافه نشده است.</div>@endforelse</div>
        <form method="post" action="{{ route('admin.store.previews.upload',$product) }}" enctype="multipart/form-data" class="product-file-upload">@csrf<input type="file" name="preview" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf" required><input type="number" name="page_number" min="1" max="500" placeholder="شماره صفحه"><button type="submit" class="store-outline-btn">افزودن پیش‌نمایش</button></form>
      </div>
    @endif

    <div class="store-panel product-category-panel"><h2>مدیریت سریع دسته‌بندی</h2><form method="post" action="{{ route('admin.store.categories.store') }}">@csrf<input name="name" required maxlength="160" placeholder="نام دسته جدید"><input name="slug" maxlength="180" placeholder="نشانی (اختیاری)"><button type="submit" class="store-outline-btn">افزودن دسته</button></form></div>
  </div>
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20261107product1">
<style>
.product-editor{max-width:1080px}.product-editor .store-section-head p{color:var(--st-muted);font-size:.8rem;margin:5px 0}.product-editor-form{padding:24px;display:grid;gap:18px}.product-editor-heading{display:flex;justify-content:space-between;align-items:center;gap:10px;border-bottom:1px solid var(--st-line);padding-bottom:14px}.product-editor-heading span:first-child{color:var(--st-blue);font-size:.7rem;font-weight:800}.product-editor-heading h2{font-size:1.02rem;margin:4px 0 0}.product-editor-step{font-size:.7rem;color:var(--st-muted);background:#f2f6fb;border-radius:99px;padding:7px 11px}.product-editor-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.product-field{display:grid;gap:7px;font-size:.78rem;font-weight:700;color:#40536d}.product-field input,.product-field select,.product-field textarea,.product-category-panel input,.product-file-upload input[type=text],.product-file-upload input[type=number]{width:100%;min-width:0;box-sizing:border-box;padding:11px 12px;border:1px solid var(--st-line);border-radius:10px;background:#fff;color:var(--st-ink);font:inherit;font-size:.8rem}.product-field small,.product-help{font-size:.67rem;color:var(--st-muted);font-weight:400}.product-field-wide{grid-column:1/-1}.product-field-check{display:flex;align-items:center;gap:9px}.product-editor-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.product-editor-actions span,.product-files-panel>p{font-size:.72rem;color:var(--st-muted)}.product-next-step{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:16px}.product-next-step strong{font-size:.88rem}.product-next-step p{margin:5px 0 0;color:var(--st-muted);font-size:.75rem}.product-files-panel{margin-top:16px}.product-files-panel>p{margin:10px 0 14px}.product-file-list{display:grid;gap:8px;margin-bottom:16px}.product-file-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px;border:1px solid var(--st-line);border-radius:10px}.product-file-row>span{display:grid;grid-template-columns:auto 1fr;gap:3px 9px;align-items:center}.product-file-row>span i{grid-row:span 2;color:var(--st-blue)}.product-file-row b{font-size:.75rem}.product-file-row small{font-size:.66rem;color:var(--st-muted)}.product-danger{border:0;background:#fff1f1;color:#b72e2e;padding:7px 11px;border-radius:8px;cursor:pointer;font:inherit;font-size:.7rem}.product-file-upload,.product-category-panel form{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.product-file-upload input[type=file]{max-width:100%;font-size:.72rem}.product-file-upload label{display:flex;align-items:center;gap:6px;font-size:.72rem}.product-help{display:block;margin-top:9px}.product-category-panel{margin-top:16px}.product-category-panel h2{font-size:.9rem}.product-category-panel input{width:auto;min-width:180px}.course-empty{padding:18px;border:1px dashed var(--st-line);border-radius:10px;text-align:center;color:var(--st-muted);font-size:.75rem}@media(max-width:700px){.product-editor-form{padding:15px}.product-editor-grid{grid-template-columns:1fr}.product-field-wide{grid-column:auto}.product-next-step{align-items:flex-start;flex-direction:column}.product-file-row{align-items:flex-start}}
</style>
@endpush
