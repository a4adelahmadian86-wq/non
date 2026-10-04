/* Temporary recovery loader: restores full Editor Kernel from known-good commit 5e4a0d4.
 * Voice import assets (#mic, farast-voice.js Kernel path, voice.css) remain on main.
 * Replace this file with the full editor-core.js blob when large-file push is available.
 */
(async function farastEditorCoreRecovery() {
  'use strict';
  const sources = [
    'https://cdn.jsdelivr.net/gh/a4adelahmadian86-wq/non@5e4a0d4ab640a7cb920c57bbddb2cae11beaf0d6/public/js/editor-core.js',
    'https://raw.githubusercontent.com/a4adelahmadian86-wq/non/5e4a0d4ab640a7cb920c57bbddb2cae11beaf0d6/public/js/editor-core.js'
  ];
  let lastError = null;
  for (const url of sources) {
    try {
      const response = await fetch(url, { cache: 'no-store' });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const source = await response.text();
      if (!source || source.indexOf('FarastEditor') < 0) throw new Error('invalid kernel payload');
      (0, eval)(source);
      return;
    } catch (error) {
      lastError = error;
    }
  }
  console.error('[FARAST Editor Kernel] recovery failed', lastError);
  const status = document.getElementById('statusText');
  if (status) status.textContent = 'بازیابی هسته ویرایشگر ناموفق بود.';
})();
