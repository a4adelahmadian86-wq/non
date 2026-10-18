{{-- Floating support messenger for public pages --}}
@unless(request()->is('editor*') || request()->is('admin*') || request()->routeIs('dashboard') || request()->routeIs('support') || request()->routeIs('admin.*'))
<button type="button" id="farast-support-fab" aria-label="گفتگو با پشتیبانی" title="پشتیبانی">
    <i class="fa-solid fa-headset"></i>
    <span class="badge" aria-hidden="true">0</span>
</button>

<div id="farast-support-widget" aria-hidden="true" role="dialog" aria-label="چت پشتیبانی">
    <div class="fsw-header">
        <div class="fsw-header-info">
            <div class="av"><i class="fa-solid fa-headset"></i></div>
            <div>
                <h3>پشتیبانی فراست</h3>
                <p>آنلاین • معمولاً زیر چند ساعت پاسخ می‌دهیم</p>
            </div>
        </div>
        <button type="button" class="fsw-close" data-fsw-close aria-label="بستن">&times;</button>
    </div>

    <div class="fsw-messages" data-fsw-messages></div>

    <div class="fsw-contact" data-fsw-contact>
        <form data-fsw-contact-form>
            <input type="text" name="name" placeholder="نام شما" autocomplete="name">
            <input type="email" name="email" placeholder="ایمیل" required autocomplete="email">
            <button type="submit">ادامه</button>
        </form>
    </div>

    <form class="fsw-composer" data-fsw-form>
        <textarea data-fsw-input rows="1" placeholder="پیام بنویسید..." maxlength="3000" dir="rtl"></textarea>
        <button type="submit" class="fsw-send" aria-label="ارسال"><i class="fa-solid fa-paper-plane"></i></button>
    </form>
</div>

<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261018">
<script src="{{ asset('js/support-widget.js') }}?v=20261018" defer></script>
@endunless
