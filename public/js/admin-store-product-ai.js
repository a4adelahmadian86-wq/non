(() => {
  'use strict';

  const form = document.getElementById('productEditorForm');
  const panel = document.querySelector('[data-product-ai-content]');
  if (!form || !panel) return;

  const generateButton = panel.querySelector('[data-ai-content-generate]');
  const statusNode = panel.querySelector('[data-ai-content-status]');
  const modal = document.querySelector('[data-ai-content-modal]');
  const applyButton = modal?.querySelector('[data-ai-content-apply]');
  const closeButtons = modal?.querySelectorAll('[data-ai-content-close]') || [];
  const onlyEmpty = modal?.querySelector('[data-ai-content-only-empty]');
  const tagInputs = form.querySelector('[data-ai-content-tag-inputs]');
  const provenance = {
    fingerprints: form.querySelector('[data-ai-content-provenance]'),
    signature: form.querySelector('[data-ai-content-signature]'),
    request: form.querySelector('[data-ai-content-request]'),
    model: form.querySelector('[data-ai-content-model]'),
    evidence: form.querySelector('[data-ai-content-field-evidence]')
  };
  let generated = null;

  const field = name => modal?.querySelector('[data-ai-content-field="' + name + '"]');
  const accepted = name => !!modal?.querySelector('[data-ai-content-accept="' + name + '"]')?.checked;
  const closeModal = () => { if (modal) modal.hidden = true; };
  const openModal = () => {
    if (!modal) return;
    modal.hidden = false;
    modal.querySelector('textarea,input,button')?.focus();
  };
  const setFormValue = (selector, value) => {
    const input = form.querySelector(selector);
    if (!input || !String(value ?? '').trim()) return false;
    if (onlyEmpty?.checked && String(input.value ?? '').trim() !== '') return false;
    input.value = String(value);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  };
  const renderList = (container, values) => {
    if (!container) return;
    container.replaceChildren();
    (Array.isArray(values) ? values : []).forEach(value => {
      const item = document.createElement('li');
      item.textContent = String(value);
      container.append(item);
    });
  };

  closeButtons.forEach(button => button.addEventListener('click', closeModal));
  modal?.addEventListener('click', event => {
    if (event.target === modal) closeModal();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && modal && !modal.hidden) closeModal();
  });

  generateButton?.addEventListener('click', async () => {
    if (generateButton.disabled) return;
    const original = generateButton.innerHTML;
    generateButton.disabled = true;
    generateButton.setAttribute('aria-busy', 'true');
    generateButton.textContent = 'در حال بررسی دانش و تولید پیشنهاد…';
    if (statusNode) statusNode.textContent = 'گزارش‌های کامل و واقعیت‌های دارای شاهد در حال آماده‌سازی هستند؛ فایل خام به مدل ارسال نمی‌شود.';

    try {
      const csrf = form.querySelector('input[name="_token"]')?.value
        || document.querySelector('meta[name="csrf-token"]')?.content || '';
      const response = await fetch(panel.dataset.generateUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          tone: panel.querySelector('[data-ai-content-tone]')?.value || 'professional',
          focus_keyword: panel.querySelector('[data-ai-content-focus-keyword]')?.value || '',
          instructions: panel.querySelector('[data-ai-content-instructions]')?.value || ''
        })
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.ok) {
        const validationMessage = Object.values(payload.errors || {}).flat()[0];
        throw new Error(validationMessage || payload.message || 'تولید محتوا انجام نشد.');
      }

      generated = payload;
      const suggestions = payload.suggestions || {};
      ['title', 'slug', 'short_description', 'description', 'seo_title', 'seo_description', 'focus_keyword', 'version', 'license_type'].forEach(name => {
        const target = field(name);
        if (target) target.value = suggestions[name] || '';
      });
      const secondary = field('secondary_keywords');
      if (secondary) secondary.value = (suggestions.secondary_keywords || []).join('، ');

      modal?.querySelectorAll('[data-ai-content-accept]').forEach(input => { input.checked = true; });
      const tagsContainer = modal?.querySelector('[data-ai-content-tags]');
      if (tagsContainer) {
        tagsContainer.replaceChildren();
        const uniqueTags = [...new Map((suggestions.tags || []).map(name => [String(name).trim().toLocaleLowerCase('fa-IR'), String(name).trim()])).values()];
        uniqueTags.slice(0, 10).forEach(name => {
          if (!name) return;
          const label = document.createElement('label');
          const checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.checked = true;
          checkbox.dataset.aiTagName = name;
          label.append(checkbox, document.createTextNode(' ' + name));
          tagsContainer.append(label);
        });
      }

      const categorySelect = form.querySelector('[name="category_id"]');
      const categoryOption = [...(categorySelect?.options || [])].find(option => option.value === String(suggestions.category_id || ''));
      const categoryNode = modal?.querySelector('[data-ai-content-category]');
      if (categoryNode) {
        categoryNode.textContent = categoryOption
          ? categoryOption.textContent + (suggestions.category_reason ? ' — ' + suggestions.category_reason : '')
          : (suggestions.category_reason || 'دسته مشخصی پیشنهاد نشد.');
      }

      renderList(modal?.querySelector('[data-ai-content-sources]'), (payload.sources || []).map(source => {
        const details = [source.type, source.pages ? source.pages + ' صفحه' : '', source.facts_total ? source.facts_total + ' شاهد' : ''].filter(Boolean).join(' · ');
        return String(source.name || 'منبع') + (details ? ' — ' + details : '');
      }));
      const fieldLabels = {
        title: 'عنوان', short_description: 'معرفی کوتاه', description: 'توضیحات',
        seo_title: 'عنوان SEO', seo_description: 'توضیح SEO', focus_keyword: 'کلیدواژه کانونی',
        version: 'نسخه', license_type: 'نوع مجوز',
        secondary_keywords: 'کلیدواژه‌های فرعی', tags: 'برچسب‌ها', category: 'دسته‌بندی',
        selling_points: 'نکات فروش'
      };
      const factLookup = new Map();
      const sourceLookup = new Map();
      (payload.sources || []).forEach(source => {
        sourceLookup.set(String(source.source_id || ''), String(source.file_name || source.name || 'منبع'));
        (source.facts || []).forEach(fact => {
          factLookup.set(String(fact.id), { source: String(source.file_name || 'منبع'), fact });
        });
      });
      const usedEvidence = Object.entries(suggestions.field_evidence || {}).flatMap(([fieldName, evidence]) => {
        const references = [];
        (evidence.fact_ids || []).forEach(id => {
          const match = factLookup.get(String(id));
          if (!match) return;
          const fact = match.fact;
          const locator = fact.locator && (fact.locator.page || fact.locator.section)
            ? ' (' + [fact.locator.page ? 'صفحه ' + fact.locator.page : '', fact.locator.section || ''].filter(Boolean).join('، ') + ')'
            : '';
          references.push(match.source + ' — ' + String(fact.key || fact.type || 'واقعیت')
            + ': ' + String(fact.value || '') + '؛ شاهد: ' + String(fact.evidence || '') + locator);
        });
        (evidence.source_ids || []).forEach(id => {
          const name = sourceLookup.get(String(id));
          if (name && !references.some(item => item.startsWith(name + ' —'))) references.push(name);
        });
        return references.length
          ? [String(fieldLabels[fieldName] || fieldName) + ' ← ' + references.join(' | ')]
          : [];
      });
      renderList(modal?.querySelector('[data-ai-content-used-facts]'), usedEvidence.length ? usedEvidence : ['برای بعضی فیلدها شاهد فیلدی معتبر برگردانده نشد؛ آن فیلدها اعمال نمی‌شوند.']);
      renderList(modal?.querySelector('[data-ai-content-lessons]'), (payload.course_lessons || []).map(lesson => {
        const heading = [lesson.section, lesson.title].filter(Boolean).join(' / ');
        const excerpt = lesson.summary || lesson.content || '';
        return heading + (excerpt ? ' — ' + excerpt : '');
      }));
      renderList(modal?.querySelector('[data-ai-content-points]'), suggestions.selling_points || []);
      renderList(modal?.querySelector('[data-ai-content-unknowns]'), suggestions.unknowns || []);
      const evidence = modal?.querySelector('[data-ai-content-evidence]');
      if (evidence) {
        const sourceCount = (payload.sources || []).length;
        const lessonCount = Number(payload.course_lesson_count || 0);
        const factCount = (suggestions.used_fact_ids || []).length;
        evidence.textContent = 'گزارش ' + sourceCount + ' منبع فایل و ' + lessonCount + ' درس بررسی شد؛ '
          + factCount + ' واقعیت دارای شاهد در پیشنهادها ارجاع داده شده است. این پیشنهاد هنوز ذخیره نشده است.';
      }

      openModal();
      if (statusNode) statusNode.textContent = 'پیشنهاد آماده است؛ فیلدهای موردنظر را انتخاب و پیش از اعمال بازبینی کن.';
    } catch (error) {
      if (statusNode) statusNode.textContent = error.message || 'ارتباط با سرویس هوش مصنوعی برقرار نشد.';
    } finally {
      generateButton.disabled = false;
      generateButton.removeAttribute('aria-busy');
      generateButton.innerHTML = original;
    }
  });

  applyButton?.addEventListener('click', () => {
    if (!generated || applyButton.disabled) return;
    const suggestions = generated.suggestions || {};
    applyButton.disabled = true;
    const original = applyButton.textContent;
    applyButton.textContent = 'در حال اعمال فیلدهای انتخاب‌شده…';

    try {
      let appliedCount = 0;
      const fields = {
        title: '[name="title"]',
        slug: '[name="slug"]',
        short_description: '[name="short_description"]',
        description: '[name="description"]',
        seo_title: '[name="seo_title"]',
        seo_description: '[name="seo_description"]',
        focus_keyword: '[name="seo_focus_keyword"]',
        version: '[name="version"]',
        license_type: '[name="license_type"]',
        secondary_keywords: '[name="seo_secondary_keywords"]'
      };
      Object.entries(fields).forEach(([name, selector]) => {
        if (!accepted(name)) return;
        const value = field(name)?.value ?? '';
        if (setFormValue(selector, value)) appliedCount += 1;
      });

      if (accepted('category') && suggestions.category_id) {
        const categorySelect = form.querySelector('[name="category_id"]');
        if (categorySelect && (!onlyEmpty?.checked || !categorySelect.value)) {
          const match = [...categorySelect.options].find(option => option.value === String(suggestions.category_id));
          if (match) {
            categorySelect.value = match.value;
            categorySelect.dispatchEvent(new Event('change', { bubbles: true }));
            appliedCount += 1;
          }
        }
      }

      if (accepted('tags') && tagInputs) {
        tagInputs.replaceChildren();
        const selectedNames = [...(modal?.querySelectorAll('[data-ai-tag-name]:checked') || [])]
          .map(input => String(input.dataset.aiTagName || '').trim())
          .filter(Boolean);
        const uniqueSelectedNames = [...new Set(selectedNames)].slice(0, 10);
        uniqueSelectedNames.forEach(name => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'ai_content_tag_names[]';
          input.value = name;
          tagInputs.append(input);
        });
        appliedCount += uniqueSelectedNames.length ? 1 : 0;
      }

      if (appliedCount === 0) {
        if (statusNode) statusNode.textContent = 'هیچ فیلدی اعمال نشد؛ انتخاب فیلدها یا گزینه «فقط فیلدهای خالی» را بررسی کن.';
        return;
      }

      if (provenance.fingerprints) provenance.fingerprints.value = JSON.stringify(generated.source_fingerprints || {});
      if (provenance.signature) provenance.signature.value = generated.source_signature || '';
      if (provenance.request) provenance.request.value = generated.ai?.request_id || '';
      if (provenance.model) provenance.model.value = generated.ai?.model || '';
      if (provenance.evidence) provenance.evidence.value = JSON.stringify(suggestions.field_evidence || {});

      closeModal();
      if (statusNode) statusNode.textContent = 'فیلدهای انتخاب‌شده در فرم قرار گرفتند؛ برای ذخیره آن‌ها دکمه ذخیره محصول را بزن. برچسب‌های جدید فقط همراه ذخیره محصول ثبت می‌شوند.';
      generated = null;
    } catch (error) {
      if (statusNode) statusNode.textContent = error.message || 'اعمال پیشنهادها کامل نشد.';
    } finally {
      applyButton.disabled = false;
      applyButton.textContent = original;
    }
  });
})();
