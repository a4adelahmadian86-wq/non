const { test, expect } = require('@playwright/test');

test('PDF.js engine, viewer and worker are served from the application origin at the pinned version', async ({ page }) => {
  const runtimeRequests = [];
  page.on('request', request => {
    const url = new URL(request.url());
    if (url.pathname.startsWith('/vendor/pdfjs/')) runtimeRequests.push(url.href);
  });

  await page.goto('/');

  const runtime = await page.evaluate(async () => {
    const [engine, viewer] = await Promise.all([
      import('/vendor/pdfjs/legacy/build/pdf.mjs'),
      import('/vendor/pdfjs/legacy/web/pdf_viewer.mjs'),
    ]);
    const workerResponse = await fetch('/vendor/pdfjs/legacy/build/pdf.worker.mjs', {
      credentials: 'same-origin',
      cache: 'no-store',
    });

    return {
      version: engine.version,
      canLoadDocuments: typeof engine.getDocument === 'function',
      hasLinkService: typeof viewer.PDFLinkService === 'function',
      hasEventBus: typeof viewer.EventBus === 'function',
      workerStatus: workerResponse.status,
      workerBytes: Number(workerResponse.headers.get('content-length') || 0),
      workerBodySize: (await workerResponse.arrayBuffer()).byteLength,
    };
  });

  expect(runtime).toMatchObject({
    version: '6.5.438',
    canLoadDocuments: true,
    hasLinkService: true,
    hasEventBus: true,
    workerStatus: 200,
  });
  expect(runtime.workerBodySize).toBeGreaterThan(1000);
  expect(runtimeRequests.length).toBeGreaterThanOrEqual(3);

  const applicationOrigin = new URL(page.url()).origin;
  for (const requestUrl of runtimeRequests) {
    expect(new URL(requestUrl).origin).toBe(applicationOrigin);
  }
});
