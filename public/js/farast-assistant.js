/* FARAST global floating assistant — public pages.
   No parallel AI pipeline. Public chat backend is not available on FARAST yet;
   panel guides users to existing editor AI and site navigation. */
(function () {
  'use strict';
  if (document.getElementById('farast-global-assistant')) return;
  if (document.body.classList.contains('editor-page-body')) return;

  var OPEN_KEY = 'farast_assistant_open';
  var root = document.createElement('div');
  root.id = 'farast-global-assistant';
  root.innerHTML =
    '<button class="farast-assistant-launcher" type="button" aria-label="دستیار فراست" aria-expanded="false">' +
    '<i class="fa-solid fa-sparkles" aria-hidden="true"></i><i class="fa-dot" aria-hidden="true"></i></button>' +
    '<section class="farast-assistant-panel" aria-label="دستیار فراست" hidden>' +
    '<header class="farast-assistant-head">' +
    '<div class="farast-assistant-avatar"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i></div>' +
    '<div><strong>دستیار فراست</strong><small>راهنمای فروشگاه، تایپ و خدمات هوشمند</small></div>' +
    '<button class="farast-assistant-close" type="button" aria-label="بستن">×</button></header>' +
    '<div class="farast-assistant-messages" data-fa-messages aria-live="polite"></div>' +
    '<div class="farast-assistant-suggestions">' +
    '<button type="button" class="farast-assistant-chip" data-fa-text="چطور فایل بخرم؟">خرید فایل</button>' +
    '<button type="button" class="farast-assistant-chip" data-fa-text="تایپ صوتی کجاست؟">تایپ صوتی</button>' +
    '<button type="button" class="farast-assistant-chip" data-fa-text="دستیار ویرایشگر">دستیار ویرایشگر</button>' +
    '<button type="button" class="farast-assistant-chip" data-fa-text="پشتیبانی">پشتیبانی</button>' +
    '</div>' +
    '<p class="farast-assistant-note">گفتگوی عمومی هنوز به backend متصل نیست. برای پردازش متن از <a href="/editor">ویرایشگر فراست</a> استفاده کنید.</p>' +
    '<form class="farast-assistant-form" autocomplete="off">' +
    '<input name="message" maxlength="500" placeholder="سوالت را بنویس…" aria-label="پیام">' +
    '<button type="submit" aria-label="ارسال">➤</button></form></section>';

  document.body.appendChild(root);

  var panel = root.querySelector('.farast-assistant-panel');
  var launcher = root.querySelector('.farast-assistant-launcher');
  var closeBtn = root.querySelector('.farast-assistant-close');
  var list = root.querySelector('[data-fa-messages]');
  var form = root.querySelector('form');
  var input = form.querySelector('input');

  var WELCOME =
    'سلام. من دستیار رابط فراست هستم. می‌توانم مسیرهای سایت را راهنمایی کنم. پردازش هوشمند متن در ویرایشگر فراست در دسترس است.';

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function addMsg(role, text) {
    var el = document.createElement('div');
    el.className = 'farast-assistant-msg ' + role;
    el.innerHTML = esc(text);
    list.appendChild(el);
    list.scrollTop = list.scrollHeight;
  }

  function replyFor(text) {
    var t = String(text || '').trim();
    var low = t.toLowerCase();
    if (/خرید|فایل|فروشگاه|سبد/.test(t) || low.indexOf('buy') >= 0) {
      return 'برای خرید فایل به فروشگاه بروید: /store — سبد خرید در /cart در دسترس است.';
    }
    if (/تایپ صوتی|میکروفون|voice|صدا/.test(t)) {
      return 'تایپ صوتی داخل ویرایشگر فراست است. پس از ورود به /editor از دکمه «تایپ صوتی» در نوار وضعیت یا روبان استفاده کنید.';
    }
    if (/دستیار|ویرایشگر|ai|هوش|بازنویسی|خلاصه/.test(t)) {
      return 'دستیار هوشمند متن در ویرایشگر فعال است: متن را انتخاب کنید و از پنل «دستیار هوشمند» یا ابزارهای AI روبان استفاده کنید. مسیر: /editor';
    }
    if (/پشتیبانی|کمک|support|تیکت/.test(t)) {
      return 'برای پشتیبانی به /support مراجعه کنید. پرسش‌های حساب و کیف پول نیز از داشبورد در دسترس است.';
    }
    if (/قیمت|تعرفه|pricing/.test(t)) {
      return 'تعرفه‌ها در /pricing منتشر شده‌اند.';
    }
    return 'گفتگوی عمومی هنوز به backend متصل نیست. برای پردازش متن و تایپ صوتی وارد ویرایشگر شوید: /editor — پشتیبانی: /support';
  }

  function setOpen(open) {
    panel.classList.toggle('is-open', open);
    panel.hidden = !open;
    launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
    try {
      sessionStorage.setItem(OPEN_KEY, open ? '1' : '0');
    } catch (e) {}
    if (open) setTimeout(function () { input.focus(); }, 160);
  }

  list.innerHTML = '';
  addMsg('bot', WELCOME);

  launcher.addEventListener('click', function () {
    setOpen(!panel.classList.contains('is-open'));
  });
  closeBtn.addEventListener('click', function () { setOpen(false); });

  root.querySelectorAll('.farast-assistant-chip').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-fa-text') || btn.textContent;
      addMsg('user', text);
      addMsg('bot', replyFor(text));
    });
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = (input.value || '').trim();
    if (!text) return;
    input.value = '';
    addMsg('user', text);
    addMsg('bot', replyFor(text));
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && panel.classList.contains('is-open')) setOpen(false);
  });

  try {
    if (sessionStorage.getItem(OPEN_KEY) === '1') setOpen(true);
  } catch (e) {}
})();
