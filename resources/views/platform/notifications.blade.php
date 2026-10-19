@extends('layouts.app')

@section('content')
<div class="container" dir="rtl" style="max-width:820px;margin:24px auto;padding:0 16px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px">
        <div>
            <h1 style="margin:0;font-size:1.35rem">اعلان‌ها</h1>
            <p style="margin:6px 0 0;color:#64748b;font-size:.9rem">اعلان‌های سیستم، پشتیبانی و رویدادهای حساب شما</p>
        </div>
        <a href="{{ route('support') }}" style="font-size:.88rem;color:#1769ff;text-decoration:none;font-weight:600">گفتگوهای پشتیبانی</a>
    </div>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden">
        @forelse($items as $n)
            @php
                $data = is_string($n->data ?? null) ? json_decode($n->data, true) : (array) ($n->data ?? []);
                $url = $data['url'] ?? null;
                $isTicket = str_starts_with((string)($n->type ?? ''), 'ticket_');
            @endphp
            <article style="padding:14px 16px;border-bottom:1px solid #f1f5f9;display:flex;gap:12px;align-items:flex-start;opacity:{{ $n->read_at ? '0.65' : '1' }}">
                <div style="width:40px;height:40px;border-radius:12px;background:{{ $isTicket ? '#eef4ff' : '#f8fafc' }};display:flex;align-items:center;justify-content:center;color:{{ $isTicket ? '#1769ff' : '#64748b' }};flex:none">
                    <i class="fa-solid {{ $isTicket ? 'fa-headset' : 'fa-bell' }}"></i>
                </div>
                <div style="min-width:0;flex:1">
                    <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
                        <b style="font-size:.95rem">{{ $n->title }}</b>
                        <time style="font-size:.75rem;color:#94a3b8">{{ \Illuminate\Support\Carbon::parse($n->created_at)->timezone(config('app.timezone'))->diffForHumans() }}</time>
                    </div>
                    @if($n->body)
                        <p style="margin:6px 0 0;color:#475569;font-size:.88rem;line-height:1.6">{{ $n->body }}</p>
                    @endif
                    <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                        @if($url)
                            <a href="{{ $url }}" style="font-size:.82rem;color:#1769ff;font-weight:700;text-decoration:none">مشاهده</a>
                        @endif
                        @unless($n->read_at)
                            <form method="post" action="{{ route('platform.notifications.read', $n->id) }}">
                                @csrf
                                <button type="submit" style="border:0;background:#f1f5f9;color:#334155;border-radius:8px;padding:6px 10px;font-size:.78rem;cursor:pointer">خوانده شد</button>
                            </form>
                        @endunless
                    </div>
                </div>
            </article>
        @empty
            <div style="padding:40px 16px;text-align:center;color:#94a3b8">اعلانی وجود ندارد.</div>
        @endforelse
    </div>

    <div style="margin-top:16px">{{ $items->links() }}</div>
</div>
@endsection
