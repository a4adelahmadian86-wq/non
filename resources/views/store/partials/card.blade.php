<article class="store-product-card">
  <a class="store-product-cover" href="{{ route('store.product',$product->slug) }}">
    @if((int)$product->price_rials===0)<span class="store-badge free">رایگان</span>
    @elseif($product->featured)<span class="store-badge">ویژه</span>@endif
    @php($cardImage = $product->cover_path ?: $product->images->where('is_active', true)->first()?->path)
    @if($cardImage)
      <img src="{{ asset($cardImage) }}" alt="{{ $product->title }}" loading="lazy">
    @else
      <span class="store-cover-placeholder"><i class="fa-solid fa-{{ $product->type === 'course' ? 'graduation-cap' : 'file-lines' }}"></i><b>FARAST</b></span>
    @endif
  </a>
  <div class="store-product-copy">
    <small>{{ $product->type === 'course' ? 'دوره آموزشی' : ($product->category?->name ?? 'فایل دیجیتال') }}</small>
    <h2><a href="{{ route('store.product',$product->slug) }}">{{ $product->title }}</a></h2>
    <p>{{ \Illuminate\Support\Str::limit($product->short_description ?: $product->description, 100) }}</p>
    <div class="store-price-row">
      <div class="store-price">
        @if((int)$product->price_rials===0) رایگان
        @else {{ number_format((int)$product->price_rials/10) }} تومان
          @if($product->compare_at_price_rials && $product->compare_at_price_rials > $product->price_rials)
            <del>{{ number_format((int)$product->compare_at_price_rials/10) }}</del>
          @endif
        @endif
      </div>
    </div>
    <div class="store-card-actions">
      <button type="button" class="buy" data-add-cart="{{ $product->id }}">افزودن</button>
      <a class="detail" href="{{ route('store.product',$product->slug) }}">جزئیات</a>
    </div>
  </div>
</article>
