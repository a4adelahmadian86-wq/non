(() => {
  'use strict';
  const button = document.getElementById('farastInstallApp');
  const help = document.getElementById('farastPwaHelp');
  if (!button) return;

  const isStandalone = () =>
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone === true;
  const isIosSafari = () =>
    /iphone|ipad|ipod/i.test(navigator.userAgent) &&
    !/crios|fxios|edgios/i.test(navigator.userAgent);

  let installPrompt = null;
  const hideInstall = () => { button.hidden = true; };

  if (isStandalone()) {
    hideInstall();
  } else {
    window.addEventListener('beforeinstallprompt', event => {
      event.preventDefault();
      installPrompt = event;
      button.hidden = false;
    });

    if (isIosSafari()) button.hidden = false;
  }

  button.addEventListener('click', async () => {
    if (installPrompt) {
      const promptEvent = installPrompt;
      installPrompt = null;
      try {
        await promptEvent.prompt();
        const choice = await promptEvent.userChoice;
        if (choice && choice.outcome === 'accepted') hideInstall();
        else hideInstall();
      } catch (_) {
        hideInstall();
      }
      return;
    }

    if (isIosSafari() && help) {
      help.hidden = false;
      help.querySelector('.farast-pwa-help__close')?.focus();
    }
  });

  help?.querySelector('.farast-pwa-help__close')?.addEventListener('click', () => {
    help.hidden = true;
    button.focus();
  });

  window.addEventListener('appinstalled', hideInstall);

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    }, { once: true });
  }
})();
