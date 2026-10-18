@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261018">
<div class="container" dir="rtl" style="padding:1.5rem;max-width:1100px;margin:0 auto">
    <div class="page-head" style="margin-bottom:1.25rem">
        <h1 style="margin:0;font-size:1.35rem">مدیریت پشتیبانی</h1>
        <p style="margin:0.35rem 0 0;color:#64748b;font-size:0.9rem">پاسخ به تیکت‌های کاربران و مهمان‌ها</p>
    </div>

    @if(session('status'))
        <div style="padding:0.75rem 1rem;background:#dcfce7;color:#166534;border-radius:12px;margin-bottom:1rem">{{ session('status') }}</div>
    @endif

    <div style="display:flex;flex-direction:column;gap:1rem">
        @forelse($tickets as $t)
            <article style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:1.15rem 1.25rem;box-shadow:0 4px 16px rgba(15,23,42,0.04)">
                <header style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;justify-content:space-between;margin-bottom:0.85rem">
                    <div>
                        <strong style="font-size:1rem">#{{ $t->id }} — {{ $t->subject }}</strong>
                        <div style="font-size:0.8rem;color:#64748b;margin-top:0.25rem">
                            {{ $t->displayName() }}
                            @if($t->guest_email)
                                · {{ $t->guest_email }}
                            @endif
                            · {{ $t->statusLabel() }}
                            · {{ optional($t->last_reply_at ?? $t->updated_at)->diffForHumans() }}
                        </div>
                    </div>
                    <span class="status-pill status-{{ $t->statusColor() }}">{{ $t->statusLabel() }}</span>
                </header>

                <div style="max-height:220px;overflow-y:auto;background:#f8fafc;border-radius:12px;padding:0.85rem;margin-bottom:0.85rem">
                    @foreach($t->messages as $m)
                        <div style="margin-bottom:0.65rem;{{ $m->isFromStaff() ? '' : 'text-align:left' }}">
                            <div style="display:inline-block;max-width:90%;padding:0.5rem 0.75rem;border-radius:12px;font-size:0.88rem;{{ $m->isFromStaff() ? 'background:#3b5bdb;color:#fff' : 'background:#fff;border:1px solid #e2e8f0' }}">
                                {{ $m->body }}
                                <div style="font-size:0.68rem;opacity:0.75;margin-top:0.25rem">{{ $m->created_at->format('Y/m/d H:i') }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <form method="post" action="{{ route('admin.tickets.reply', $t->id) }}" style="display:flex;flex-direction:column;gap:0.6rem">
                    @csrf
                    <textarea name="body" required placeholder="پاسخ خود را بنویسید..." rows="3" style="border:1px solid #e2e8f0;border-radius:12px;padding:0.7rem 0.9rem;font-family:inherit;resize:vertical"></textarea>
                    <div style="display:flex;gap:0.5rem;align-items:center;justify-content:flex-end">
                        <select name="status" style="border:1px solid #e2e8f0;border-radius:10px;padding:0.45rem 0.7rem">
                            <option value="answered">پاسخ داده‌شده</option>
                            <option value="open">باز</option>
                            <option value="pending">در حال بررسی</option>
                            <option value="closed">بسته</option>
                        </select>
                        <button type="submit" class="btn primary">ارسال پاسخ</button>
                    </div>
                </form>
            </article>
        @empty
            <p style="color:#64748b">تیکتی وجود ندارد.</p>
        @endforelse
    </div>

    <div style="margin-top:1.25rem">{{ $tickets->links() }}</div>
</div>
@endsection
