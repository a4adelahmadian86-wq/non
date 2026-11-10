(() => {
  const form = document.getElementById('productEditorForm');
  if (!form || form.dataset.wizardReady === '1') return;
  const identityHeading = form.querySelector('#product-identity');
  const pricingHeading = form.querySelector('#product-pricing');
  const contentHeading = form.querySelector('#product-content');
  const discoveryHeading = form.querySelector('#product-discovery');
  const supervisorHeading = form.querySelector('#product-ai-supervisor');
  const grid = pricingHeading?.closest('.product-editor-grid');
  if (!identityHeading || !pricingHeading || !contentHeading || !discoveryHeading || !supervisorHeading || !grid) return;

  const makePanel = (title, index) => {
    const panel = document.createElement('section');
    panel.className = 'product-wizard-panel';
    panel.dataset.wizardPanel = String(index);
    panel.setAttribute('aria-label', title);
    return panel;
  };
  const identityGrid = document.createElement('div');
  identityGrid.className = grid.className;
  const pricingGrid = document.createElement('div');
  pricingGrid.className = grid.className;
  let pricingStarted = false;
  Array.from(grid.childNodes).forEach(node => {
    if (node === pricingHeading) pricingStarted = true;
    (pricingStarted ? pricingGrid : identityGrid).appendChild(node);
  });
  const identityPanel = makePanel('مشخصات پایه', 0);
  identityPanel.append(identityHeading, identityGrid);
  const pricingPanel = makePanel('قیمت‌گذاری', 1);
  pricingPanel.append(pricingGrid);
  form.insertBefore(identityPanel, contentHeading);
  form.insertBefore(pricingPanel, contentHeading);
  grid.remove();

  const wrapRange = (start, end, title, index) => {
    const panel = makePanel(title, index);
    let node = start;
    while (node && node !== end) {
      const next = node.nextSibling;
      panel.appendChild(node);
      node = next;
    }
    form.insertBefore(panel, end || null);
    return panel;
  };
  const contentPanel = wrapRange(contentHeading, discoveryHeading, 'محتوا و معرفی', 2);
  const discoveryPanel = wrapRange(discoveryHeading, supervisorHeading, 'نمایش و دسته‌بندی', 3);
  const reviewPanel = wrapRange(supervisorHeading, null, 'بازبینی و ذخیره', 4);
  const panels = [identityPanel, pricingPanel, contentPanel, discoveryPanel, reviewPanel];
  const nav = document.querySelector('.product-workflow');
  const hrefs = ['#product-identity', '#product-pricing', '#product-content', '#product-discovery', '#product-ai-supervisor'];
  const links = Array.from(nav?.querySelectorAll('a') || []).filter(link => hrefs.includes(link.getAttribute('href')));
  const titles = ['مشخصات', 'قیمت‌گذاری', 'محتوا', 'نمایش', 'بازبینی'];
  let current = 0;

  const show = index => {
    current = Math.max(0, Math.min(index, panels.length - 1));
    panels.forEach((panel, i) => { panel.hidden = i !== current; });
    links.forEach(link => {
      if (link.getAttribute('href') === hrefs[current]) link.setAttribute('aria-current', 'step');
      else link.removeAttribute('aria-current');
    });
    const label = form.querySelector('[data-wizard-current-step]');
    if (label) label.textContent = 'گام ' + (current + 1) + ' از ' + panels.length + ' · ' + titles[current];
    window.scrollTo({ top: Math.max(0, form.getBoundingClientRect().top + window.scrollY - 24), behavior: 'smooth' });
  };
  const validatePanel = panel => {
    const fields = Array.from(panel.querySelectorAll('input,select,textarea')).filter(field => !field.disabled && field.type !== 'hidden');
    const invalid = fields.find(field => !field.checkValidity());
    if (invalid) { invalid.reportValidity(); invalid.focus({ preventScroll: false }); return false; }
    return true;
  };
  panels.forEach((panel, index) => {
    const actions = document.createElement('div');
    actions.className = 'product-wizard-actions';
    const back = document.createElement('button');
    back.type = 'button'; back.className = 'product-wizard-back'; back.textContent = 'مرحله قبل';
    back.disabled = index === 0; back.addEventListener('click', () => show(current - 1));
    const next = document.createElement('button');
    next.type = 'button'; next.className = 'product-wizard-next';
    next.textContent = index === panels.length - 1 ? 'آماده ذخیره' : 'گام بعدی';
    next.addEventListener('click', () => { if (validatePanel(panel) && current < panels.length - 1) show(current + 1); else if (current === panels.length - 1) document.getElementById('productSaveButton')?.focus(); });
    actions.append(back, next); panel.appendChild(actions);
  });
  links.forEach(link => link.addEventListener('click', event => {
    event.preventDefault(); const index = hrefs.indexOf(link.getAttribute('href')); if (index >= 0) show(index);
  }));
  const progress = document.createElement('div');
  progress.className = 'product-wizard-progress';
  progress.innerHTML = '<strong data-wizard-current-step></strong><span>هر گام را جداگانه تکمیل کنید؛ امکان بازگشت به مراحل قبلی وجود دارد.</span>';
  form.insertBefore(progress, identityPanel);
  form.noValidate = true; form.dataset.wizardReady = '1';
  form.addEventListener('submit', event => {
    const fields = Array.from(form.querySelectorAll('input,select,textarea')).filter(field => !field.disabled && field.type !== 'hidden');
    const invalid = fields.find(field => !field.checkValidity());
    if (invalid) {
      event.preventDefault(); const index = panels.indexOf(invalid.closest('[data-wizard-panel]'));
      if (index >= 0) show(index); invalid.reportValidity(); invalid.focus({ preventScroll: false });
    }
  });
  show(0);
})();
