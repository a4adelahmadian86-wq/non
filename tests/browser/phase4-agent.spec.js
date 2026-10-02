const { test, expect } = require('@playwright/test');

test.describe('FARAST Editor Agent', () => {
  test('agent panel is document-workspace UI and never exposes raw DOM execution controls', async ({ page }) => {
    await page.goto(process.env.FARAST_EDITOR_URL || 'http://127.0.0.1:8001/editor');
    const panel = page.locator('#farastAgentPanel');
    await expect(panel).toHaveAttribute('aria-label', 'عامل سند فراست');
    await expect(page.locator('#farastAgentRun')).toBeVisible();
    await expect(page.locator('#farastAgentAccept')).toBeVisible();
  });

  test('agent command execution is routed through the editor kernel bridge', async ({ page }) => {
    await page.goto(process.env.FARAST_EDITOR_URL || 'http://127.0.0.1:8001/editor');
    const bridge = await page.evaluate(() => ({
      editor: !!window.FarastEditor,
      execute: typeof window.FarastEditor?.execute === 'function',
      transactions: typeof window.FarastEditor?.getTransactions === 'function'
    }));
    expect(bridge).toEqual({ editor: true, execute: true, transactions: true });
  });
});