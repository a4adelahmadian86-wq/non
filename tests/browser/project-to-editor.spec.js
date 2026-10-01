const { test, expect } = require('@playwright/test');

test.afterEach(async ({ page }) => {
  try {
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    if (csrf) await page.evaluate(async token => { await fetch('/logout', { method: 'POST', headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, credentials: 'same-origin' }); }, csrf);
  } catch (_) {}
});

test('logs in through the real auth flow, creates a typing project, and opens the real word processor surface', async ({ page }) => {
  const pageErrors = [];
  const consoleErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error)));
  page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });
  const password = `${process.env.GITHUB_RUN_ID || 'local'}-farast-e2e`;

  await page.goto('/login?continue=/projects/new');
  await page.locator('input[name="mobile"]').fill('09151234567');
  const phoneResponsePromise = page.waitForResponse(response => response.url().endsWith('/login/phone'));
  await page.getByRole('button', { name: /ادامه با شماره موبایل/ }).click();
  const phoneResponse = await phoneResponsePromise;
  if (!phoneResponse.ok()) throw new Error(`/login/phone failed: ${phoneResponse.status()} ${await phoneResponse.text()}`);
  await expect(page).toHaveURL(/\/login\/password/);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: 'ورود به حساب' }).click();

  await expect(page).toHaveURL(/\/projects\/new/);
  await expect(page.getByRole('heading', { name: 'پروژه جدید' })).toBeVisible();

  await page.getByRole('button', { name: /تایپ/ }).click();
  await page.getByRole('button', { name: 'خودم تایپ می‌کنم' }).click();

  const templateCards = page.locator('#choices .project-card');
  await expect(templateCards.first()).toBeVisible();
  await templateCards.first().click();

  await page.getByRole('button', { name: 'ایجاد پروژه' }).click();
  await expect(page).toHaveURL(/\/editor(?:\?.*)?$/);
  await expect(page.locator('#farastWord')).toBeVisible({ timeout: 5000 });
  await expect(page.locator('#farastWord')).toHaveAttribute('data-editor-ready', '1', { timeout: 5000 });
  try {
    await expect(page.locator('#pagesViewport .farast-page')).toBeVisible({ timeout: 5000 });
  } catch (error) {
    throw new Error(`${error.message}\nSTATUS=${JSON.stringify(await page.locator('#statusText').textContent())}\nSAVE_STATE=${JSON.stringify(await page.locator('#saveState').textContent())}\nPAGE_ERRORS=${JSON.stringify(pageErrors)}\nCONSOLE_ERRORS=${JSON.stringify(consoleErrors)}`);
  }
  await expect(page.locator('.farast-editor').first()).toHaveAttribute('contenteditable', 'true');
  await expect(page.locator('#docTitle')).toHaveValue('سند جدید');

  const editor = page.locator('.farast-editor').first();
  await editor.click();
  await page.keyboard.type('سلام فراست');
  await expect(editor).toContainText('سلام فراست');
  await page.keyboard.type(' FARAST 2026');
  await expect(editor).toContainText('FARAST 2026');
  await page.keyboard.press('Control+a');
  const selection = await page.evaluate(() => { const s = window.FarastEditor?.getSelection?.(); return s ? { text: s.text, start: s.start, end: s.end } : null; });
  if (!selection?.text?.includes('سلام فراست')) throw new Error('SELECTION='+JSON.stringify(selection));
  await page.locator('.farast-ribbon button[data-command="bold"]').click();
  const modelAfterBold = await page.evaluate(() => window.FarastEditor?.state?.model?.sections?.[0]?.blocks || null);
  const domAfterBold = await editor.innerHTML();
  if (!JSON.stringify(modelAfterBold).includes('"bold":true')) { const debug = await page.evaluate(() => ({ stateSelection: window.FarastEditor?.state?.selection || null, lastSelection: window.FarastEditor?.state?.lastSelection || null, raw: (() => { const s = getSelection(); return s ? { text: s.toString(), anchorNode: s.anchorNode?.nodeType, anchorOffset: s.anchorOffset, focusNode: s.focusNode?.nodeType, focusOffset: s.focusOffset } : null; })() })); throw new Error('MODEL_AFTER_BOLD='+JSON.stringify(modelAfterBold)+' DOM='+domAfterBold+' DEBUG='+JSON.stringify(debug)); }
  await expect.poll(async () => (await editor.locator('strong').allTextContents()).join('')).toContain('سلام فراست');
  const transactionLog = await page.evaluate(() => window.FarastEditor?.getTransactions?.() || []);
  expect(transactionLog.some(x => x.command === 'InsertText')).toBeTruthy();
  expect(transactionLog.some(x => x.command === 'FormatText')).toBeTruthy();
  expect(transactionLog.every(x => x.id && x.timestamp && x.command && x.source && x.precondition)).toBeTruthy();

  const kernelContract = await page.evaluate(() => ({
    commands: ['InsertText','DeleteRange','FormatText','SetParagraphStyle','SetDirection','SplitParagraph','MergeParagraph','InsertPageBreak','InsertTable','InsertImage','InsertLink','AddComment','RemoveComment','AddBookmark','RemoveBookmark','undo','redo'].every(name => window.FarastEditor?.commands?.has(name)),
    selection: window.FarastEditor?.state?.selection ? {
      start: window.FarastEditor.state.selection.start,
      end: window.FarastEditor.state.selection.end
    } : null,
    canonical: window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.every(b => b.id && (b.type === 'page_break' || b.type === 'image' || Array.isArray(b.runs) || Array.isArray(b.items) || Array.isArray(b.rows)))
  }));
  expect(kernelContract.commands).toBeTruthy();
  expect(kernelContract.canonical).toBeTruthy();
  expect(kernelContract.selection?.start?.blockId).toBeTruthy();
  expect(typeof kernelContract.selection.start.offset).toBe('number');


  await page.keyboard.press('Control+z');
  await expect(editor.locator('strong')).toHaveCount(0);
  await page.keyboard.press('Control+y');
  await expect.poll(async () => (await editor.locator('strong').allTextContents()).join('')).toContain('سلام فراست');
  const beforeSaveSemantic = await page.evaluate(() => ({
    plain: window.FarastEditor?.state?.model?.plain_text || '',
    runs: window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.map(b => ({ type: b.type, text: (b.runs || []).map(r => r.text).join('') })) || []
  }));
  if (!beforeSaveSemantic.plain.includes('سلام فراست')) throw new Error('BEFORE_SAVE_MODEL='+JSON.stringify(beforeSaveSemantic));
  const beforeSaveRunsText = await page.evaluate(() => (window.FarastEditor?.state?.model?.sections || []).flatMap(section => section.blocks || []).map(block => (block.runs || []).map(run => run.text || '').join('')).join('\\n'));
  expect(beforeSaveRunsText).toContain('سلام فراست');
  expect(beforeSaveRunsText).toContain('FARAST 2026');
  const saveDocumentId = await page.evaluate(() => String(window.FarastEditor?.state?.documentId || ''));
  if (!saveDocumentId) throw new Error('SAVE_DOCUMENT_ID_MISSING');
  const saveResponsePromise = page.waitForResponse(response => response.url().endsWith('/editor/save') && response.request().method() === 'POST');
  await page.locator('#saveNow').click();
  const saveResponse = await saveResponsePromise;
  const savePayload = saveResponse.request().postDataJSON();
  const saveBody = await saveResponse.json();
  if (!saveResponse.ok()) throw new Error('SAVE_RESPONSE=' + JSON.stringify(saveBody));
  expect(String(savePayload.document_id)).toBe(saveDocumentId);
  expect(savePayload.document_model?.plain_text || '').toContain('سلام فراست');
  expect(savePayload.document_model?.plain_text || '').toContain('FARAST 2026');
  const savePayloadRunsText = (savePayload.document_model?.sections || []).flatMap(section => section.blocks || []).map(block => (block.runs || []).map(run => run.text || '').join('')).join('\\n');
  expect(savePayloadRunsText).toContain('سلام فراست');
  expect(savePayloadRunsText).toContain('FARAST 2026');
  expect(Number(saveBody.document_id)).toBe(Number(saveDocumentId));
  expect(Number(saveBody.revision)).toBeGreaterThan(0);
  await expect(page.locator('#saveState')).toHaveText('ذخیره شد', { timeout: 5000 });

  const canonicalState = await page.evaluate(async (documentId) => {
    const response = await fetch('/editor/documents/' + documentId + '/state', { headers: { Accept: 'application/json' } });
    return { status: response.status, body: await response.json() };
  }, saveDocumentId);
  if (canonicalState.status !== 200) throw new Error('CANONICAL_STATE_RESPONSE=' + JSON.stringify(canonicalState));
  expect(Number(canonicalState.body.document_id)).toBe(Number(saveDocumentId));
  expect(Number(canonicalState.body.revision)).toBe(Number(saveBody.revision));
  const canonicalRunsText = (canonicalState.body.document_model?.sections || []).flatMap(section => section.blocks || []).map(block => {
    if (block.type === 'table') return (block.rows || []).flatMap(row => row.cells || []).map(cell => (cell.runs || []).map(run => run.text || '').join('')).join(' ');
    if (block.type === 'list') return (block.items || []).map(item => (item.runs || []).map(run => run.text || '').join('')).join(' ');
    return (block.runs || []).map(run => run.text || '').join('');
  }).join('\\n');
  expect(canonicalRunsText).toContain('سلام فراست');
  expect(canonicalRunsText).toContain('FARAST 2026');
  expect(canonicalState.body.document_model?.plain_text || '').toContain('سلام فراست');
  expect(canonicalState.body.document_model?.plain_text || '').toContain('FARAST 2026');

  await page.reload();
  await expect(page.locator('#pagesViewport .farast-page').first()).toBeVisible({ timeout: 5000 });
  await expect(page.locator('#farastWord')).toHaveAttribute('data-editor-ready', '1', { timeout: 5000 });
  const reloadedDocumentId = await page.locator('#farastWord').getAttribute('data-document-id');
  expect(reloadedDocumentId).toBe(saveDocumentId);
  const reloadedModelState = await page.evaluate(() => ({
    plainText: window.FarastEditor?.state?.model?.plain_text || '',
    revision: Number(window.FarastEditor?.state?.revision || 0),
    documentId: Number(window.FarastEditor?.state?.documentId || 0)
  }));
  expect(reloadedModelState.documentId).toBe(Number(saveDocumentId));
  expect(reloadedModelState.revision).toBeGreaterThanOrEqual(Number(saveBody.revision));
  expect(reloadedModelState.plainText).toContain('سلام فراست');
  expect(reloadedModelState.plainText).toContain('FARAST 2026');

  const paragraph = 'این یک پاراگراف فارسی برای آزمون صفحه‌بندی پایدار فراست است. FARAST 2026.';
  await page.context().grantPermissions(['clipboard-read', 'clipboard-write'], { origin: process.env.FARAST_E2E_URL || 'http://127.0.0.1:8000' });
  await page.evaluate(() => navigator.clipboard.writeText('متن چسبانده‌شده فارسی FARAST'));
  await page.locator('.farast-editor').first().click();
  await page.keyboard.press('Control+a');
  await page.keyboard.press('Control+v');
  await expect(page.locator('.farast-editor').first()).toContainText('متن چسبانده‌شده فارسی FARAST');
  await page.locator('.farast-tab[data-tab="insert"]').click();
  await expect(page.locator('.farast-ribbon button[data-command="pageBreak"]')).toBeVisible();
  await page.locator('.farast-ribbon button[data-command="pageBreak"]').click();
  await expect.poll(async () => page.evaluate(() => window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.some(b => b.type === 'page_break'))).toBeTruthy();

  await page.locator('.farast-tab[data-tab="home"]').click();
  await page.locator('.farast-editor').first().click();
  await page.keyboard.press('Control+a');
  await page.locator('.farast-ribbon button[data-command="ul"]').click();
  await expect.poll(async () => page.evaluate(() => window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.some(b => b.type === 'list' && !b.ordered))).toBeTruthy();
  await page.keyboard.press('Control+z');
  await expect.poll(async () => page.evaluate(() => !window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.some(b => b.type === 'list'))).toBeTruthy();
  await page.locator('.farast-tab[data-tab="insert"]').click();
  const acceptTableDialogs = dialog => dialog.accept('2');
  page.on('dialog', acceptTableDialogs);
  await page.locator('.farast-ribbon button[data-command="table"]').click();
  await expect.poll(async () => page.evaluate(() => window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.some(b => b.type === 'table' && b.rows?.length === 2 && b.rows?.[0]?.cells?.length === 2))).toBeTruthy();
  page.off('dialog', acceptTableDialogs);
  await page.keyboard.press('Control+z');
  await expect.poll(async () => page.evaluate(() => !window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.some(b => b.type === 'table'))).toBeTruthy();

  await page.locator('.farast-editor').first().click();
  await page.keyboard.press('Control+a');
  await page.locator('.farast-ribbon button[data-command="ul"]').click();
  await expect.poll(async () => page.evaluate(() => window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.some(b => b.type === 'list' && !b.ordered))).toBeTruthy();
  await expect.poll(async () => page.evaluate(() => window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.some(b => b.type === 'page_break'))).toBeTruthy();

  const listBlockId = await page.evaluate(() => window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.find(b => b.type === 'list')?.id || null);
  expect(listBlockId).toBeTruthy();
  await page.locator('#saveNow').click();
  await expect(page.locator('#saveState')).toHaveText('ذخیره شد', { timeout: 5000 });
  await page.reload();
  await expect(page.locator('#farastWord')).toHaveAttribute('data-editor-ready', '1', { timeout: 5000 });
  const reloadedListState = await page.evaluate(() => {
    const blocks = window.FarastEditor?.state?.model?.sections?.[0]?.blocks || [];
    const list = blocks.find(b => b.type === 'list');
    return { list: list ? { ordered: list.ordered, items: list.items?.length || 0, levels: (list.items || []).map(i => i.level ?? 0) } : null, hasPageBreak: blocks.some(b => b.type === 'page_break') };
  });
  expect(reloadedListState.list).toBeTruthy();
  expect(reloadedListState.list.ordered).toBeFalsy();
  expect(reloadedListState.list.items).toBeGreaterThan(0);
  expect(reloadedListState.hasPageBreak).toBeTruthy();

  const firstListItem = page.locator('.farast-editor li').first();
  await firstListItem.scrollIntoViewIfNeeded();
  await firstListItem.click();
  await page.locator('.farast-ribbon button[data-command="indent"]').click();
  await expect.poll(async () => page.evaluate(() => {
    const list = window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.find(b => b.type === 'list');
    return list?.items?.[0]?.level ?? 0;
  })).toBe(1);
  await page.locator('#saveNow').click();
  await expect(page.locator('#saveState')).toHaveText('ذخیره شد', { timeout: 5000 });
  await page.reload();
  await expect(page.locator('#farastWord')).toHaveAttribute('data-editor-ready', '1', { timeout: 5000 });
  const nestedListLevel = await page.evaluate(() => window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.find(b => b.type === 'list')?.items?.[0]?.level ?? 0);
  expect(nestedListLevel).toBe(1);

  await page.locator('.farast-tab[data-tab="insert"]').click();
  const acceptRegressionTableDialogs = dialog => dialog.accept('2');
  page.on('dialog', acceptRegressionTableDialogs);
  await page.locator('.farast-ribbon button[data-command="table"]').click();
  await expect.poll(async () => page.evaluate(() => window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.some(b => b.type === 'table' && b.rows?.length === 2 && b.rows?.[0]?.cells?.length === 2))).toBeTruthy();
  const tableCell = page.locator('.farast-editor table td').first();
  await tableCell.click();
  await page.keyboard.insertText('سلول اول');
  await expect.poll(async () => page.evaluate(() => {
    const table = window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.find(b => b.type === 'table');
    return table?.rows?.[0]?.cells?.[0]?.runs?.map(r => r.text || '').join('') || '';
  })).toContain('سلول اول');
  await page.evaluate(() => window.FarastEditor?.execute?.('addTableRow'));
  await page.evaluate(() => window.FarastEditor?.execute?.('addTableColumn'));
  await expect.poll(async () => page.evaluate(() => {
    const table = window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.find(b => b.type === 'table');
    return { rows: table?.rows?.length || 0, cols: table?.rows?.[0]?.cells?.length || 0 };
  })).toEqual({ rows: 3, cols: 3 });
  await page.evaluate(() => window.FarastEditor?.execute?.('removeTableColumn'));
  await page.evaluate(() => window.FarastEditor?.execute?.('removeTableRow'));
  await expect.poll(async () => page.evaluate(() => {
    const table = window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.find(b => b.type === 'table');
    return { rows: table?.rows?.length || 0, cols: table?.rows?.[0]?.cells?.length || 0 };
  })).toEqual({ rows: 2, cols: 2 });
  await page.locator('#saveNow').click();
  await expect(page.locator('#saveState')).toHaveText('ذخیره شد', { timeout: 5000 });
  await page.reload();
  await expect(page.locator('#farastWord')).toHaveAttribute('data-editor-ready', '1', { timeout: 5000 });
  const reloadedTableState = await page.evaluate(() => {
    const table = window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.find(b => b.type === 'table');
    return { rows: table?.rows?.length || 0, cols: table?.rows?.[0]?.cells?.length || 0, text: table?.rows?.[0]?.cells?.[0]?.runs?.map(r => r.text || '').join('') || '' };
  });
  expect(reloadedTableState).toEqual({ rows: 2, cols: 2, text: 'سلول اول' });

  page.off('dialog', acceptRegressionTableDialogs);


  await page.locator('.farast-editor').first().click();
  await page.keyboard.press('Control+a');
  for (let i = 0; i < 120; i++) {
    await page.keyboard.insertText(paragraph);
    if (i < 119) await page.keyboard.press('Enter');
  }
  await expect.poll(async () => page.locator('#pagesViewport .farast-page').count()).toBeGreaterThan(1);

  await page.locator('#saveNow').click();
  await expect(page.locator('#saveState')).toHaveText('ذخیره شد', { timeout: 5000 });

  await page.locator('.farast-tab[data-tab="insert"]').click();
  await page.locator('.farast-ribbon button[data-command="pageBreak"]').click();
  await expect.poll(async () => page.locator('#pagesViewport .farast-page').count()).toBeGreaterThan(2);
  await expect(page.locator('#totalPages')).toHaveText(/^[۳-۹۰-۹]+$/);

  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
  await page.locator('#source').setInputFiles({ name: 'kernel-test.png', mimeType: 'image/png', buffer: png });
  await expect.poll(async () => page.evaluate(() => window.FarastEditor?.state?.model?.resources?.length || 0)).toBeGreaterThan(0);
  const resource = await page.evaluate(() => window.FarastEditor?.state?.model?.resources?.[0] || null);
  expect(resource?.id).toMatch(/^asset-/);
  expect(resource?.storage_path).toBeTruthy();
  expect(resource?.source || '').not.toMatch(/^data:image\//);


  await page.setViewportSize({ width: 820, height: 900 });
  await expect(page.locator('#farastWord')).toBeVisible();
  await expect(page.locator('#pagesViewport .farast-page').first()).toBeVisible();
  await expect(page.locator('.farast-editor').first()).toHaveAttribute('contenteditable', 'true');
});
