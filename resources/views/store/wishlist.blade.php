@extends('layouts.app')
@section('content')
<section class="store-page" dir="rtl">
  <div class="store-container">
    <div class="store-section-head">
      <div><span class="eyebrow">لیست‌های من</span><h1>{{ $type==='later' ? 'بعداً می‌خرم' : 'علاقه‌مندی‌ها' }}</h1></div>
      <div style="display:flex;gap:8px">
        <a class="store-outline-btn" href="{{ route('wishlist',['type'=>'wishlist']) }}">علاقه‌مندی</a>
        <a class="store-outline-btn" href="{{ route('wishlist',['type'=>'later']) }}">بعداً می‌خرم</a>
        <a class="store-primary-btn" href="{{ route('store') }}">فروشگاه</a>
      </div>
    </div>
    @if(session('status'))<div class="store-alert success">{{ session('status') }}</div>@endif
    <div class="store-product-grid">
      @forelse($items as $row)
        @if($row->product)
          @include('store.partials.card', ['product' => $row->product])
        @endif
      @empty
        <div class="store-empty-state"><i class="fa-regular fa-heart"></i><h2>لیست خالی است</h2><a class="store-primary-btn" href="{{ route('store') }}">مشاهده فروشگاه</a></div>
      @endforelse
    </div>
    {{ $items->links() }}
  </div>
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20260929b">
@endpush
