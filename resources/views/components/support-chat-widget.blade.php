{{-- ویجت شناور فرانت — فقط صفحات عمومی --}}
@unless(request()->is('editor*') || request()->is('admin*') || request()->routeIs('dashboard') || request()->routeIs('support') || request()->routeIs('admin.*') || request()->routeIs('wallet') || request()->routeIs('platform.*'))

<button type="button" id="farast-support-fab" class="rf-fab" aria-label="گفتگو با پشتیبانی" title="پشتیبانی">
    <i class="fa-solid fa-headset"></i>
</button>

<div id="farast-support-widget" class="rf-widget" aria-hidden="true" role="dialog" aria-label="چت پشتیبانی" data-auth="{{ auth()->check() ? '1' : '0' }}">
    <header class="rf-widget-head">
        <div class="rf-widget-head-info">
            <div class="rf-avatar rf-avatar-sm"><i class="fa-solid fa-headset"></i></div>
            <div>
                <strong>پشتیبانی فراست</strong>
                <small>@auth ادامه گفتگو در داشبورد @else معمولاً زیر چند ساعت پاسخ می‌دهیم @endauth</small>
            </div>
        </div>
        <button type="button" class="rf-widget-close" data-fsw-close aria-label="بستن">&times;</button>
    </header>

    <div class="rf-widget-messages" data-fsw-messages></div>

    @guest
    <div class="rf-widget-contact" data-fsw-contact>
        <form data-fsw-contact-form>
            <input type="text" name="name" placeholder="نام شما" autocomplete="name">
            <input type="email" name="email" placeholder="ایمیل" required autocomplete="email">
            <button type="submit" class="rf-btn-primary">ادامه</button>
        </form>
    </div>
    @endguest

    <form class="rf-widget-composer" data-fsw-form>
        <textarea data-fsw-input rows="1" placeholder="پیام بنویسید..." maxlength="3000" dir="auto"></textarea>
        <button type="submit" class="rf-send" aria-label="ارسال">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
        </button>
    </form>
</div>

<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261018r">
<script src="{{ asset('js/support-widget.js') }}?v=20261018c" defer></script>
@endunless
