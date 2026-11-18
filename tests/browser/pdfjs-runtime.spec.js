const { test, expect } = require('@playwright/test');

test('PDF.js engine, viewer and worker are served from the application origin at the pinned version', async ({ page }) => {
  const runtimeRequests = [];
  page.on('request', request => {
    const url = new URL(request.url());
    if (url.pathname.startsWith('/vendor/pdfjs/')) runtimeRequests.push(url.href);
  });

  await page.goto('/');

  const runtime = await page.evaluate(async () => {
    const engine = await import('/vendor/pdfjs/legacy/build/pdf.mjs');
    // The production reader exposes the engine before loading the viewer:
    // PDF.js's viewer bundle reads globalThis.pdfjsLib during module evaluation.
    window.pdfjsLib = engine;
    const viewer = await import('/vendor/pdfjs/legacy/web/pdf_viewer.mjs');
    const workerResponse = await fetch('/vendor/pdfjs/legacy/build/pdf.worker.mjs', {
      credentials: 'same-origin',
      cache: 'no-store',
    });
    const viewerCssResponse = await fetch('/vendor/pdfjs/legacy/web/pdf_viewer.css', {
      credentials: 'same-origin',
      cache: 'no-store',
    });
    const runtimeVersionResponse = await fetch('/vendor/pdfjs/VERSION', {
      credentials: 'same-origin',
      cache: 'no-store',
    });
    const packageIntegrityResponse = await fetch('/vendor/pdfjs/NPM_INTEGRITY', {
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
      viewerCssStatus: viewerCssResponse.status,
      viewerCssBodySize: (await viewerCssResponse.arrayBuffer()).byteLength,
      runtimeVersionStatus: runtimeVersionResponse.status,
      runtimeVersion: (await runtimeVersionResponse.text()).trim(),
      packageIntegrityStatus: packageIntegrityResponse.status,
      packageIntegrity: (await packageIntegrityResponse.text()).trim(),
    };
  });

  expect(runtime).toMatchObject({
    version: '6.5.438',
    canLoadDocuments: true,
    hasLinkService: true,
    hasEventBus: true,
    workerStatus: 200,
    viewerCssStatus: 200,
    runtimeVersionStatus: 200,
    runtimeVersion: '6.5.438',
    packageIntegrityStatus: 200,
    packageIntegrity: 'sha512-vg8Pw1x+AFKy4AMDvhuWl5cOYV8d698UoEANoFFhc79hqXDnZQqnOXoxw3HG907WHOg1JYOvLhP756boddFxpA==',
  });
  expect(runtime.workerBodySize).toBeGreaterThan(1000);
  expect(runtime.viewerCssBodySize).toBeGreaterThan(1000);
  expect(runtimeRequests.length).toBeGreaterThanOrEqual(3);

  const applicationOrigin = new URL(page.url()).origin;
  for (const requestUrl of runtimeRequests) {
    expect(new URL(requestUrl).origin).toBe(applicationOrigin);
  }
});
