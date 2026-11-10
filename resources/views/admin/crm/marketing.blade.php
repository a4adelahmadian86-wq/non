@extends('layouts.admin')

@section('admin-content')
<section class="crm-marketing" dir="rtl">
    <header class="crm-marketing__hero">
        <div>
            <span>FARAST · CUSTOMER GROWTH</span>
            <h1>مرکز کمپین‌های بازاریابی</h1>
            <p>سگمنت‌بندی بر اساس رفتار واقعی، ارائه مشوق در چارچوب رضایت و حاشیه سود، و سنجش نتیجه تا خرید قطعی.</p>
        </div>
        <a href="{{ route('admin.index') }}">بازگشت به مرکز عملیات</a>
    </header>
    <div class="crm-marketing__notice" id="campaignNotice" role="status" hidden></div>

    <div class="crm-marketing__grid">
        <article class="crm-marketing__panel">
            <div class="crm-marketing__panel-head">
                <div><span>01 · AUDIENCE</span><h2>ساخت سگمنت رفتاری</h2></div>
                <small>فقط کاربران دارای رضایت فعال</small>
            </div>
            <form id="segmentForm" class="crm-marketing__form">
                <label>نام سگمنت<input name="name" maxlength="140" required placeholder="مثلاً رهاکنندگان سبد محصول"></label>
                <label>سیگنال رفتاری
                    <select name="signal_event">
                        <option value="">بدون فیلتر رفتاری</option>
                        <option value="product_viewed">مشاهده محصول</option>
                        <option value="wishlist_added">افزودن به علاقه‌مندی</option>
                        <option value="cart_added">افزودن به سبد</option>
                        <option value="cart_abandoned">رهاکردن سبد</option>
                        <option value="coupon_attempted">تلاش برای استفاده از کد</option>
                    </select>
                </label>
                <label>محصول هدف (اختیاری)
                    <select name="signal_product_id">
                        <option value="">همه محصولات</option>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}">{{ $product->title }}</option>
                        @endforeach
                    </select>
                </label>
                <label>بازه تحلیل (روز)<input name="signal_window_days" type="number" min="1" max="90" value="14"></label>
                <label class="crm-marketing__wide">توضیح (اختیاری)<input name="description" maxlength="1000" placeholder="هدف و کاربرد این گروه"></label>
                <div class="crm-marketing__actions crm-marketing__wide">
                    <button type="button" class="is-secondary" id="previewAudience">محاسبه مخاطبان</button>
                    <strong id="audiencePreview">هنوز محاسبه نشده</strong>
                    <button type="submit">ذخیره سگمنت</button>
                </div>
            </form>
            <div class="crm-marketing__sublist">
                <h3>سگمنت‌های ذخیره‌شده</h3>
                <div id="segmentsList" class="crm-marketing__empty">در حال دریافت…</div>
            </div>
        </article>

        <article class="crm-marketing__panel">
            <div class="crm-marketing__panel-head">
                <div><span>02 · CAMPAIGN</span><h2>تعریف کمپین</h2></div>
                <small>ارسال فقط با اقدام صریح مدیر</small>
            </div>
            <form id="campaignForm" class="crm-marketing__form">
                <label>نام کمپین<input name="name" maxlength="160" required placeholder="مثلاً بازگشت خریداران بالقوه"></label>
                <label>سگمنت مخاطبان<select name="segment_id" id="campaignSegment" required><option value="">ابتدا سگمنت بسازید</option></select></label>
                <label>عنوان اعلان<input name="title" maxlength="180" required></label>
                <label>محصول مشوق (اختیاری)
                    <select name="incentive_product_id">
                        <option value="">بدون کد تخفیف؛ فقط اعلان</option>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}" @disabled($product->cost_price_rials === null)>{{ $product->title }}{{ $product->cost_price_rials === null ? ' — فاقد بهای تمام‌شده' : '' }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="crm-marketing__wide">متن پیام<textarea name="body" rows="4" minlength="2" maxlength="5000" required placeholder="پیام کمپین را بنویسید…"></textarea></label>
                <div class="crm-marketing__offer-fields crm-marketing__wide">
                    <label>درصد تخفیف<input name="incentive_discount_percent" type="number" min="1" max="50" placeholder="مثلاً ۱۰"></label>
                    <label>اعتبار کد (ساعت)<input name="incentive_valid_hours" type="number" min="1" max="168" value="48"></label>
                    <label>سقف بودجه کمپین (ریال)<input name="budget_cap_rials" type="number" min="1" step="1" placeholder="مثلاً ۵۰۰۰۰۰"></label>
                </div>
                <p class="crm-marketing__hint crm-marketing__wide">برای کمپین دارای مشوق، محصول باید منتشرشده و دارای بهای تمام‌شده باشد. تخفیف نهایی با سیاست حاشیه سود و بودجه ماهانه فراست محدود می‌شود؛ پیشنهادهای فعال نیز از بودجه رزرو می‌گیرند.</p>
                <div class="crm-marketing__actions crm-marketing__wide"><button type="submit">ذخیره پیش‌نویس کمپین</button></div>
            </form>
        </article>
    </div>

    <article class="crm-marketing__panel crm-marketing__campaign-panel">
        <div class="crm-marketing__panel-head">
            <div><span>03 · PERFORMANCE</span><h2>عملکرد و چرخه کمپین‌ها</h2></div>
            <button type="button" class="is-secondary" id="refreshCampaigns">به‌روزرسانی</button>
        </div>
        <div class="crm-marketing__table-wrap">
            <table>
                <thead><tr><th>کمپین</th><th>وضعیت</th><th>مخاطب</th><th>پیشنهاد صادرشده</th><th>نمایش / کلیک</th><th>بازخرید</th><th>فروش منتسب</th><th>هزینه تخفیف</th><th>بودجه رزروشده / مانده</th><th>اقدام</th></tr></thead>
                <tbody id="campaignsList"><tr><td colspan="10" class="crm-marketing__empty">در حال دریافت کمپین‌ها…</td></tr></tbody>
            </table>
        </div>
        <p class="crm-marketing__hint">فروش منتسب فقط از ردیف محصول هدف در سفارش‌های پرداخت‌شده محاسبه می‌شود. نرخ‌ها بر مبنای نمایش و تعامل یکتای هر پیشنهاد هستند؛ اعلان‌های عمومی بدون مشوق، پیشنهاد مالی محسوب نمی‌شوند.</p>
    </article>
</section>
@endsection

@push('styles')
<style>
.crm-marketing{max-width:1500px;margin:0 auto;padding:22px;color:#1b2b43}
.crm-marketing__hero{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:20px;padding:24px;border-radius:20px;background:linear-gradient(120deg,#102747,#1d477a);color:#fff}
.crm-marketing__hero span,.crm-marketing__panel-head span{font-size:10px;letter-spacing:1.4px;font-weight:900;opacity:.72}
.crm-marketing__hero h1{font-size:25px;margin:8px 0}.crm-marketing__hero p{margin:0;color:#d6e5f7;font-size:13px;line-height:1.9}
.crm-marketing__hero>a{white-space:nowrap;padding:10px 14px;border:1px solid #ffffff55;border-radius:10px;color:#fff;text-decoration:none;font-size:12px}
.crm-marketing__grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.crm-marketing__panel{background:#fff;border:1px solid #e5ebf3;border-radius:17px;padding:19px;margin-bottom:16px;box-shadow:0 7px 24px #142b4908;min-width:0}
.crm-marketing__panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:17px}
.crm-marketing__panel-head span{color:#4a78b8;opacity:1}.crm-marketing__panel-head h2{font-size:17px;margin:5px 0 0}.crm-marketing__panel-head small{font-size:10px;color:#7b899d}
.crm-marketing__form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.crm-marketing__form label{display:grid;gap:6px;font-size:11px;font-weight:800;color:#52627a;min-width:0}
.crm-marketing__form input,.crm-marketing__form select,.crm-marketing__form textarea{width:100%;min-width:0;border:1px solid #dce4ef;border-radius:9px;padding:10px;background:#fbfcfe;color:#1b2b43;font:inherit;font-size:12px}
.crm-marketing__form textarea{resize:vertical;line-height:1.8}.crm-marketing__wide{grid-column:1/-1}
.crm-marketing__offer-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.crm-marketing__actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.crm-marketing button{border:0;border-radius:9px;padding:10px 14px;background:#2366b2;color:white;font:inherit;font-size:11px;font-weight:900;cursor:pointer}
.crm-marketing button.is-secondary{background:#edf3fa;color:#345a83;border:1px solid #d9e4f1}
.crm-marketing__actions strong{font-size:11px;color:#2366b2;margin-inline-end:auto}
.crm-marketing__hint{font-size:10px;line-height:1.9;color:#78869a;margin:0}
.crm-marketing__sublist{border-top:1px solid #edf1f6;margin-top:17px;padding-top:14px}.crm-marketing__sublist h3{font-size:12px;margin:0 0 9px}
.crm-marketing__empty{font-size:11px;color:#8491a3;padding:14px;text-align:center}
.crm-marketing__segment-chip{display:inline-flex;align-items:center;gap:8px;padding:6px 9px;margin:3px;border-radius:8px;background:#f1f5fa;color:#405b7b;font-size:10px}
.crm-marketing__table-wrap{overflow:auto}.crm-marketing table{width:100%;border-collapse:collapse;min-width:900px}
.crm-marketing th,.crm-marketing td{padding:11px 9px;border-bottom:1px solid #edf1f6;text-align:right;font-size:10px;white-space:nowrap}
.crm-marketing th{background:#f7f9fc;color:#68788e;font-weight:900}.crm-marketing td strong{display:block;color:#253c5c;font-size:11px}
.crm-marketing td small{display:block;color:#8290a3;margin-top:4px}.crm-marketing .status{display:inline-block;border-radius:20px;padding:4px 8px;background:#eef3f8;color:#52677f}
.crm-marketing .status.sent{background:#e9f7ef;color:#247349}.crm-marketing .status.draft{background:#fff5df;color:#94681d}
.crm-marketing__notice{padding:11px 14px;border-radius:10px;background:#edf6ff;color:#245b91;margin-bottom:14px;font-size:12px}.crm-marketing__notice.is-error{background:#fff0ef;color:#a23b36}
.crm-marketing__send{padding:7px 10px!important}.crm-marketing__send[disabled]{opacity:.45;cursor:not-allowed}
@media(max-width:1050px){.crm-marketing__grid{grid-template-columns:1fr}}
@media(max-width:650px){.crm-marketing{padding:12px}.crm-marketing__hero{display:block;padding:18px}.crm-marketing__hero h1{font-size:20px}.crm-marketing__hero>a{display:inline-block;margin-top:14px}.crm-marketing__panel{padding:13px}.crm-marketing__form{grid-template-columns:1fr}.crm-marketing__wide{grid-column:auto}.crm-marketing__offer-fields{grid-template-columns:1fr}.crm-marketing__panel-head{align-items:flex-start}.crm-marketing__panel-head small{max-width:110px;text-align:left}}
</style>
@endpush

@push('scripts')
<script>
(() => {
    const urls = {
        segments: @json(route('admin.crm.marketing.segments.index')),
        createSegment: @json(route('admin.crm.marketing.segments.store')),
        preview: @json(route('admin.crm.marketing.audience.preview')),
        campaigns: @json(route('admin.crm.marketing.campaigns.index')),
        createCampaign: @json(route('admin.crm.marketing.campaigns.store')),
        sendCampaign: @json(url('/admin/crm/marketing/campaigns')),
    };
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const notice = document.getElementById('campaignNotice');
    const segmentsList = document.getElementById('segmentsList');
    const campaignsList = document.getElementById('campaignsList');
    const segmentSelect = document.getElementById('campaignSegment');
    const number = value => new Intl.NumberFormat('fa-IR').format(Number(value || 0));

    function message(text, error = false) {
        notice.textContent = text;
        notice.hidden = false;
        notice.classList.toggle('is-error', error);
        window.scrollTo({top: 0, behavior: 'smooth'});
    }

    async function request(url, options = {}) {
        const response = await fetch(url, {
            ...options,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                ...(options.headers || {}),
            },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const validation = data.errors ? Object.values(data.errors).flat().join(' ') : '';
            throw new Error(validation || data.message || 'عملیات انجام نشد.');
        }
        return data;
    }

    function currentFilters() {
        const form = document.getElementById('segmentForm');
        const filters = {};
        const event = form.elements.signal_event.value;
        const productId = form.elements.signal_product_id.value;
        if (event) filters.signal_event = event;
        if (productId) {
            if (!event) throw new Error('برای فیلتر محصول، ابتدا نوع سیگنال رفتاری را انتخاب کنید.');
            filters.signal_product_id = Number(productId);
        }
        filters.signal_window_days = Number(form.elements.signal_window_days.value || 14);
        return filters;
    }

    async function loadSegments() {
        const result = await request(urls.segments);
        const items = result.data?.data || [];
        segmentsList.replaceChildren();
        segmentSelect.replaceChildren();
        if (!items.length) {
            segmentsList.textContent = 'هنوز سگمنتی ذخیره نشده است.';
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'ابتدا سگمنت بسازید';
            segmentSelect.append(option);
            return;
        }
        items.forEach(item => {
            const chip = document.createElement('span');
            chip.className = 'crm-marketing__segment-chip';
            chip.textContent = item.name + ' · ' + number(item.audience_count || 0) + ' مخاطب هنگام ساخت';
            segmentsList.append(chip);
            const option = document.createElement('option');
            option.value = item.id;
            option.textContent = item.name;
            segmentSelect.append(option);
        });
    }

    async function loadCampaigns() {
        const result = await request(urls.campaigns);
        const items = result.data?.data || [];
        campaignsList.replaceChildren();
        if (!items.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 10;
            cell.className = 'crm-marketing__empty';
            cell.textContent = 'هنوز کمپینی ثبت نشده است.';
            row.append(cell);
            campaignsList.append(row);
            return;
        }
        items.forEach(item => {
            const row = document.createElement('tr');
            const name = document.createElement('td');
            const title = document.createElement('strong');
            title.textContent = item.name;
            const subtitle = document.createElement('small');
            subtitle.textContent = item.segment_name || 'بدون سگمنت';
            name.append(title, subtitle);
            row.append(name);

            const status = document.createElement('td');
            const badge = document.createElement('span');
            badge.className = 'status ' + (item.status === 'sent' ? 'sent' : item.status === 'draft' ? 'draft' : '');
            badge.textContent = ({draft:'پیش‌نویس',sending:'در حال ارسال',sent:'ارسال‌شده'})[item.status] || item.status;
            status.append(badge);
            row.append(status);

            const metrics = [
                [number(item.recipient_count), ''],
                [number(item.offer_count), ''],
                [number(item.offer_view_count) + ' / ' + number(item.offer_click_count), 'نرخ کلیک ' + number(item.view_to_click_rate) + '٪'],
                [number(item.redeemed_offer_count), 'تبدیل نمایش ' + number(item.view_to_redemption_rate) + '٪'],
                [number(item.attributed_revenue_rials) + ' ریال', ''],
                [number(item.redeemed_discount_rials) + ' ریال', ''],
                [number(item.reserved_discount_rials) + ' ریال', 'مانده ' + (item.budget_remaining_rials === null ? 'بدون سقف' : number(item.budget_remaining_rials) + ' ریال')],
            ];
            metrics.forEach(metric => {
                const cell = document.createElement('td');
                cell.textContent = metric[0];
                if (metric[1]) {
                    const small = document.createElement('small');
                    small.textContent = metric[1];
                    cell.append(small);
                }
                row.append(cell);
            });

            const action = document.createElement('td');
            if (item.status === 'draft') {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'crm-marketing__send';
                button.textContent = 'ارسال کمپین';
                button.addEventListener('click', async () => {
                    if (!window.confirm('کمپین برای مخاطبان دارای رضایت ارسال شود؟ پس از ارسال قابل ارسال مجدد نیست.')) return;
                    button.disabled = true;
                    try {
                        await request(urls.sendCampaign + '/' + item.id + '/send', {method:'POST', body:'{}'});
                        message('کمپین با موفقیت ارسال شد.');
                        await loadCampaigns();
                    } catch (error) {
                        button.disabled = false;
                        message(error.message, true);
                    }
                });
                action.append(button);
            } else {
                action.textContent = '—';
            }
            row.append(action);
            campaignsList.append(row);
        });
    }

    document.getElementById('previewAudience').addEventListener('click', async () => {
        try {
            const result = await request(urls.preview, {method:'POST', body:JSON.stringify(currentFilters())});
            document.getElementById('audiencePreview').textContent = number(result.audience_count) + ' مخاطب واجد شرایط';
        } catch (error) {
            message(error.message, true);
        }
    });

    document.getElementById('segmentForm').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        try {
            const result = await request(urls.createSegment, {
                method:'POST',
                body:JSON.stringify({
                    name:form.elements.name.value,
                    description:form.elements.description.value,
                    filters:currentFilters(),
                }),
            });
            message('سگمنت ذخیره شد؛ ' + number(result.audience_count) + ' مخاطب واجد شرایط هستند.');
            form.reset();
            form.elements.signal_window_days.value = 14;
            document.getElementById('audiencePreview').textContent = 'هنوز محاسبه نشده';
            await loadSegments();
        } catch (error) {
            message(error.message, true);
        }
    });

    document.getElementById('campaignForm').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const productId = form.elements.incentive_product_id.value;
        const body = {
            name:form.elements.name.value,
            segment_id:Number(form.elements.segment_id.value),
            title:form.elements.title.value,
            body:form.elements.body.value,
        };
        if (productId) {
            body.incentive_product_id = Number(productId);
            body.incentive_discount_percent = Number(form.elements.incentive_discount_percent.value);
            body.incentive_valid_hours = Number(form.elements.incentive_valid_hours.value || 48);
            body.budget_cap_rials = Number(form.elements.budget_cap_rials.value);
        }
        try {
            const result = await request(urls.createCampaign, {method:'POST', body:JSON.stringify(body)});
            message('پیش‌نویس کمپین ذخیره شد؛ ' + number(result.audience_count) + ' مخاطب در سگمنت فعلی.');
            form.reset();
            await Promise.all([loadSegments(), loadCampaigns()]);
        } catch (error) {
            message(error.message, true);
        }
    });

    document.getElementById('refreshCampaigns').addEventListener('click', async () => {
        try {
            await Promise.all([loadSegments(), loadCampaigns()]);
            message('اطلاعات کمپین‌ها به‌روز شد.');
        } catch (error) {
            message(error.message, true);
        }
    });

    Promise.all([loadSegments(), loadCampaigns()]).catch(error => message(error.message, true));
})();
</script>
@endpush
