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

  const aiPanel = document.querySelector('[data-product-ai-content]');
  if (aiPanel) {
    const generateButton = aiPanel.querySelector('[data-ai-content-generate]');
    const statusNode = aiPanel.querySelector('[data-ai-content-status]');
    const modal = document.querySelector('[data-ai-content-modal]');
    const applyButton = modal?.querySelector('[data-ai-content-apply]');
    const closeButtons = modal?.querySelectorAll('[data-ai-content-close]') || [];
    const onlyEmpty = modal?.querySelector('[data-ai-content-only-empty]');
    const provenance = {
      fingerprints: form.querySelector('[data-ai-content-provenance]'),
      signature: form.querySelector('[data-ai-content-signature]'),
      request: form.querySelector('[data-ai-content-request]'),
      model: form.querySelector('[data-ai-content-model]')
    };
    let generated = null;

    const normalized = value => String(value || '').trim().toLocaleLowerCase('fa-IR').replace(/\s+/g, ' ');
    const field = name => modal?.querySelector('[data-ai-content-field="' + name + '"]');
    const renderList = (container, values, className) => {
      if (!container) return;
      container.replaceChildren();
      (Array.isArray(values) ? values : []).forEach(value => {
        const item = document.createElement('li');
        item.textContent = String(value);
        if (className) item.className = className;
        container.append(item);
      });
    };
    const openModal = () => { if (modal) { modal.hidden = false; modal.querySelector('textarea,input,button')?.focus(); } };
    const closeModal = () => { if (modal) modal.hidden = true; };
    closeButtons.forEach(button => button.addEventListener('click', closeModal));
    modal?.addEventListener('click', event => { if (event.target === modal) closeModal(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && modal && !modal.hidden) closeModal(); });

    generateButton?.addEventListener('click', async () => {
      if (generateButton.disabled) return;
      const original = generateButton.innerHTML;
      generateButton.disabled = true;
      generateButton.textContent = 'در حال بررسی دانش و تولید پیشنهاد…';
      if (statusNode) statusNode.textContent = 'گزارش‌های کامل و واقعیت‌های دارای شاهد در حال آماده‌سازی هستند؛ فایل خام ارسال نمی‌شود.';
      try {
        const csrf = form.querySelector('input[name="_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch(aiPanel.dataset.generateUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
          body: JSON.stringify({
            tone: aiPanel.querySelector('[data-ai-content-tone]')?.value || 'professional',
            focus_keyword: aiPanel.querySelector('[data-ai-content-focus-keyword]')?.value || '',
            instructions: aiPanel.querySelector('[data-ai-content-instructions]')?.value || ''
          })
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
          const validationMessage = Object.values(payload.errors || {}).flat()[0];
          throw new Error(validationMessage || payload.message || 'تولید محتوا انجام نشد.');
        }
        generated = payload;
        const suggestions = payload.suggestions || {};
        ['title','short_description','description','seo_title','seo_description','focus_keyword'].forEach(name => {
          const target = field(name);
          if (target) target.value = suggestions[name] || '';
        });
        const secondary = field('secondary_keywords');
        if (secondary) secondary.value = (suggestions.secondary_keywords || []).join('، ');
        const tagsContainer = modal?.querySelector('[data-ai-content-tags]');
        if (tagsContainer) {
          tagsContainer.replaceChildren();
          (suggestions.tags || []).forEach(name => {
            const label = document.createElement('label');
            label.className = 'product-ai-content-tag';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = true;
            checkbox.dataset.aiTagName = String(name);
            label.append(checkbox, document.createTextNode(' ' + String(name)));
            tagsContainer.append(label);
          });
        }
        const categorySelect = form.querySelector('[name="category_id"]');
        const categoryOption = [...(categorySelect?.options || [])].find(option => option.value === String(suggestions.category_id || ''));
        const categoryNode = modal?.querySelector('[data-ai-content-category]');
        if (categoryNode) categoryNode.textContent = categoryOption ? categoryOption.textContent + (suggestions.category_reason ? ' — ' + suggestions.category_reason : '') : (suggestions.category_reason || 'دسته مشخصی پیشنهاد نشد.');
        renderList(modal?.querySelector('[data-ai-content-points]'), suggestions.selling_points || []);
        renderList(modal?.querySelector('[data-ai-content-unknowns]'), suggestions.unknowns || []);
        const sourceCount = (payload.sources || []).length + (payload.course_lesson_count ? 1 : 0);
        const factCount = (suggestions.used_fact_ids || []).length;
        const evidence = modal?.querySelector('[data-ai-content-evidence]');
        if (evidence) evidence.textContent = 'گزارش کامل ' + sourceCount + ' منبع دانش بررسی شد؛ ' + factCount + ' واقعیت دارای شاهد در پیشنهادها استفاده شده است. این پیشنهاد هنوز ذخیره نشده است.';
        openModal();
        if (statusNode) statusNode.textContent = 'پیشنهاد آماده است؛ قبل از اعمال، تمام فیلدها را بازبینی کن.';
      } catch (error) {
        if (statusNode) statusNode.textContent = error.message || 'ارتباط با سرویس هوش مصنوعی برقرار نشد.';
      } finally {
        generateButton.disabled = false;
        generateButton.innerHTML = original;
      }
    });

    const createOrFindTag = async name => {
      const select = form.querySelector('[name="tag_ids[]"]');
      if (!select) return null;
      const existing = [...select.options].find(option => normalized(option.textContent) === normalized(name));
      if (existing) return existing;
      const data = new FormData();
      data.append('name', name);
      const csrf = form.querySelector('input[name="_token"]')?.value || '';
      const response = await fetch(aiPanel.dataset.tagUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf},
        body: data
      });
      const payload = await response.json();
      if (!response.ok || !payload.id) throw new Error(payload.message || 'ساخت برچسب «' + name + '» انجام نشد.');
      const option = new Option(payload.name, payload.id, true, true);
      select.add(option);
      return option;
    };

    applyButton?.addEventListener('click', async () => {
      if (!generated || applyButton.disabled) return;
      const suggestions = generated.suggestions || {};
      const preserveExisting = !!onlyEmpty?.checked;
      applyButton.disabled = true;
      const original = applyButton.textContent;
      applyButton.textContent = 'در حال اعمال پیشنهادها…';
      try {
        const fields = {
          title: form.querySelector('[name="title"]'),
          short_description: form.querySelector('[name="short_description"]'),
          description: form.querySelector('[name="description"]'),
          seo_title: form.querySelector('[name="seo_title"]'),
          seo_description: form.querySelector('[name="seo_description"]'),
          seo_focus_keyword: form.querySelector('[name="seo_focus_keyword"]'),
          seo_secondary_keywords: form.querySelector('[name="seo_secondary_keywords"]')
        };
        const values = {
          title: suggestions.title,
          short_description: suggestions.short_description,
          description: suggestions.description,
          seo_title: suggestions.seo_title,
          seo_description: suggestions.seo_description,
          seo_focus_keyword: suggestions.focus_keyword,
          seo_secondary_keywords: (suggestions.secondary_keywords || []).join('، ')
        };
        Object.entries(fields).forEach(([name, input]) => {
          if (!input || (preserveExisting && String(input.value || '').trim() !== '')) return;
          input.value = values[name] || '';
          input.dispatchEvent(new Event('input', {bubbles:true}));
          input.dispatchEvent(new Event('change', {bubbles:true}));
        });

        const categorySelect = form.querySelector('[name="category_id"]');
        if (categorySelect && suggestions.category_id && (!preserveExisting || !categorySelect.value)) {
          const match = [...categorySelect.options].find(option => option.value === String(suggestions.category_id));
          if (match) { categorySelect.value = match.value; categorySelect.dispatchEvent(new Event('change', {bubbles:true})); }
        }

        const tagSelect = form.querySelector('[name="tag_ids[]"]');
        const selectedTagNames = [...(modal?.querySelectorAll('[data-ai-tag-name]:checked') || [])].map(input => input.dataset.aiTagName);
        if (tagSelect && selectedTagNames.length && (!preserveExisting || ![...tagSelect.options].some(option => option.selected))) {
          if (!preserveExisting) [...tagSelect.options].forEach(option => { option.selected = false; });
          for (const name of selectedTagNames) {
            try {
              const option = await createOrFindTag(name);
              if (option) option.selected = true;
            } catch (error) {
              if (statusNode) statusNode.textContent = error.message || 'برخی برچسب‌ها ثبت نشدند.';
            }
          }
          tagSelect.dispatchEvent(new Event('change', {bubbles:true}));
        }

        if (provenance.fingerprints) provenance.fingerprints.value = JSON.stringify(generated.source_fingerprints || {});
        if (provenance.signature) provenance.signature.value = generated.source_signature || '';
        if (provenance.request) provenance.request.value = generated.ai?.request_id || '';
        if (provenance.model) provenance.model.value = generated.ai?.model || '';
        closeModal();
        if (statusNode) statusNode.textContent = 'پیشنهادها در فرم اعمال شدند؛ برای ذخیره نهایی، دکمه ذخیره محصول را بزن.';
        generated = null;
      } catch (error) {
        if (statusNode) statusNode.textContent = error.message || 'اعمال پیشنهادها کامل نشد.';
      } finally {
        applyButton.disabled = false;
        applyButton.textContent = original;
      }
    });
  }
})();
