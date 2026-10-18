@unless(request()->is('editor*') || request()->is('admin*') || request()->routeIs('dashboard') || request()->routeIs('support') || request()->routeIs('admin.*') || request()->routeIs('wallet') || request()->routeIs('platform.*'))

<button type="button" id="farast-support-fab" aria-label="گفتگو با پشتیبانی" title="پشتیبانی فراست">
    <i class="fa-solid fa-headset"></i>
</button>

<div id="farast-support-widget" aria-hidden="true" role="dialog" aria-label="چت پشتیبانی" data-auth="{{ auth()->check() ? '1' : '0' }}">
    <header class="sdw-head">
        <div class="sdw-head-info">
            <div class="sd-avatar sd-avatar-sm" style="background:rgba(255,255,255,.2)"><i class="fa-solid fa-headset"></i></div>
            <div>
                <strong>پشتیبانی فراست</strong>
                <small>@auth پاسخ در داشبورد نیز ذخیره می‌شود @else معمولاً زیر چند ساعت پاسخ می‌دهیم @endauth</small>
            </div>
        </div>
        <button type="button" class="sdw-close" data-fsw-close aria-label="بستن">&times;</button>
    </header>

    <div class="sdw-messages" data-fsw-messages></div>

    @guest
    <div class="sdw-contact" data-fsw-contact>
        <form data-fsw-contact-form>
            <input type="text" name="name" placeholder="نام شما" autocomplete="name">
            <input type="email" name="email" placeholder="ایمیل" required autocomplete="email">
            <button type="submit" class="sd-btn sd-btn-primary" style="width:100%">ادامه گفتگو</button>
        </form>
    </div>
    @endguest

    <form class="sdw-composer" data-fsw-form>
        <textarea data-fsw-input rows="1" placeholder="پیام بنویسید..." maxlength="3000" dir="auto"></textarea>
        <button type="submit" class="sd-send" aria-label="ارسال">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
        </button>
    </form>
</div>

<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261018pro">
<script src="{{ asset('js/support-widget.js') }}?v=20261018pro" defer></script>
@endunless
