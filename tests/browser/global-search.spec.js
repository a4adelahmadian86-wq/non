const { test, expect } = require('@playwright/test');

test.describe('Global search experience', () => {
  test('header search suggests public articles and opens the selected result', async ({ page }) => {
    await page.goto('/');
    const search = page.locator('#farastGlobalSearch');
    const suggestions = page.locator('#farastSearchSuggestions');

    await search.fill('OCR');
    await expect(suggestions).toBeVisible();
    const article = suggestions.getByRole('option', { name: /اسکن تا سند قابل ویرایش/ });
    await expect(article).toBeVisible();
    await article.click();

    await expect(page).toHaveURL(/document-digitization$/);
    await expect(page.locator('h1')).toContainText('اسکن تا سند قابل ویرایش');
  });

  test('suggestions support keyboard selection and escape dismissal', async ({ page }) => {
    await page.goto('/');
    const search = page.locator('#farastGlobalSearch');
    const suggestions = page.locator('#farastSearchSuggestions');

    await search.fill('OCR');
    await expect(suggestions).toBeVisible();
    await search.press('ArrowDown');
    await expect(search).toHaveAttribute('aria-activedescendant', /^farast-search-option-/);
    await search.press('Escape');
    await expect(suggestions).toBeHidden();
    await expect(search).toHaveAttribute('aria-expanded', 'false');
  });
});
