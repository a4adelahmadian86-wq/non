@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261018detail">
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=20261018detail">

<div class="tk-page" dir="rtl" id="ticketDetailPage">
    <nav class="tk-breadcrumb">
        <div class="tk-breadcrumb-inner">
            <a href="{{ route('support.show', $ticket) }}" class="tk-crumb-strong">
                جزییات تیکت <bdi>{{ $ticket->id }}</bdi>
            </a>
            <span class="tk-crumb-sep"></span>
            <a href="{{ route('dashboard') }}" class="tk-crumb-icon" title="داشبورد"><i class="fa-solid fa-house"></i></a>
            <span class="tk-crumb-dash">-</span>
            <a href="{{ route('support') }}">تیکت ها</a>
            <span class="tk-crumb-dash">-</span>
            <span>شماره تیکت <bdi>{{ $ticket->id }}</bdi></span>
        </div>
    </nav>

    <main class="tk-main tk-main-wide">
        <article class="tk-card tk-detail-card" id="ticketDropZone">
            <div class="tk-card-head">
                <hgroup>
                    <h1>{{ $ticket->subject }}</h1>
                    <div class="tk-detail-sub">
                        <p>شماره تیکت : <span>{{ $ticket->id }}</span></p>
                        <span class="tk-vsep"></span>
                        <p>زمان ایجاد : <span>{{ $ticket->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</span></p>
                        <span class="tk-vsep"></span>
                        <p class="tk-status"><span class="tk-dot tk-dot-{{ $ticket->statusColor() }}"></span>{{ $ticket->statusLabel() }}</p>
                    </div>
                </hgroup>
                @if($ticket->status !== 'closed')
                    <button type="button" class="tk-btn-close" id="openCloseDialog">بستن تیکت</button>
                @endif
            </div>

            <div class="tk-divider"></div>

            <div class="tk-thread" id="chatMessages">
                @foreach($ticket->messages as $m)
                    @php $isMine = $m->user_id === auth()->id() && ! $m->isFromStaff(); @endphp
                    <div class="tk-bubble-row {{ $isMine ? 'is-user' : 'is-staff' }}">
                        <div class="tk-bubble-avatar {{ $isMine ? 'tk-av-user' : 'tk-av-staff' }}">
                            @if($isMine)
                                <i class="fa-solid fa-user"></i>
                            @else
                                <i class="fa-solid fa-headset"></i>
                            @endif
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
                                <button type="button" class="tk-icon-btn" id="pickAttachBtn" title="پیوست فایل">
                                    <i class="fa-solid fa-paperclip"></i>
                                </button>
                                <button type="submit" class="tk-send-btn" id="sendBtn" title="ارسال">
                                    <i class="fa-solid fa-paper-plane"></i>
                                </button>
                            </div>
                        </div>
                        <div class="tk-upload-status" id="uploadStatus" hidden>
                            <div class="tk-upload-row">
                                <i class="fa-solid fa-file" id="uploadIcon"></i>
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
                        <p class="tk-drop-hint" id="dropHint">فایل را بکشید و اینجا رها کنید، یا روی گیره کلیک کنید</p>
                    </form>
                </div>
            @else
                <div class="sd-closed">این تیکت بسته شده است. برای موضوع جدید، تیکت تازه ثبت کنید.</div>
            @endif

            {{-- Drag overlay --}}
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
        <p class="tk-dialog-text">آیا از بستن تیکت مطمئن هستید؟</p>
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
<script src="{{ asset('js/support-ticket-detail.js') }}?v=20261018detail"></script>
@endpush
@endsection
