@extends('layouts.app')

@section('content')
@php $lastMsgId = $ticket->messages->last()?->id ?? 0; @endphp
<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261019csat">
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=20261019csat">

<div class="tk-page" dir="rtl" id="ticketDetailPage"
     data-poll-url="{{ route('support.messages.poll', $ticket) }}"
     data-last-id="{{ $lastMsgId }}"
     data-ticket-status="{{ $ticket->status }}">
    <nav class="tk-breadcrumb">
        <div class="tk-breadcrumb-inner">
            <a href="{{ route('support.show', $ticket) }}" class="tk-crumb-strong">
                جزییات تیکت <bdi>{{ $ticket->displayCode() }}</bdi>
            </a>
            <span class="tk-crumb-sep"></span>
            <a href="{{ route('dashboard') }}" class="tk-crumb-icon" title="داشبورد"><i class="fa-solid fa-house"></i></a>
            <span class="tk-crumb-dash">-</span>
            <a href="{{ route('support') }}">تیکت ها</a>
            <span class="tk-crumb-dash">-</span>
            <span>شماره تیکت <bdi>{{ $ticket->displayCode() }}</bdi></span>
        </div>
    </nav>

    <main class="tk-main tk-main-wide">
        <article class="tk-card tk-detail-card" id="ticketDropZone">
            <div class="tk-card-head">
                <hgroup>
                    <h1>{{ $ticket->subject }}</h1>
                    <div class="tk-detail-sub">
                        <p>شماره تیکت : <span>{{ $ticket->displayCode() }}</span></p>
                        <span class="tk-vsep"></span>
                        <p>زمان ایجاد : <span>{{ $ticket->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</span></p>
                        <span class="tk-vsep"></span>
                        <p class="tk-status" id="ticketStatusBadge"><span class="tk-dot tk-dot-{{ $ticket->statusColor() }}"></span><span id="ticketStatusLabel">{{ $ticket->statusLabel() }}</span></p>
                        @if($ticket->department)
                            <span class="tk-vsep"></span>
                            <p>{{ $ticket->departmentLabel() }}</p>
                        @endif
                    </div>
                </hgroup>
                @if($ticket->status !== 'closed')
                    <button type="button" class="tk-btn-close" id="openCloseDialog">بستن تیکت</button>
                @endif
            </div>

            <div class="tk-divider"></div>

            <div class="tk-thread" id="chatMessages">
                @php $prevDay = null; @endphp
                @foreach($ticket->messages as $m)
                    @php
                        $day = $m->created_at->timezone(config('app.timezone'))->format('Y/m/d');
                        $isMine = $m->user_id === auth()->id() && ! $m->isFromStaff();
                    @endphp
                    @if($day !== $prevDay)
                        <div class="tk-day-sep"><span>{{ $day }}</span></div>
                        @php $prevDay = $day; @endphp
                    @endif
                    <div class="tk-bubble-row {{ $isMine ? 'is-user' : 'is-staff' }}" data-msg-id="{{ $m->id }}">
                        <div class="tk-bubble-avatar {{ $isMine ? 'tk-av-user' : 'tk-av-staff' }}">
                            <i class="fa-solid {{ $isMine ? 'fa-user' : 'fa-headset' }}"></i>
                        </div>
                        <div class="tk-bubble-col">
                            <div class="tk-bubble {{ $isMine ? 'tk-bubble-user' : 'tk-bubble-staff' }}">
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
                            <p class="tk-bubble-time {{ $isMine ? '' : 'is-end' }}">
                                {{ $m->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>

            @if($ticket->status !== 'closed')
                <div class="tk-reply-box" id="replyBox">
                    <form method="post" action="{{ route('support.message', $ticket) }}" enctype="multipart/form-data" id="chatComposer" class="tk-reply-form">
                        @csrf
                        <div class="tk-reply-inner">
                            <textarea name="body" id="composerBody" rows="4" required maxlength="5000" placeholder="متن پاسخ شما ..." dir="auto"></textarea>
                            <input type="file" name="attachment" id="composerFile" accept=".jpg,.jpeg,.png,.gif,.pdf,.txt,.zip,.doc,.docx" hidden>
                            <div class="tk-reply-actions">
                                <button type="button" class="tk-icon-btn" id="pickAttachBtn" title="پیوست فایل"><i class="fa-solid fa-paperclip"></i></button>
                                <button type="submit" class="tk-send-btn" id="sendBtn" title="ارسال"><i class="fa-solid fa-paper-plane"></i></button>
                            </div>
                        </div>
                        <div class="tk-upload-status" id="uploadStatus" hidden>
                            <div class="tk-upload-row">
                                <i class="fa-solid fa-file"></i>
                                <div class="tk-upload-meta">
                                    <strong id="uploadFileName">—</strong>
                                    <span id="uploadStateText">آماده ارسال</span>
                                </div>
                                <button type="button" class="tk-upload-remove" id="removeAttach" title="حذف">&times;</button>
                            </div>
                            <div class="tk-progress" id="uploadProgressWrap" hidden>
                                <div class="tk-progress-bar" id="uploadProgressBar"></div>
                            </div>
                        </div>
                        <p class="tk-drop-hint">فایل را بکشید و اینجا رها کنید، یا روی گیره کلیک کنید · Ctrl+Enter برای ارسال</p>
                    </form>
                </div>
            @else
                <div class="sd-closed">این تیکت بسته شده است. برای موضوع جدید، تیکت تازه ثبت کنید.</div>

                {{-- رضایت‌سنجی (CSAT) --}}
                @if($ticket->canRateCsat())
                    <div class="csat-box" id="csatBox">
                        <div class="csat-head">
                            <i class="fa-solid fa-face-smile"></i>
                            <div>
                                <strong>از پشتیبانی راضی بودید؟</strong>
                                <span>با امتیاز ۱ تا ۵ به کیفیت پاسخ‌گویی کمک کنید.</span>
                            </div>
                        </div>
                        <form method="post" action="{{ route('support.csat', $ticket) }}" id="csatForm" class="csat-form">
                            @csrf
                            <input type="hidden" name="score" id="csatScore" value="">
                            <div class="csat-stars" role="group" aria-label="امتیاز رضایت">
                                @for($i = 1; $i <= 5; $i++)
                                    <button type="button" class="csat-star" data-score="{{ $i }}" title="{{ $i }} از ۵" aria-label="{{ $i }}">
                                        <i class="fa-regular fa-star"></i>
                                    </button>
                                @endfor
                            </div>
                            <textarea name="comment" rows="2" maxlength="500" placeholder="نظر اختیاری (حداکثر ۵۰۰ کاراکتر)" class="csat-comment"></textarea>
                            <button type="submit" class="csat-submit" id="csatSubmit" disabled>ثبت امتیاز</button>
                        </form>
                        @error('csat')
                            <p class="csat-error">{{ $message }}</p>
                        @enderror
                    </div>
                @elseif($ticket->csat_score)
                    <div class="csat-box csat-done">
                        <div class="csat-head">
                            <i class="fa-solid fa-circle-check"></i>
                            <div>
                                <strong>امتیاز شما: {{ $ticket->csat_score }} از ۵</strong>
                                @if($ticket->csat_comment)
                                    <span>{{ $ticket->csat_comment }}</span>
                                @else
                                    <span>از بازخورد شما سپاسگزاریم.</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            @endif

            <div class="tk-drop-overlay" id="dropOverlay" hidden>
                <div class="tk-drop-overlay-card">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                    <strong>فایل را رها کنید</strong>
                    <span>برای افزودن پیوست به پاسخ</span>
                </div>
            </div>
        </article>
    </main>
</div>

<dialog id="closeTicketDialog" class="tk-dialog">
    <form method="post" action="{{ route('support.close', $ticket) }}" class="tk-dialog-body">
        @csrf
        <div class="tk-dialog-head">
            <p>بستن تیکت</p>
            <button type="button" class="tk-dialog-x" data-close-dialog>&times;</button>
        </div>
        <div class="tk-dialog-line"></div>
        <p class="tk-dialog-text">آیا از بستن تیکت مطمئن هستید؟ پس از بستن می‌توانید به کیفیت پشتیبانی امتیاز دهید.</p>
        <div class="tk-dialog-line"></div>
        <div class="tk-dialog-actions">
            <button type="button" class="tk-btn-ghost" data-close-dialog>لغو</button>
            <button type="submit" class="tk-btn-danger-solid">بستن تیکت</button>
        </div>
    </form>
</dialog>

@if(session('status'))
    <div class="sd-toast">{{ session('status') }}</div>
@endif

@push('scripts')
<script src="{{ asset('js/support-ticket-detail.js') }}?v=20261019csat"></script>
<script>
(function () {
    const form = document.getElementById('csatForm');
    if (!form) return;
    const scoreInput = document.getElementById('csatScore');
    const submitBtn = document.getElementById('csatSubmit');
    const stars = form.querySelectorAll('.csat-star');

    function paint(n) {
        stars.forEach(function (btn) {
            const s = parseInt(btn.dataset.score, 10);
            const icon = btn.querySelector('i');
            if (s <= n) {
                btn.classList.add('is-on');
                icon.classList.remove('fa-regular');
                icon.classList.add('fa-solid');
            } else {
                btn.classList.remove('is-on');
                icon.classList.remove('fa-solid');
                icon.classList.add('fa-regular');
            }
        });
        scoreInput.value = n || '';
        submitBtn.disabled = !n;
    }

    stars.forEach(function (btn) {
        btn.addEventListener('click', function () {
            paint(parseInt(btn.dataset.score, 10));
        });
        btn.addEventListener('mouseenter', function () {
            paint(parseInt(btn.dataset.score, 10));
        });
    });
    form.querySelector('.csat-stars')?.addEventListener('mouseleave', function () {
        paint(parseInt(scoreInput.value || '0', 10));
    });
})();
</script>
@endpush
@endsection
