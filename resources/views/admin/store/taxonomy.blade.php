@extends('layouts.admin')
@section('admin-content')
<section class="store-page" dir="rtl">
  <div class="store-container">
    <div class="store-section-head">
      <div>
        <span class="eyebrow">مدیریت فروشگاه</span>
        <h1>مدیریت دسته‌بندی‌ها و برچسب‌ها</h1>
        <p>ساختار دسته‌بندی محصولات را مرتب کنید و برچسب‌ها را بدون قطع ارتباط محصولات مدیریت کنید.</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="store-outline-btn" href="{{ route('admin.store.index') }}">بازگشت به محصولات</a>
      </div>
    </div>
    @if(session('status'))<div class="store-alert success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="store-alert error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="taxonomy-grid">
      <section class="taxonomy-panel">
        <div class="taxonomy-panel-head"><div><h2>دسته‌بندی جدید</h2><p>دسته‌های غیرفعال از محصولات قدیمی جدا نمی‌شوند.</p></div></div>
        <form method="post" action="{{ route('admin.store.categories.store') }}" class="taxonomy-create-form">
          @csrf
          <label>نام دسته<input name="name" required maxlength="160" placeholder="مثلاً آموزش و مهارت"></label>
          <label>نشانی انگلیسی<input name="slug" maxlength="180" placeholder="learning-skills"></label>
          <label>دسته والد<select name="parent_id"><option value="">بدون والد</option>@foreach($categories as $parent)<option value="{{ $parent->id }}">{{ $parent->name }}</option>@endforeach</select></label>
          <label>ترتیب نمایش<input type="number" name="sort_order" min="0" max="999999" value="0"></label>
          <label class="taxonomy-wide">توضیحات<textarea name="description" rows="2" maxlength="5000"></textarea></label>
          <button type="submit" class="store-primary-btn">ایجاد دسته‌بندی</button>
        </form>
      </section>

      <section class="taxonomy-panel">
        <div class="taxonomy-panel-head"><div><h2>برچسب جدید</h2><p>برچسب‌ها برای جستجو و دسته‌بندی دقیق‌تر محصولات استفاده می‌شوند.</p></div></div>
        <form method="post" action="{{ route('admin.store.tags.store') }}" class="taxonomy-create-form">
          @csrf
          <label>نام برچسب<input name="name" required maxlength="100" placeholder="مثلاً راهنمای نصب"></label>
          <label>نشانی انگلیسی<input name="slug" maxlength="120" placeholder="installation-guide"></label>
          <button type="submit" class="store-primary-btn">ایجاد برچسب</button>
        </form>
      </section>
    </div>

    <section class="taxonomy-panel">
      <div class="taxonomy-panel-head"><div><h2>دسته‌بندی‌ها</h2><p>{{ number_format($categories->count()) }} دسته · تغییر وضعیت دسته، محصولات متصل را حفظ می‌کند.</p></div></div>
      <div class="taxonomy-list">
        @forelse($categories as $category)
          <article class="taxonomy-row">
            <div class="taxonomy-row-meta">
              <strong>{{ $category->name }}</strong>
              <small>{{ $category->products_count }} محصول · {{ $category->children_count }} زیردسته · {{ $category->parent?->name ?? 'دسته اصلی' }} · {{ $category->is_active ? 'فعال' : 'غیرفعال' }}</small>
            </div>
            <form method="post" action="{{ route('admin.store.taxonomy.categories.update', $category) }}" class="taxonomy-edit-form">
              @csrf @method('PUT')
              <label>نام<input name="name" required maxlength="160" value="{{ $category->name }}"></label>
              <label>Slug<input name="slug" required maxlength="180" value="{{ $category->slug }}"></label>
              <label>والد<select name="parent_id"><option value="">بدون والد</option>@foreach($categories as $parent)@if($parent->id !== $category->id)<option value="{{ $parent->id }}" @selected($category->parent_id === $parent->id)>{{ $parent->name }}</option>@endif @endforeach</select></label>
              <label>ترتیب<input type="number" name="sort_order" min="0" max="999999" value="{{ $category->sort_order }}"></label>
              <label class="taxonomy-wide">توضیحات<textarea name="description" rows="2" maxlength="5000">{{ $category->description }}</textarea></label>
              <input type="hidden" name="is_active" value="0">
              <label class="taxonomy-check"><input type="checkbox" name="is_active" value="1" @checked($category->is_active)> فعال باشد</label>
              <button type="submit" class="store-outline-btn">ذخیره دسته</button>
            </form>
          </article>
        @empty
          <p class="taxonomy-empty">هنوز دسته‌ای ثبت نشده است.</p>
        @endforelse
      </div>
    </section>

    <section class="taxonomy-panel">
      <div class="taxonomy-panel-head"><div><h2>برچسب‌ها</h2><p>{{ number_format($tags->count()) }} برچسب ثبت‌شده</p></div></div>
      <div class="taxonomy-list">
        @forelse($tags as $tag)
          <article class="taxonomy-tag-row">
            <div class="taxonomy-row-meta"><strong>{{ $tag->name }}</strong><small>{{ $tag->products_count }} محصول متصل · {{ $tag->slug }}</small></div>
            <form method="post" action="{{ route('admin.store.taxonomy.tags.update', $tag) }}" class="taxonomy-tag-form">
              @csrf @method('PUT')
              <label>نام<input name="name" required maxlength="100" value="{{ $tag->name }}"></label>
              <label>Slug<input name="slug" required maxlength="120" value="{{ $tag->slug }}"></label>
              <button type="submit" class="store-outline-btn">ذخیره</button>
            </form>
            <form method="post" action="{{ route('admin.store.taxonomy.tags.destroy', $tag) }}" onsubmit="return confirm('این برچسب حذف شود؟')">
              @csrf @method('DELETE')
              <button type="submit" class="store-outline-btn taxonomy-delete" @disabled($tag->products_count > 0)>حذف برچسب</button>
            </form>
          </article>
        @empty
          <p class="taxonomy-empty">هنوز برچسبی ثبت نشده است.</p>
        @endforelse
      </div>
    </section>
  </div>
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20261109taxonomy1">
<style>
.taxonomy-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:14px;margin-bottom:14px}.taxonomy-panel{display:grid;gap:14px;padding:16px;margin:0 0 14px;border:1px solid var(--st-line);border-radius:14px;background:#fff;min-width:0}.taxonomy-panel-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.taxonomy-panel-head h2{margin:0;font-size:.9rem;color:var(--st-ink)}.taxonomy-panel-head p{margin:5px 0 0;font-size:.7rem;color:var(--st-muted)}.taxonomy-create-form,.taxonomy-edit-form,.taxonomy-tag-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;align-items:end}.taxonomy-create-form label,.taxonomy-edit-form label,.taxonomy-tag-form label{display:grid;gap:5px;font-size:.7rem;color:var(--st-muted);min-width:0}.taxonomy-create-form input,.taxonomy-create-form select,.taxonomy-create-form textarea,.taxonomy-edit-form input,.taxonomy-edit-form select,.taxonomy-edit-form textarea,.taxonomy-tag-form input{width:100%;min-width:0;border:1px solid var(--st-line);border-radius:9px;padding:9px 10px;font:inherit;font-size:.75rem;color:var(--st-ink);background:#fff}.taxonomy-wide{grid-column:1/-1}.taxonomy-check{display:flex!important;align-items:center;gap:8px}.taxonomy-check input{width:17px!important;height:17px}.taxonomy-list{display:grid;gap:10px}.taxonomy-row,.taxonomy-tag-row{display:grid;gap:10px;padding:12px;border:1px solid var(--st-line);border-radius:11px}.taxonomy-row-meta{display:grid;gap:4px}.taxonomy-row-meta strong{font-size:.78rem;color:var(--st-ink)}.taxonomy-row-meta small{font-size:.66rem;color:var(--st-muted)}.taxonomy-delete{color:#b42318!important}.taxonomy-delete:disabled{opacity:.45;cursor:not-allowed}.taxonomy-empty{margin:0;font-size:.72rem;color:var(--st-muted)}@media(max-width:850px){.taxonomy-grid{grid-template-columns:1fr}.taxonomy-edit-form{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:520px){.taxonomy-create-form,.taxonomy-edit-form,.taxonomy-tag-form{grid-template-columns:1fr}.taxonomy-wide{grid-column:auto}.taxonomy-tag-row{grid-template-columns:1fr}}
</style>
@endpush
