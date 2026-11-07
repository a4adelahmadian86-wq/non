(() => {
    const root = document.querySelector('.blog-article-page');
    if (!root) return;

    const progress = document.querySelector('[data-reading-progress] span');
    const article = root.querySelector('[data-article-body]');
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

    const saveButton = root.querySelector('[data-blog-save]');
    if (saveButton) {
        const key = 'farast.blog.saved';
        const slug = saveButton.dataset.blogSave;
        const readSaved = () => {
            try { return JSON.parse(localStorage.getItem(key) || '[]'); } catch (_) { return []; }
        };
        const sync = () => {
            const saved = readSaved().includes(slug);
            saveButton.setAttribute('aria-pressed', String(saved));
            saveButton.querySelector('i').className = saved ? 'fa-solid fa-bookmark' : 'fa-regular fa-bookmark';
            saveButton.querySelector('span').textContent = saved ? 'ذخیره شد' : 'ذخیره برای بعد';
        };
        sync();
        saveButton.addEventListener('click', () => {
            const saved = readSaved();
            const next = saved.includes(slug) ? saved.filter(item => item !== slug) : [...saved, slug].slice(-50);
            try { localStorage.setItem(key, JSON.stringify(next)); } catch (_) {}
            sync();
        });
    }

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
    root.querySelector('[data-blog-copy]')?.addEventListener('click', async event => {
        const button = event.currentTarget;
        const ok = await copyLink();
        button.querySelector('span').textContent = ok ? 'لینک کپی شد' : 'کپی نشد';
        window.setTimeout(() => button.querySelector('span').textContent = 'کپی لینک', 1800);
    });
    root.querySelector('[data-blog-share]')?.addEventListener('click', async () => {
        if (navigator.share) {
            try { await navigator.share({ title: document.title, url: window.location.href }); } catch (_) {}
        } else {
            await copyLink();
        }
    });
    root.querySelector('[data-reading-mode]')?.addEventListener('click', event => {
        document.body.classList.toggle('blog-reading-mode');
        const active = document.body.classList.contains('blog-reading-mode');
        event.currentTarget.setAttribute('aria-pressed', String(active));
        event.currentTarget.innerHTML = active
            ? '<i class="fa-solid fa-compress"></i> خروج از حالت مطالعه'
            : '<i class="fa-solid fa-book-open"></i> حالت مطالعه';
    });
})();
