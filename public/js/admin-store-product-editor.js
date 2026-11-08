(() => {
  const form = document.getElementById('productEditorForm');
  if (!form) return;
  const typeSelect = form.querySelector('[data-product-type]');
  const deliveryPolicyField = form.querySelector('[data-download-policy-field]');
  const deliveryPolicySelect = form.querySelector('[data-download-policy]');
  const syncDeliveryPolicy = () => {
    if (!deliveryPolicyField || !deliveryPolicySelect) return;
    const isDigitalFile = typeSelect?.value === 'digital_file';
    deliveryPolicyField.hidden = !isDigitalFile;
    deliveryPolicySelect.disabled = !isDigitalFile;
  };
  typeSelect?.addEventListener('change', syncDeliveryPolicy);
  syncDeliveryPolicy();

  const price = form.querySelector('[name="price_rials"]');
  const pricePreview = form.querySelector('[data-price-preview]');
  const updatePrice = () => {
    if (!pricePreview || !price) return;
    const raw = Number(price.value || 0);
    const toman = Math.floor(raw / 10);
    const formatter = new Intl.NumberFormat('fa-IR');
    let label = 'معادل ' + formatter.format(toman) + ' تومان';
    const compare = Number(form.querySelector('[name="compare_at_price_rials"]')?.value || 0);
    if (compare > raw && raw >= 0) {
      const saving = Math.floor((compare - raw) / 10);
      const percent = Math.round(((compare - raw) / compare) * 100);
      label += ' · ' + formatter.format(percent) + '٪ تخفیف · ' + formatter.format(saving) + ' تومان صرفه‌جویی';
    }
    pricePreview.textContent = label;
  };
  price?.addEventListener('input', updatePrice);
  form.querySelector('[name="compare_at_price_rials"]')?.addEventListener('input', updatePrice);
  updatePrice();

  document.querySelectorAll('input[type="file"]').forEach(input => {
    input.addEventListener('change', () => {
      const output = input.closest('form')?.querySelector('[data-file-name]');
      if (!output) return;
      const file = input.files?.[0];
      output.textContent = file
        ? file.name + ' · ' + new Intl.NumberFormat('fa-IR').format(Math.ceil(file.size / 1024)) + ' کیلوبایت'
        : 'فایلی انتخاب نشده است.';
    });
  });

  const bindPreview = (selector, outputSelector, fallback) => {
    const input = form.querySelector(selector);
    const output = document.querySelector(outputSelector);
    if (!input || !output) return;
    const render = () => { output.textContent = input.value.trim() || fallback; };
    input.addEventListener('input', render);
    render();
  };
  const titleInput = form.querySelector('[data-product-title]');
  const seoTitleInput = form.querySelector('[data-seo-title]');
  const seoTitleOutput = document.querySelector('[data-seo-preview-title]');
  bindPreview('[data-seo-title]', '[data-seo-preview-title]', titleInput?.value || 'عنوان محصول شما');
  if (titleInput && seoTitleInput && seoTitleOutput) {
    titleInput.addEventListener('input', () => {
      if (!seoTitleInput.value.trim()) seoTitleOutput.textContent = titleInput.value.trim() || 'عنوان محصول شما';
    });
  }
  const shortDescriptionInput = form.querySelector('[data-product-short-description]');
  bindPreview('[data-seo-description]', '[data-seo-preview-description]', shortDescriptionInput?.value || 'توضیحات محصول شما در این قسمت نمایش داده می‌شود.');
  const slugInput = form.querySelector('[data-product-slug]');
  const slugOutput = document.querySelector('[data-seo-preview-url]');
  if (slugInput && slugOutput) {
    const renderSlug = () => { slugOutput.textContent = window.location.origin + '/store/product/' + (slugInput.value.trim() || 'product-url'); };
    slugInput.addEventListener('input', renderSlug);
    renderSlug();
  }
  const shortDescription = form.querySelector('[data-product-short-description]');
  const seoDescription = form.querySelector('[data-seo-description]');
  if (shortDescription && seoDescription) shortDescription.addEventListener('input', () => {
    if (!seoDescription.value.trim()) { const output = document.querySelector('[data-seo-preview-description]'); if (output) output.textContent = shortDescription.value.trim() || 'توضیحات محصول شما در این قسمت نمایش داده می‌شود.'; }
  });
  document.querySelectorAll('[data-inline-create]').forEach(inlineForm => {
    inlineForm.addEventListener('submit', async event => {
      event.preventDefault();
      const button = inlineForm.querySelector('button[type="submit"]');
      const feedback = inlineForm.closest('.product-category-panel')?.querySelector('[data-inline-feedback]');
      if (button?.disabled) return;
      if (button) { button.disabled = true; button.dataset.originalText = button.textContent; button.textContent = 'در حال ثبت…'; }
      if (feedback) feedback.textContent = '';
      try {
        const response = await fetch(inlineForm.action, {method:'POST',body:new FormData(inlineForm),headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message || Object.values(payload.errors || {}).flat()[0] || 'ثبت مورد جدید انجام نشد.');
        const kind = inlineForm.dataset.inlineCreate;
        const select = kind === 'category' ? form.querySelector('[name="category_id"]') : form.querySelector('[name="tag_ids[]"]');
        if (select) { const option = new Option(payload.name,payload.id,true,true); select.add(option); option.selected = true; }
        if (feedback) feedback.textContent = kind === 'category' ? 'دسته ساخته و برای همین محصول انتخاب شد.' : 'برچسب ساخته و به همین محصول اختصاص یافت.';
        inlineForm.reset();
      } catch (error) { if (feedback) feedback.textContent = error.message || 'خطا در ارتباط با سرور.'; }
      finally { if (button) { button.disabled = false; button.textContent = button.dataset.originalText || 'ثبت'; } }
    });
  });
  const progressRoot = document.querySelector('[data-product-progress]');
  const progressLabel = progressRoot?.querySelector('[data-product-progress-label]');
  const progressBar = progressRoot?.querySelector('[data-product-progress-bar]');
  const progressTrack = progressRoot?.querySelector('[role="progressbar"]');
  const requiredFields = Array.from(form.querySelectorAll('[required]'))
    .filter(field => !field.disabled && field.type !== 'hidden');
  const updateFormProgress = () => {
    if (!progressRoot || !progressLabel || !progressBar || !progressTrack) return;
    const total = requiredFields.length;
    const complete = requiredFields.filter(field => {
      if (field.type === 'checkbox') return field.checked;
      return String(field.value ?? '').trim() !== '' && field.checkValidity();
    }).length;
    const percentage = total ? Math.round((complete / total) * 100) : 100;
    progressBar.style.width = percentage + '%';
    progressTrack.setAttribute('aria-valuenow', String(percentage));
    const formatter = new Intl.NumberFormat('fa-IR');
    progressLabel.textContent = formatter.format(complete) + ' از ' + formatter.format(total) +
      ' فیلد ضروری · ' + formatter.format(percentage) + '٪';
  };
  form.addEventListener('input', updateFormProgress);
  form.addEventListener('change', updateFormProgress);
  updateFormProgress();

  const workflow = document.querySelector('.product-workflow');
  const stepLinks = Array.from(workflow?.querySelectorAll('[data-product-step-link]') || []);
  const stepTargets = stepLinks
    .map(link => document.querySelector(link.getAttribute('href')))
    .filter(Boolean);
  const setActiveStep = target => {
    stepLinks.forEach(link => {
      const active = link.getAttribute('href') === '#' + target.id;
      link.classList.toggle('is-active', active);
      if (active) link.setAttribute('aria-current', 'step');
      else link.removeAttribute('aria-current');
    });
  };
  if (stepTargets.length) {
    setActiveStep(stepTargets[0]);
    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver(entries => {
        const visible = entries
          .filter(entry => entry.isIntersecting)
          .sort((a, b) => Math.abs(a.boundingClientRect.top - 135) - Math.abs(b.boundingClientRect.top - 135));
        if (visible[0]) setActiveStep(visible[0].target);
      }, {rootMargin: '-125px 0px -62% 0px', threshold: [0, 0.1, 0.25]});
      stepTargets.forEach(target => observer.observe(target));
    }
    stepLinks.forEach(link => link.addEventListener('click', () => {
      const target = document.querySelector(link.getAttribute('href'));
      if (target) setActiveStep(target);
    }));
  }

  form.addEventListener('submit', event => {
    const button = document.getElementById('productSaveButton');
    if (button?.dataset.submitting === '1') {
      event.preventDefault();
      return;
    }
    if (!form.reportValidity()) return;
    if (button) {
      button.dataset.submitting = '1';
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      button.textContent = 'در حال ذخیره اطلاعات…';
    }
  });
})();
