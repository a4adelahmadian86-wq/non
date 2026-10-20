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
<link rel="stylesheet" href="{{ asset('css/announcements.css') }}?v=20261020a1">
<div class="an-feed" dir="rtl" style="margin:0 0 14px">
    @foreach($dashAnnouncements as $a)
        <article class="an-feed-item is-pinned type-{{ $a->type }}" style="padding:12px 14px">
            <div class="an-feed-icon" style="width:36px;height:36px"><i class="fa-solid {{ $a->icon() }}"></i></div>
            <div style="flex:1;min-width:0">
                <div class="an-feed-meta">
                    <span class="an-badge an-badge-pin"><i class="fa-solid fa-thumbtack"></i> پین</span>
                    <span class="an-badge an-badge-{{ $a->type }}">{{ $a->typeLabel() }}</span>
                </div>
                <h2 style="font-size:.95rem;margin:0 0 4px">{{ $a->title }}</h2>
                <p class="an-body" style="font-size:.82rem">{{ \Illuminate\Support\Str::limit($a->body, 140) }}</p>
            </div>
            <a href="{{ route('announcements') }}" class="an-feed-link" style="margin:0;white-space:nowrap">همه</a>
        </article>
    @endforeach
</div>
@endif
