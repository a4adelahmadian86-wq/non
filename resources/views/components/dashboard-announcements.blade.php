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
<div class="dash-ann-stack" dir="rtl" style="display:grid;gap:8px;margin:0 0 14px">
    @foreach($dashAnnouncements as $a)
        <div style="display:flex;gap:12px;align-items:flex-start;padding:12px 14px;border-radius:14px;border:1px solid {{ $a->type === 'danger' ? '#fecaca' : ($a->type === 'warning' ? '#fde68a' : '#bfdbfe') }};background:{{ $a->type === 'danger' ? '#fef2f2' : ($a->type === 'warning' ? '#fffbeb' : '#eff6ff') }}">
            <i class="fa-solid {{ $a->icon() }}" style="margin-top:3px;color:{{ $a->type === 'danger' ? '#b91c1c' : ($a->type === 'warning' ? '#b45309' : '#1d4ed8') }}"></i>
            <div style="flex:1;min-width:0">
                <b style="display:block;font-size:.9rem;color:#0f172a">{{ $a->title }}</b>
                <span style="font-size:.82rem;color:#475569;line-height:1.6">{{ \Illuminate\Support\Str::limit($a->body, 140) }}</span>
            </div>
            <a href="{{ route('announcements') }}" style="font-size:.78rem;font-weight:700;color:#1769ff;white-space:nowrap">همه</a>
        </div>
    @endforeach
</div>
@endif
