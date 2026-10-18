@unless(request()->is('editor*') || request()->is('admin*') || request()->routeIs('dashboard') || request()->routeIs('support*') || request()->routeIs('admin.*') || request()->routeIs('wallet') || request()->routeIs('platform.*'))

<link rel="stylesheet" href="{{ asset('css/support-widget.css') }}?v=20261018goft">

{{-- حباب شناور سمت راست --}}
<div id="fsw-holder" class="fsw-holder" dir="rtl">
    <button type="button" id="farast-support-fab" class="fsw-bubble" aria-label="گفتگو با پشتیبانی" title="پشتیبانی فراست">
        <span class="fsw-bubble-face" data-fsw-icon="chat">
            <svg viewBox="0 0 120 120" width="34" height="34" aria-hidden="true">
                <path fill="#fff" d="M60.19,53.75a3,3,0,1,0,3.06,3A3,3,0,0,0,60.19,53.75Zm-11.37,0a3,3,0,1,0,3.06,3A3,3,0,0,0,48.81,53.75Zm45.94,4A35,35,0,1,0,52.75,92v12.76s14.55-4.25,30.53-19.28C94.68,74.74,94.75,59.41,94.75,59.41l0,0C94.74,58.87,94.75,58.3,94.75,57.72Zm-10.14.6s0,10.64-8,18.09A57.93,57.93,0,0,1,53,89.8V80.34A24.29,24.29,0,1,1,84.61,57.16c0,.4,0,.8,0,1.19ZM70.69,53.75a3,3,0,1,0,3.06,3A3,3,0,0,0,70.69,53.75Z" transform="translate(0.25 0.25)"/>
            </svg>
        </span>
        <span class="fsw-bubble-face fsw-bubble-close" data-fsw-icon="close" hidden>
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round">
                <path d="M6 6l12 12M18 6L6 18"/>
            </svg>
        </span>
        <span class="fsw-unread" data-fsw-unread hidden>1</span>
    </button>

    <div id="farast-support-widget" class="fsw-panel" aria-hidden="true" role="dialog" aria-label="چت پشتیبانی" data-auth="{{ auth()->check() ? '1' : '0' }}">
        <header class="fsw-head">
            <div class="fsw-avatars" aria-hidden="true">
                <span class="fsw-av"><i class="fa-solid fa-headset"></i></span>
                <span class="fsw-av fsw-av-2"><i class="fa-solid fa-user"></i></span>
                <span class="fsw-online" title="آنلاین"></span>
            </div>
            <div class="fsw-head-text">
                <strong>پشتیبانی فراست</strong>
                <small>پاسخگوی سوالات شما هستیم...</small>
            </div>
            <button type="button" class="fsw-head-close" data-fsw-close aria-label="بستن">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </header>

        <div class="fsw-body" data-fsw-messages>
            <div class="fsw-msg fsw-msg-bot">
                <div class="fsw-msg-bubble">
                    موضوع سوال شما در چه موردی است؟
                    <div class="fsw-qa" data-fsw-qa>
                        <button type="button" data-qa="راهنمایی شروع کار با فراست">از کجا شروع کنم؟</button>
                        <button type="button" data-qa="سوال درباره خرید و فروشگاه">خرید و فروشگاه</button>
                        <button type="button" data-qa="سوال درباره خدمات تایپ و تبدیل">خدمات تایپ و تبدیل</button>
                        <button type="button" data-qa="سوال مالی و کیف پول">بخش مالی و کیف پول</button>
                        <button type="button" data-qa="گفتگو با کارشناس پشتیبانی">💬 گفتگو با کارشناس</button>
                    </div>
                </div>
            </div>
            <div class="fsw-msg fsw-msg-bot">
                <div class="fsw-msg-bubble">سلام 👋 پیام خود را بنویسید؛ تیم پشتیبانی در اسرع وقت پاسخ می‌دهد.</div>
                <time>همین الان</time>
            </div>
        </div>

        @guest
        <div class="fsw-contact" data-fsw-contact>
            <p>برای شروع گفتگو، نام و ایمیل خود را وارد کنید:</p>
            <form data-fsw-contact-form>
                <input type="text" name="name" placeholder="نام شما" autocomplete="name">
                <input type="email" name="email" placeholder="ایمیل" required autocomplete="email">
                <button type="submit" class="fsw-btn-primary">ادامه گفتگو</button>
            </form>
        </div>
        @endguest

        <div class="fsw-emoji" data-fsw-emoji hidden>
            @foreach(['😀','😁','😂','😊','😍','🤔','👍','🙏','🔥','✨','✅','❌','❤️','🎉','💡','😅','😢','🤝','👏','📎'] as $e)
                <button type="button" data-emoji="{{ $e }}">{{ $e }}</button>
            @endforeach
        </div>

        <form class="fsw-footer" data-fsw-form>
            <button type="button" class="fsw-tool" data-fsw-emoji-toggle title="ایموجی" aria-label="ایموجی">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M8.5 14s1.5 2 3.5 2 3.5-2 3.5-2"/><circle cx="9" cy="10" r="1" fill="currentColor"/><circle cx="15" cy="10" r="1" fill="currentColor"/></svg>
            </button>
            <textarea data-fsw-input rows="1" placeholder="پیامی بنویسید..." maxlength="3000" dir="auto"></textarea>
            <button type="submit" class="fsw-send" aria-label="ارسال">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9.51 4.23l8.56 4.28c3.84 1.92 3.84 5.06 0 6.98l-8.56 4.28c-5.76 2.88-8.11.52-5.23-5.23l.87-1.73c.22-.44.22-1.17 0-1.61l-.87-1.74C1.4 3.71 3.76 1.35 9.51 4.23z"/>
                    <path d="M5.44 12h5.4"/>
                </svg>
            </button>
        </form>
    </div>
</div>

<script src="{{ asset('js/support-widget.js') }}?v=20261018goft" defer></script>
@endunless
