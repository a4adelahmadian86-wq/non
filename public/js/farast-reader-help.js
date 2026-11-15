(() => {
  'use strict';
  const root = document.querySelector('[data-reader-root]');
  const toolbar = root?.querySelector('.farast-reader-toolbar');
  if (!root || !toolbar || root.dataset.shortcutGuideReady === 'true') return;
  root.dataset.shortcutGuideReady = 'true';

  const trigger = document.createElement('button');
  trigger.type = 'button';
  trigger.id = 'reader-shortcuts-help';
  trigger.className = 'reader-tool reader-shortcuts-help';
  trigger.setAttribute('aria-label', 'راهنمای میان‌برهای صفحه‌کلید');
  trigger.setAttribute('aria-haspopup', 'dialog');
  trigger.setAttribute('aria-controls', 'reader-shortcuts-dialog');
  trigger.title = 'راهنمای میان‌برها · ؟';
  trigger.textContent = '?';

  const dialog = document.createElement('dialog');
  dialog.id = 'reader-shortcuts-dialog';
  dialog.className = 'farast-reader-shortcuts-dialog';
  dialog.setAttribute('aria-labelledby', 'reader-shortcuts-title');
  dialog.innerHTML = `
    <div class="farast-reader-shortcuts-dialog__head">
      <div><span class="farast-reader-shortcuts-eyebrow">FARAST PDF STUDIO</span><h2 id="reader-shortcuts-title">میان‌برهای صفحه‌کلید</h2></div>
      <button type="button" class="farast-reader-shortcuts-close" aria-label="بستن راهنما">×</button>
    </div>
    <p class="farast-reader-shortcuts-intro">برای مطالعه سریع‌تر، بدون جابه‌جایی بین ابزارها از این کلیدها استفاده کنید.</p>
    <div class="farast-reader-shortcuts-table-wrap">
      <table class="farast-reader-shortcuts-table">
        <tbody>
          <tr><th scope="row">صفحه بعد / قبل</th><td><kbd>←</kbd> <kbd>→</kbd> <span>یا</span> <kbd>Page Down</kbd> <kbd>Page Up</kbd></td></tr>
          <tr><th scope="row">ابتدا / انتهای سند</th><td><kbd>Home</kbd> <kbd>End</kbd></td></tr>
          <tr><th scope="row">بزرگ‌نمایی / کوچک‌نمایی</th><td><kbd>+</kbd> <kbd>−</kbd></td></tr>
          <tr><th scope="row">جست‌وجو در سند</th><td><kbd>Ctrl</kbd> <kbd>F</kbd></td></tr>
          <tr><th scope="row">نتیجه بعدی / قبلی</th><td><kbd>Ctrl</kbd> <kbd>G</kbd> <span>و</span> <kbd>Ctrl</kbd> <kbd>Shift</kbd> <kbd>G</kbd></td></tr>
          <tr><th scope="row">تمام‌صفحه</th><td><kbd>F</kbd></td></tr>
          <tr><th scope="row">ثبت نشانک صفحه</th><td><kbd>B</kbd></td></tr>
          <tr><th scope="row">نمایش این راهنما</th><td><kbd>?</kbd></td></tr>
        </tbody>
      </table>
    </div>
    <p class="farast-reader-shortcuts-footnote">میان‌برها هنگام تایپ در ورودی‌ها و هنگام انتخاب متن سند غیرفعال هستند.</p>
    <div class="farast-reader-shortcuts-dialog__actions"><button type="button" class="farast-reader-shortcuts-done">متوجه شدم</button></div>
  `;

  toolbar.append(trigger);
  root.append(dialog);
  const close = () => { if (dialog.open) dialog.close(); };
  const open = () => {
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
    dialog.querySelector('.farast-reader-shortcuts-close')?.focus();
  };
  trigger.addEventListener('click', open);
  dialog.querySelector('.farast-reader-shortcuts-close')?.addEventListener('click', close);
  dialog.querySelector('.farast-reader-shortcuts-done')?.addEventListener('click', close);
  dialog.addEventListener('click', event => { if (event.target === dialog) close(); });
  dialog.addEventListener('close', () => trigger.focus());

  document.addEventListener('keydown', event => {
    if (!root.contains(event.target) && event.target !== document.body && event.target !== document.documentElement) return;
    if (event.ctrlKey || event.metaKey || event.altKey || event.key !== '?') return;
    if (event.target instanceof Element && event.target.closest('input,textarea,select,[contenteditable="true"],.farast-pdf-text-layer,.farast-pdf-cont-text,[data-reader-text-layer]')) return;
    event.preventDefault();
    if (!dialog.open) open();
  });
})();
