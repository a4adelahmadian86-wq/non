(() => {
    const page = document.querySelector('.farast-blog');
    if (!page) return;

    const savedKey = 'farast.blog.saved';
    const readSaved = () => {
        try {
            const value = JSON.parse(localStorage.getItem(savedKey) || '[]');
            return Array.isArray(value) ? value : [];
        } catch (_) { return []; }
    };
    const writeSaved = value => {
        try { localStorage.setItem(savedKey, JSON.stringify(value.slice(-50))); } catch (_) {}
    };
    const syncSaveButtons = () => {
        const saved = readSaved();
        page.querySelectorAll('[data-blog-save]').forEach(button => {
            const active = saved.includes(button.dataset.blogSave);
            button.setAttribute('aria-pressed', String(active));
            const icon = button.querySelector('i');
            if (icon) icon.className = active ? 'fa-solid fa-bookmark' : 'fa-regular fa-bookmark';
            const label = button.querySelector('span');
            if (label) label.textContent = active ? 'ذخیره شد' : 'ذخیره برای بعد';
        });
        const count = page.querySelector('[data-saved-count]');
        if (count) count.textContent = new Intl.NumberFormat('fa-IR').format(saved.length);
    };
    syncSaveButtons();

    page.querySelectorAll('[data-blog-save]').forEach(button => {
        button.addEventListener('click', () => {
            const saved = readSaved();
            const slug = button.dataset.blogSave;
            writeSaved(saved.includes(slug) ? saved.filter(item => item !== slug) : [...saved, slug]);
            syncSaveButtons();
            syncSavedFilter();
        });
    });

    const savedFilter = page.querySelector('[data-blog-filter-saved]');
    const savedEmpty = page.querySelector('[data-blog-empty-saved]');
    let onlySaved = false;
    const syncSavedFilter = () => {
        const saved = readSaved();
        page.querySelectorAll('[data-blog-card]').forEach(card => {
            card.hidden = onlySaved && !saved.includes(card.dataset.blogCard);
        });
        if (savedEmpty) savedEmpty.hidden = !onlySaved || page.querySelectorAll('[data-blog-card]:not([hidden])').length > 0;
        if (savedFilter) {
            savedFilter.setAttribute('aria-pressed', String(onlySaved));
            savedFilter.classList.toggle('is-active', onlySaved);
        }
    };
    savedFilter?.addEventListener('click', () => {
        onlySaved = !onlySaved;
        syncSavedFilter();
    });

    if (!page.classList.contains('blog-article-page')) return;

    const progress = document.querySelector('[data-reading-progress] span');
    const article = page.querySelector('[data-article-body]');
    const updateProgress = () => {
        if (!progress || !article) return;
        const rect = article.getBoundingClientRect();
        const total = article.offsetHeight - window.innerHeight * .45;
        const passed = Math.max(0, Math.min(total, -rect.top + window.innerHeight * .35));
        progress.style.width = (total > 0 ? passed / total * 100 : 0) + '%';
    };
    updateProgress();
    window.addEventListener('scroll', updateProgress, { passive: true });
    window.addEventListener('resize', updateProgress);

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(window.location.href);
            return true;
        } catch (_) {
            const input = document.createElement('textarea');
            input.value = window.location.href;
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.select();
            const ok = document.execCommand('copy');
            input.remove();
            return ok;
        }
    };
    page.querySelector('[data-blog-copy]')?.addEventListener('click', async event => {
        const button = event.currentTarget;
        const ok = await copyLink();
        button.querySelector('span').textContent = ok ? 'لینک کپی شد' : 'کپی نشد';
        window.setTimeout(() => button.querySelector('span').textContent = 'کپی لینک', 1800);
    });
    page.querySelector('[data-blog-share]')?.addEventListener('click', async () => {
        if (navigator.share) {
            try { await navigator.share({ title: document.title, url: window.location.href }); } catch (_) {}
        } else {
            await copyLink();
        }
    });
    page.querySelector('[data-reading-mode]')?.addEventListener('click', event => {
        document.body.classList.toggle('blog-reading-mode');
        const active = document.body.classList.contains('blog-reading-mode');
        event.currentTarget.setAttribute('aria-pressed', String(active));
        event.currentTarget.innerHTML = active
            ? '<i class="fa-solid fa-compress"></i> خروج از حالت مطالعه'
            : '<i class="fa-solid fa-book-open"></i> حالت مطالعه';
    });
})();
