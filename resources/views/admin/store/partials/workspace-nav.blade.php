<div class="store-workspace-shell" dir="rtl">
  <header class="store-workspace-bar">
    <a class="store-workspace-brand" href="{{ route('admin.index') }}">
      <span class="store-workspace-mark"><i class="fa-solid fa-cubes-stacked"></i></span>
      <span><b>فراست</b><small>فضای کاری فروشگاه</small></span>
    </a>
    <div class="store-workspace-context">
      <span>مرکز فروش</span><i class="fa-solid fa-chevron-left"></i>
      <strong>{{ request()->routeIs('admin.store.create') ? 'محصول جدید' : (request()->routeIs('admin.store.course.*') ? 'مدیریت دوره' : (request()->routeIs('admin.store.edit') ? 'ویرایش محصول' : 'کاتالوگ محصولات')) }}</strong>
      @if(isset($product) && $product->exists)<small>{{ $product->title }}</small>@endif
    </div>
    <div class="store-workspace-actions">
      <a href="{{ url('/store') }}" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i><span>نمای فروشگاه</span></a>
      <a href="{{ route('admin.index') }}"><i class="fa-solid fa-grid-2"></i><span>کنسول اصلی</span></a>
      <a class="is-primary" href="{{ route('admin.store.create') }}"><i class="fa-solid fa-plus"></i><span>محصول جدید</span></a>
    </div>
  </header>
  <nav class="store-workspace-nav" aria-label="ناوبری فضای کاری فروشگاه">
    <a class="{{ request()->routeIs('admin.store.index') ? 'is-active' : '' }}" href="{{ route('admin.store.index') }}"><i class="fa-solid fa-layer-group"></i> کاتالوگ</a>
    <a class="{{ request()->routeIs('admin.store.create') ? 'is-active' : '' }}" href="{{ route('admin.store.create') }}"><i class="fa-solid fa-circle-plus"></i> ایجاد محصول</a>
    @if(isset($product) && $product->exists)
      <a class="{{ request()->routeIs('admin.store.edit') ? 'is-active' : '' }}" href="{{ route('admin.store.edit',$product) }}"><i class="fa-solid fa-sliders"></i> اطلاعات و فروش</a>
      @if($product->type === 'course')
        <a class="{{ request()->routeIs('admin.store.course.*') ? 'is-active' : '' }}" href="{{ route('admin.store.course.edit',$product) }}"><i class="fa-solid fa-graduation-cap"></i> برنامه آموزشی</a>
      @else
        <a href="{{ route('admin.store.edit',$product) }}#product-files"><i class="fa-solid fa-folder-open"></i> فایل‌ها و پیش‌نمایش</a>
      @endif
      <a href="{{ route('store.product',$product->slug) }}" target="_blank" rel="noopener"><i class="fa-solid fa-eye"></i> صفحه عمومی</a>
    @endif
  </nav>
</div>