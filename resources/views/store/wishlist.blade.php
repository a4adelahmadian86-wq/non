@extends('layouts.app')
@section('content')
<section class="store-page" dir="rtl">
  <div class="store-container">
    <div class="store-section-head wishlist-heading">
      <div><span class="eyebrow">فضای شخصی شما</span><h1>{{ $type==='later' ? 'بعداً می‌خرم' : 'علاقه‌مندی‌ها' }}</h1><p>محصول‌هایی که برای خرید یا بررسی بیشتر نگه داشته‌اید، اینجا در دسترس‌اند.</p></div>
      <a class="store-primary-btn" href="{{ route('store') }}"><i class="fa-solid fa-compass" aria-hidden="true"></i> کشف محصولات</a>
    </div>
    <nav class="wishlist-tabs" aria-label="فهرست‌های ذخیره‌شده">
      <a class="{{ $type==='wishlist' ? 'is-active' : '' }}" href="{{ route('wishlist',['type'=>'wishlist']) }}" @if($type==='wishlist') aria-current="page" @endif><i class="fa-regular fa-heart" aria-hidden="true"></i> علاقه‌مندی‌ها</a>
      <a class="{{ $type==='later' ? 'is-active' : '' }}" href="{{ route('wishlist',['type'=>'later']) }}" @if($type==='later') aria-current="page" @endif><i class="fa-regular fa-clock" aria-hidden="true"></i> بعداً می‌خرم</a>
      <span class="wishlist-total">{{ number_format($items->total()) }} مورد</span>
    </nav>
    @if(session('status'))<div class="store-alert success" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>{{ session('status') }}</div>@endif
    @if($items->count())
      <div class="store-product-grid wishlist-product-grid">
        @foreach($items as $row)
          @if($row->product)
            <div class="wishlist-item">
              @include('store.partials.card', ['product' => $row->product])
              <form method="post" action="{{ route('wishlist.destroy', $row->product) }}" class="wishlist-remove-form" onsubmit="return confirm('این محصول از این فهرست حذف شود؟')">
                @csrf
                @method('DELETE')
                <input type="hidden" name="list_type" value="{{ $type }}">
                <button type="submit"><i class="fa-regular fa-trash-can" aria-hidden="true"></i> حذف از این فهرست</button>
              </form>
            </div>
          @endif
        @endforeach
      </div>
      <div class="wishlist-pagination">{{ $items->links() }}</div>
    @else
      <div class="store-empty-state wishlist-empty">
        <span class="wishlist-empty-icon"><i class="fa-{{ $type==='later' ? 'regular fa-clock' : 'regular fa-heart' }}" aria-hidden="true"></i></span>
        <h2>{{ $type==='later' ? 'هنوز محصولی برای بعداً ذخیره نکرده‌اید' : 'فهرست علاقه‌مندی‌ها خالی است' }}</h2>
        <p>در صفحه هر محصول می‌توانید آن را به این فهرست اضافه کنید تا بعداً راحت‌تر پیدایش کنید.</p>
        <a class="store-primary-btn" href="{{ route('store') }}"><i class="fa-solid fa-store" aria-hidden="true"></i> مشاهده فروشگاه</a>
      </div>
    @endif
  </div>
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20260929b">
@endpush
