<article class="store-product-card">
  <a class="store-product-cover" href="{{ route('store.product',$product->slug) }}">
    @if((int)$product->price_rials===0)<span class="store-badge free">رایگان</span>
    @elseif($product->featured)<span class="store-badge">ویژه</span>@endif
    @if($product->cover_path)
      <img src="{{ asset($product->cover_path) }}" alt="{{ $product->title }}" loading="lazy">
    @else
      <i class="fa-solid fa-file-lines"></i>
    @endif
  </a>
  <div class="store-product-copy">
    <small>{{ $product->category?->name ?? 'فایل دیجیتال' }}</small>
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
