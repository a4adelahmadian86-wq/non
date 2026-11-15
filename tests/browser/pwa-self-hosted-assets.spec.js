const { test, expect } = require('@playwright/test');

test.describe('Self-hosted PWA presentation assets', () => {
  test('loads Persian typography and Font Awesome locally without CDN requests', async ({ page }) => {
    const cdnRequests = [];
    const fontResponses = [];

    page.on('request', request => {
      const url = new URL(request.url());
      if (url.hostname === 'cdnjs.cloudflare.com' || url.hostname === 'cdn.jsdelivr.net') {
        cdnRequests.push(url.href);
      }
    });
    page.on('response', response => {
      if (new URL(response.url()).pathname.startsWith('/fonts/')) {
        fontResponses.push({ path: new URL(response.url()).pathname, status: response.status() });
      }
    });

    await page.goto('/');
    const loadedFonts = await page.evaluate(async () => {
      const [persian, icons] = await Promise.all([
        document.fonts.load('400 16px "Vazirmatn"', 'فراست'),
        document.fonts.load('900 16px "Font Awesome 6 Free"', '\uF007'),
      ]);

      return {
        persian: persian.length,
        icons: icons.length,
        persianReady: document.fonts.check('400 16px "Vazirmatn"', 'فراست'),
        iconsReady: document.fonts.check('900 16px "Font Awesome 6 Free"', '\uF007'),
      };
    });

    expect(cdnRequests).toEqual([]);
    expect(loadedFonts.persian).toBeGreaterThan(0);
    expect(loadedFonts.icons).toBeGreaterThan(0);
    expect(loadedFonts.persianReady).toBe(true);
    expect(loadedFonts.iconsReady).toBe(true);
    expect(fontResponses).toEqual(expect.arrayContaining([
      expect.objectContaining({ path: '/fonts/vazirmatn-variable.woff2', status: 200 }),
      expect.objectContaining({ path: '/fonts/fontawesome/fa-solid-900.woff2', status: 200 }),
    ]));
  });
});
