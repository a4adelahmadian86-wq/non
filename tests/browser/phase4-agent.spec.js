const { test, expect } = require('@playwright/test');

test.afterEach(async ({ page }) => {
  try {
    const csrf=await page.locator('meta[name="csrf-token"]').getAttribute('content');
    if(csrf)await page.evaluate(async token=>{await fetch('/logout',{method:'POST',headers:{'X-CSRF-TOKEN':token},credentials:'same-origin'});},csrf);
  } catch(_){}
});

async function openEditor(page){
 const password=`${process.env.GITHUB_RUN_ID||'local'}-farast-e2e`;
 await page.goto('/login?continue=/projects/new');
 await page.locator('input[name="mobile"]').fill('09151234567');
 await page.getByRole('button',{name:/ادامه با شماره موبایل/}).click();
 await expect(page).toHaveURL(/\/login\/password/);
 await page.locator('input[name="password"]').fill(password);await page.getByRole('button',{name:'ورود به حساب'}).click();
 await expect(page).toHaveURL(/\/projects\/new/);
 await page.locator('#choices .project-card').filter({hasText:'تایپ'}).first().click();await page.getByRole('button',{name:'خودم تایپ می‌کنم'}).click();
 await page.locator('#choices .project-card').first().click();await page.getByRole('button',{name:'ایجاد پروژه'}).click();
 await expect(page.locator('#farastWord')).toHaveAttribute('data-editor-ready','1',{timeout:10000});
}

async function editorSnapshot(page,label){const d=await page.evaluate(()=>({active:document.activeElement?.className||'',dom:document.querySelector('#pagesViewport .farast-editor')?.innerText||'',contenteditable:document.querySelector('#pagesViewport .farast-editor')?.getAttribute('contenteditable')||'',model:window.FarastEditor?.state?.model?.plain_text||'',blockCount:window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.length||0,selection:window.getSelection()?.toString()||''}));console.log(label,JSON.stringify(d));}
test('agent preview, accept and undo use the Editor Kernel',async({page})=>{
 await openEditor(page);const editor=page.locator('.farast-editor').first();await editor.click();await editorSnapshot(page,'BEFORE_TYPE');await page.keyboard.type('این متن عامل سند است');await editorSnapshot(page,'AFTER_TYPE');await expect.poll(async()=>page.evaluate(()=>window.FarastEditor?.state?.model?.plain_text||'')).toContain('این متن عامل سند است');await page.locator('#fdToolbar [data-tool="selectAll"]').click();
 await page.locator('[data-agent-open]').first().click();await page.locator('#farastAgentInput').fill('این متن را پررنگ کن');await page.locator('#farastAgentRun').click();await page.waitForTimeout(500);console.log('AGENT_PLAN_DEBUG',JSON.stringify(await page.evaluate(()=>({plan:window.__farastAgentPlan||null,status:document.getElementById('farastAgentStatus')?.textContent||'',planText:document.getElementById('farastAgentPlan')?.textContent||'',modelText:window.FarastEditor?.state?.model?.plain_text||'',blocks:window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.map(b=>({id:b.id,text:(b.runs||[]).map(r=>r.text||'').join('')}))||[],domText:document.querySelector('#pagesViewport .farast-editor')?.innerText||'',selection:window.FarastEditor?.getSelection?.()||null}))));
 await expect.poll(async()=>page.evaluate(()=>window.__farastAgentPlan?.intent?.operation)).toBe('format_bold');await expect(page.locator('#farastAgentAccept')).toBeEnabled();await page.locator('#farastAgentAccept').click();
 await expect.poll(async()=>page.evaluate(()=>window.__farastAgentPlan?.status)).toBe('executed');await expect.poll(async()=>page.evaluate(()=>window.__farastAgentPlan?.evidence?.command?.name||window.__farastAgentPlan?.evidence?.command)).toContain('FormatText');
 await expect.poll(async()=>page.locator('.farast-editor').first().locator('strong').allTextContents()).toEqual(['این متن عامل سند است']);await page.locator('#farastAgentUndo').click();await expect(editor.locator('strong')).toHaveCount(0);
});

test('high risk agent action requires explicit approval',async({page})=>{
 await openEditor(page);const editor=page.locator('.farast-editor').first();await editor.click();await editorSnapshot(page,'BEFORE_RISK_TYPE');await page.keyboard.type('حذف شود');await editorSnapshot(page,'AFTER_RISK_TYPE');await expect.poll(async()=>page.evaluate(()=>window.FarastEditor?.state?.model?.plain_text||'')).toContain('حذف شود');await page.keyboard.press('Control+a');
 await page.locator('[data-agent-open]').first().click();await page.locator('#farastAgentInput').fill('این متن را حذف کن');await page.locator('#farastAgentRun').click();await page.waitForTimeout(500);console.log('AGENT_RISK_DEBUG',JSON.stringify(await page.evaluate(()=>({plan:window.__farastAgentPlan||null,status:document.getElementById('farastAgentStatus')?.textContent||'',planText:document.getElementById('farastAgentPlan')?.textContent||'',modelText:window.FarastEditor?.state?.model?.plain_text||'',blocks:window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.map(b=>({id:b.id,text:(b.runs||[]).map(r=>r.text||'').join('')}))||[],domText:document.querySelector('#pagesViewport .farast-editor')?.innerText||'',selection:window.FarastEditor?.getSelection?.()||null}))));
 await expect(page.locator('#farastAgentApprove')).toBeVisible({timeout:10000});await expect(page.locator('#farastAgentAccept')).toBeDisabled();
});