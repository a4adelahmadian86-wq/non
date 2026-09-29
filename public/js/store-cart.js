(() => {
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  const fmt = (n) => new Intl.NumberFormat('fa-IR').format(Math.max(0, Number(n) || 0));

  async function api(url, body) {
    const opts = {
      method: body === undefined ? 'GET' : 'POST',
      headers: {
        'X-CSRF-TOKEN': csrf(),
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    };
    if (body !== undefined) opts.body = JSON.stringify(body);
    const r = await fetch(url, opts);
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.message || 'خطا در سبد خرید');
    return j;
  }

  function render(payload) {
    const countEl = document.getElementById('farastCartCount');
    const body = document.getElementById('farastCartBody');
    const total = document.getElementById('farastCartTotal');
    const checkout = document.getElementById('farastCartCheckout');
    const count = payload.count || 0;
    if (countEl) countEl.textContent = String(count);
    if (total) total.textContent = fmt(payload.total_toman ?? Math.floor((payload.total_rials || 0) / 10)) + ' تومان';
    if (checkout) {
      checkout.disabled = count === 0;
      checkout.onclick = () => { location.href = '/cart'; };
    }
    if (!body) return;
    if (!payload.items || !payload.items.length) {
      body.innerHTML = '<div style="padding:24px;text-align:center;color:#6b7c93;font-size:.85rem">سبد خالی است.<br><a href="/store" style="color:#1769ff">برو به فروشگاه</a></div>';
      return;
    }
    body.innerHTML = payload.items.map((it) => `
      <div class="farast-cart-item" data-id="${it.product_id}" style="display:flex;justify-content:space-between;gap:10px;padding:12px 0;border-bottom:1px solid #edf1f5;font-size:.8rem">
        <div>
          <a href="/store/product/${it.slug}" style="color:#1a2b45;font-weight:700;text-decoration:none">${it.title}</a>
          <div style="color:#6b7c93;margin-top:4px">${fmt(it.unit_price_toman)} تومان × ${it.quantity}</div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px">
          <strong>${fmt(it.line_total_toman)} تومان</strong>
          <button type="button" data-remove="${it.product_id}" style="border:0;background:#fdecea;color:#c62828;border-radius:8px;padding:4px 8px;cursor:pointer;font-size:.7rem">حذف</button>
        </div>
      </div>`).join('') +
      `<div style="padding:14px 0 0;display:flex;gap:8px">
        <a href="/cart" style="flex:1;text-align:center;background:#1769ff;color:#fff;text-decoration:none;padding:10px;border-radius:10px;font-weight:800;font-size:.78rem">تکمیل خرید</a>
      </div>`;
    body.querySelectorAll('[data-remove]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          const j = await api('/cart/products/' + btn.dataset.remove + '/remove', {});
          render(j);
        } catch (e) { alert(e.message); }
      });
    });
  }

  async function refresh() {
    try {
      const j = await api('/cart/summary');
      render(j);
      return j;
    } catch (_) {
      return null;
    }
  }

  function openDrawer() {
    const d = document.getElementById('farastCartDrawer');
    const b = document.getElementById('farastCartBackdrop');
    if (d) { d.setAttribute('aria-hidden', 'false'); d.classList.add('is-open'); }
    if (b) b.hidden = false;
    refresh();
  }
  function closeDrawer() {
    const d = document.getElementById('farastCartDrawer');
    const b = document.getElementById('farastCartBackdrop');
    if (d) { d.setAttribute('aria-hidden', 'true'); d.classList.remove('is-open'); }
    if (b) b.hidden = true;
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('farastCartButton')?.addEventListener('click', openDrawer);
    document.querySelector('[data-cart-close]')?.addEventListener('click', closeDrawer);
    document.getElementById('farastCartBackdrop')?.addEventListener('click', closeDrawer);
    refresh();
  });

  window.FarastCart = { refresh, open: openDrawer, close: closeDrawer, api, render };
})();
