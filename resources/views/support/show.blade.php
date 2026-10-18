@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261018pro2">
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=20261018pro2">

<div class="tk-page" dir="rtl">
    <nav class="tk-breadcrumb">
        <div class="tk-breadcrumb-inner">
            <a href="{{ route('support') }}" class="tk-crumb-strong">تیکت ها</a>
            <span class="tk-crumb-sep"></span>
            <a href="{{ route('dashboard') }}" class="tk-crumb-icon" title="داشبورد"><i class="fa-solid fa-house"></i></a>
            <span class="tk-crumb-dash">-</span>
            <a href="{{ route('support') }}">تیکت ها</a>
            <span class="tk-crumb-dash">-</span>
            <span>#{{ $ticket->id }}</span>
        </div>
    </nav>

    <main class="tk-main tk-main-wide">
        <article class="tk-card tk-chat-card">
            <div class="tk-card-head">
                <hgroup>
                    <h1>{{ $ticket->subject }}</h1>
                    <div class="tk-meta-line">
                        <span class="tk-status"><span class="tk-dot tk-dot-{{ $ticket->statusColor() }}"></span>{{ $ticket->statusLabel() }}</span>
                        <span>شماره {{ $ticket->id }}</span>
                    </div>
                </hgroup>
                <div class="tk-head-actions">
                    <a href="{{ route('support') }}" class="tk-btn-ghost">بازگشت به لیست</a>
                    @if($ticket->status !== 'closed')
                        <form method="post" action="{{ route('support.close', $ticket) }}" onsubmit="return confirm('آیا از بستن این تیکت مطمئن هستید؟')">
                            @csrf
                            <button type="submit" class="tk-btn-danger">بستن تیکت</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="tk-meta-grid">
                <div class="tk-meta-item"><small>دپارتمان</small><strong>{{ $ticket->departmentLabel() }}</strong></div>
                <div class="tk-meta-item"><small>اولویت</small><strong>{{ $ticket->priorityLabel() }}</strong></div>
                <div class="tk-meta-item"><small>تاریخ ایجاد</small><strong>{{ $ticket->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</strong></div>
                <div class="tk-meta-item"><small>آخرین به‌روزرسانی</small><strong>{{ optional($ticket->last_reply_at ?? $ticket->updated_at)->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</strong></div>
                @if($ticket->topicLabel())
                    <div class="tk-meta-item"><small>حوزه</small><strong>{{ $ticket->topicLabel() }}</strong></div>
                @endif
                @if($ticket->topic_area === 'services' && $ticket->serviceLabel())
                    <div class="tk-meta-item"><small>خدمت</small><strong>{{ $ticket->serviceLabel() }}</strong></div>
                @endif
                @if($ticket->topic_area === 'store' && $ticket->relatedProduct)
                    <div class="tk-meta-item"><small>محصول</small><strong>{{ $ticket->relatedProduct->title }}</strong></div>
                @endif
            </div>

            <div class="tk-divider"></div>

            <div class="sd-messages tk-messages" id="chatMessages">
                @foreach($ticket->messages as $m)
                    @php $isMine = $m->user_id === auth()->id() && ! $m->isFromStaff(); @endphp
                    <div class="sd-msg {{ $isMine ? 'sd-msg-user' : 'sd-msg-staff' }}">
                        @unless($isMine)
                            <div class="sd-avatar sd-avatar-sm"><i class="fa-solid fa-headset"></i></div>
                        @endunless
                        <div class="sd-msg-col">
                            <div class="sd-bubble">
                                <pre>{{ $m->body }}</pre>
                                @if(!empty($m->attachments))
                                    <div class="tk-attach-list">
                                        @foreach((array) $m->attachments as $att)
                                            <a href="{{ asset('storage/'.($att['path'] ?? '')) }}" target="_blank" rel="noopener">
                                                <i class="fa-solid fa-paperclip"></i>
                                                {{ $att['name'] ?? 'پیوست' }}
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <time class="sd-msg-time">
                                {{ $m->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }}
                                · {{ $isMine ? 'شما' : 'پشتیبانی' }}
                            </time>
                        </div>
                    </div>
                @endforeach
            </div>

            @if($ticket->status !== 'closed')
                <form class="sd-composer" id="chatComposer" method="post" action="{{ route('support.message', $ticket) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="sd-composer-inner">
                        <div class="sd-tools">
                            <button type="button" class="sd-tool" id="emojiToggle" title="ایموجی"><i class="fa-regular fa-face-smile"></i></button>
                            <label class="sd-tool" title="پیوست">
                                <i class="fa-solid fa-paperclip"></i>
                                <input type="file" name="attachment" id="composerFile" accept=".jpg,.jpeg,.png,.gif,.pdf,.txt,.zip" hidden>
                            </label>
                        </div>
                        <div class="sd-input-wrap">
                            <textarea name="body" id="composerBody" rows="1" required maxlength="5000" placeholder="متن پاسخ شما ..." dir="auto"></textarea>
                        </div>
                        <button type="submit" class="sd-send" id="sendBtn" title="ارسال">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                        </button>
                    </div>
                    <span class="sd-file-chip" id="composerFileName" hidden></span>
                    <div class="sd-emoji-panel" id="emojiPanel" hidden>
                        @foreach(['😀','😁','😂','😊','😍','🤔','👍','🙏','🔥','✨','✅','❌','❤️','🎉','💡','📎','😅','😢','🤝','👏'] as $e)
                            <button type="button" data-emoji="{{ $e }}">{{ $e }}</button>
                        @endforeach
                    </div>
                </form>
            @else
                <div class="sd-closed">این تیکت بسته شده است. برای موضوع جدید، تیکت تازه ثبت کنید.</div>
            @endif
        </article>
    </main>
</div>

@if(session('status'))
    <div class="sd-toast">{{ session('status') }}</div>
@endif

@push('scripts')
<script src="{{ asset('js/support-messenger.js') }}?v=20261018pro2"></script>
@endpush
@endsection
