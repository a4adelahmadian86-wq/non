{{-- اعلانات فقط داخل داشبورد؛ این کامپوننت عمداً در فرانت عمومی چیزی رندر نمی‌کند. --}}
@if(auth()->check() && (request()->routeIs('dashboard') || request()->routeIs('announcements') || request()->routeIs('admin.*') || request()->routeIs('support*')))
@php
    $bannerItems = collect();
    try {
        if (\Illuminate\Support\Facades\Schema::hasTable('announcements')) {
            $bannerItems = \App\Models\Announcement::query()
                ->forUser(auth()->user())
                ->where('is_pinned', true)
                ->latest()
                ->limit(2)
                ->get();
        }
    } catch (\Throwable) {
        $bannerItems = collect();
    }
@endphp
@if($bannerItems->isNotEmpty())
<div class="farast-announce-banner" dir="rtl">
    @foreach($bannerItems as $a)
        <div class="farast-announce-item type-{{ $a->type }}">
            <i class="fa-solid {{ $a->icon() }}"></i>
            <div>
                <strong>{{ $a->title }}</strong>
                <span>{{ \Illuminate\Support\Str::limit($a->body, 120) }}</span>
            </div>
            <a href="{{ $a->link_url ?: route('announcements') }}">جزئیات</a>
        </div>
    @endforeach
</div>
@endif
@endif
