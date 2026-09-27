(() => {
  const originalFetch = window.fetch.bind(window);
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  window.fetch = async (...args) => {
    const response = await originalFetch(...args);
    try {
      const url = typeof args[0] === 'string' ? args[0] : args[0]?.url || '';
      if (url.endsWith('/editor/analyze') && response.ok) {
        const data = await response.clone().json();
        const price = document.getElementById('price');
        const sidebar = document.getElementById('aiSidebar');
        if (price) price.textContent = new Intl.NumberFormat('fa-IR').format(Number(data.price || 0)) + ' ریال';
        if (sidebar && data.document_id) {
          let box = document.getElementById('farastCheckoutBox');
          if (!box) { box = document.createElement('div'); box.id='farastCheckoutBox'; box.className='editor-checkout-box'; sidebar.appendChild(box); }
          const free = Number(data.free_pages_preview || 0) > 0;
          box.innerHTML = `<div class="checkout-estimate"><span>برآورد فعلی</span><strong>${new Intl.NumberFormat('fa-IR').format(Number(data.price || 0))} ریال</strong></div><p>${free ? 'یک صفحه از اعتبار رایگان شما برای این سفارش قابل اعمال است.' : 'این مبلغ برآورد اولیه است و مبلغ نهایی هنگام درخواست خروجی دوباره محاسبه می‌شود.'}</p><form method="post" action="/documents/${encodeURIComponent(data.document_id)}/checkout"><input type="hidden" name="_token" value="${csrf}"><label><input type="checkbox" name="accept_terms" value="1" required> متن را کامل بازبینی کرده‌ام و قوانین پرداخت را می‌پذیرم.</label><button type="submit"><i class="fa-solid fa-credit-card"></i> درخواست خروجی و ادامه پرداخت</button></form></div>`;
        }
      }
    } catch (_) {}
    return response;
  };
})();
