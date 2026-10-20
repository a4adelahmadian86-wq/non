@php
    $dashAnnouncements = collect();
    try {
        if (auth()->check() && \Illuminate\Support\Facades\Schema::hasTable('announcements')) {
            $dashAnnouncements = \App\Models\Announcement::query()
                ->forUser(auth()->user())
                ->where('is_pinned', true)
                ->latest()
                ->limit(2)
                ->get();
        }
    } catch (\Throwable $e) {
        $dashAnnouncements = collect();
    }
@endphp
@if($dashAnnouncements->isNotEmpty())
<link rel="stylesheet" href="{{ asset('css/announcements.css') }}?v=20261020pro2">
<div class="an-dash-stack" dir="rtl" role="region" aria-label="اعلانات پین‌شده">
    @foreach($dashAnnouncements as $a)
        <div class="an-dash-item type-{{ $a->type }} an-type-{{ $a->type }}">
            <div class="an-item-icon" aria-hidden="true">
                <i class="fa-solid {{ $a->icon() }}"></i>
            </div>
            <div class="an-item-body" style="min-width:0">
                <div class="an-item-meta" style="margin-bottom:2px">
                    <span class="an-tag an-tag-pin"><i class="fa-solid fa-thumbtack"></i> پین</span>
                    <span class="an-tag an-tag-{{ $a->type }}">{{ $a->typeLabel() }}</span>
                </div>
                <h3 class="an-item-title">{{ $a->title }}</h3>
                <p class="an-item-text">{{ \Illuminate\Support\Str::limit($a->body, 120) }}</p>
            </div>
            <div class="an-item-side">
                <a href="{{ route('announcements') }}" class="an-btn an-btn-ghost an-btn-sm">مشاهده</a>
            </div>
        </div>
    @endforeach
</div>
@endif
