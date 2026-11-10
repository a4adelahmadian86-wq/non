@extends('layouts.admin')

@section('admin-content')
<section class="coupon-console" dir="rtl">
    <header class="coupon-hero">
        <div>
            <span class="coupon-eyebrow">مدیریت فروش و بازگشت مشتری</span>
            <h1>مرکز هوشمند کدهای تخفیف</h1>
            <p>مدیریت کدهای عمومی، پیشنهادهای اختصاصی، اعتبار مصرف و عملکرد واقعی پیشنهادها در یک نقطه.</p>
        </div>
        <a class="coupon-back" href="{{ route('admin.index') }}">بازگشت به مدیریت</a>
    </header>

    @if(session('status'))<div class="coupon-flash" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="coupon-errors" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif

    <div class="coupon-stats">
        <article><span>کل پیشنهادهای اختصاصی</span><strong>{{ number_format($stats['total']) }}</strong><small>ثبت‌شده در موتور بازاریابی</small></article>
        <article><span>استفاده موفق</span><strong>{{ number_format($stats['redeemed']) }}</strong><small>پس از پرداخت قطعی سفارش</small></article>
        <article><span>در انتظار استفاده</span><strong>{{ number_format($stats['offered']) }}</strong><small>هنوز معتبر و قابل استفاده</small></article>
        <article><span>منقضی‌شده</span><strong>{{ number_format($stats['expired']) }}</strong><small>مهلت ۴۸ ساعته پایان یافته</small></article>
        <article><span>نمایش پیشنهادها</span><strong>{{ number_format($stats['offer_views']) }}</strong><small>نمایش یکتای دقیقه‌ای در {{ $stats['measurement_window_days'] }} روز اخیر</small></article>
        <article><span>تعامل با پیشنهاد</span><strong>{{ number_format($stats['offer_clicks']) }}</strong><small>کپی کد اختصاصی · نرخ تعامل {{ number_format($stats['view_to_click_rate'], 1) }}٪</small></article>
        <article><span>نرخ تبدیل پیشنهاد</span><strong>{{ number_format($stats['conversion_rate'], 1) }}٪</strong><small>استفاده موفق نسبت به کل پیشنهادهای صادرشده</small></article>
        <article><span>تبدیل نمایش به خرید</span><strong>{{ number_format($stats['view_to_redemption_rate'], 1) }}٪</strong><small>مصرف موفق نسبت به نمایش‌های ثبت‌شده در همین بازه</small></article>
        <article><span>هزینه تخفیف مصرف‌شده</span><strong>{{ number_format((int) floor($stats['discount_cost_rials'] / 10)) }}</strong><small>تومان · مجموع ارزش کدهای مصرف‌شده</small></article>
        <article><span>فروش منتسب به پیشنهاد</span><strong>{{ number_format((int) floor($stats['attributed_revenue_rials'] / 10)) }}</strong><small>تومان · {{ number_format($stats['attributed_orders']) }} سفارش پرداخت‌شده متصل به کد</small></article>
        <article><span>سود ناخالص قابل محاسبه</span><strong>{{ number_format((int) floor($stats['known_gross_profit_rials'] / 10)) }}</strong><small>تومان · فقط با بهای تمام‌شده ثبت‌شده هنگام خرید</small></article>
        <article><span>اقلام فاقد بهای تاریخی</span><strong>{{ number_format($stats['items_missing_historical_cost']) }}</strong><small>سود این اقلام محاسبه یا تخمین زده نمی‌شود</small></article>
        <article><span>محصولات منتشرشده بدون بهای تمام‌شده فعلی</span><strong>{{ number_format($stats['products_missing_cost']) }}</strong><small>برای این محصولات پیشنهاد خودکار صادر نمی‌شود</small></article>
        <article><span>مصرف بودجه تخفیف در ماه جاری</span><strong>{{ number_format((int) floor($stats['monthly_budget_spent_rials'] / 10)) }}</strong><small>تومان · مصرف واقعی سفارش‌های پرداخت‌شده</small><small>@if($stats['monthly_budget_cap_rials'] > 0) مانده: {{ number_format((int) floor($stats['monthly_budget_remaining_rials'] / 10)) }} تومان از سقف ماهانه {{ number_format((int) floor($stats['monthly_budget_cap_rials'] / 10)) }} تومان @else سقف بودجه نامحدود است @endif</small></article>
    </div>

    @if($canManageGlobalCoupons)
    <section class="coupon-panel">
        <div class="coupon-panel-head"><div><h2>قواعد اجرایی موتور پیشنهاد هوشمند</h2><p>این تنظیمات مستقیماً روی تحلیل سیگنال‌ها، سقف تخفیف، فاصله پیشنهادها و نگهداری داده اثر می‌گذارند.</p></div></div>
        <form method="post" action="{{ route('admin.store-coupons.settings') }}" class="coupon-create-form">
            @csrf @method('PUT')
            <label>حداقل امتیاز لازم<input type="number" name="score_threshold" min="1" max="12" value="{{ old('score_threshold',$settings['score_threshold']) }}" required></label>
            <label>پنجره تحلیل (روز)<input type="number" name="signal_window_days" min="1" max="60" value="{{ old('signal_window_days',$settings['signal_window_days']) }}" required></label>
            <label>فاصله پیشنهادها (روز)<input type="number" name="cooldown_days" min="1" max="90" value="{{ old('cooldown_days',$settings['cooldown_days']) }}" required></label>
            <label>اعتبار پیشنهاد (ساعت)<input type="number" name="offer_valid_hours" min="1" max="168" value="{{ old('offer_valid_hours',$settings['offer_valid_hours']) }}" required></label>
            <label>حداکثر تخفیف خودکار (%)<input type="number" name="max_discount_percent" min="1" max="50" value="{{ old('max_discount_percent',$settings['max_discount_percent']) }}" required></label>
            <label>تشخیص سبد رهاشده پس از (ساعت)<input type="number" name="abandonment_hours" min="1" max="72" value="{{ old('abandonment_hours',$settings['abandonment_hours']) }}" required></label>
            <label>نگهداری سیگنال‌ها (روز)<input type="number" name="retention_days" min="7" max="180" value="{{ old('retention_days',$settings['retention_days']) }}" required></label>
            <label>سقف بودجه تخفیف ماهانه (ریال)<input type="number" name="monthly_budget_rials" min="0" max="999999999999999" value="{{ old('monthly_budget_rials',$settings['monthly_budget_rials']) }}" required><small>صفر = بدون سقف؛ پس از تکمیل بودجه، مصرف همه کدها متوقف می‌شود.</small></label>
            <button type="submit">ذخیره قواعد موتور</button>
        </form>
    </section>
    @endif

    <section class="coupon-panel">
        <div class="coupon-panel-head">
            <div><h2>پیشنهادهای اختصاصی هوشمند</h2><p>هر ردیف به یک مشتری و یک محصول متصل است؛ کد در لحظه پرداخت دوباره اعتبارسنجی می‌شود.</p></div>
            <span class="coupon-chip">First-party · رضایت‌محور</span>
        </div>
        <form method="get" class="coupon-filters">
            <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="جستجوی کد، محصول، نام یا موبایل">
            <select name="status">
                <option value="">همه وضعیت‌ها</option>
                <option value="offered" @selected($filters['status']==='offered')>در انتظار استفاده</option>
                <option value="redeemed" @selected($filters['status']==='redeemed')>مصرف‌شده</option>
                <option value="expired" @selected($filters['status']==='expired')>منقضی‌شده</option>
                <option value="revoked" @selected($filters['status']==='revoked')>باطل‌شده</option>
            </select>
            <button type="submit">اعمال فیلتر</button>
            <a href="{{ route('admin.store-coupons.index') }}">پاک‌کردن</a>
        </form>

        <div class="coupon-table-wrap">
            <table class="coupon-table">
                <thead><tr><th>کد / وضعیت</th><th>مشتری</th><th>محصول هدف</th><th>امتیاز / علت</th><th>تخفیف</th><th>زمان اعتبار</th><th>عملیات</th></tr></thead>
                <tbody>
                @forelse($assignments as $assignment)
                    @php
                        $state = $assignment->status === 'offered' && $assignment->expires_at?->lte(now()) ? 'expired' : $assignment->status;
                        $stateLabel = ['offered'=>'در انتظار استفاده','redeemed'=>'مصرف‌شده','revoked'=>'باطل‌شده','expired'=>'منقضی‌شده'][$state] ?? $state;
                        $reasonLabels = ['repeated_product_views'=>'بازدید مکرر','wishlist_interest'=>'افزودن به علاقه‌مندی','cart_intent'=>'افزودن به سبد','cart_abandoned'=>'سبد رهاشده','coupon_interest'=>'جستجوی تخفیف'];
                    @endphp
                    <tr>
                        <td><strong class="coupon-code">{{ $assignment->coupon?->code ?? '—' }}</strong><span class="coupon-state state-{{ $state }}">{{ $stateLabel }}</span></td>
                        <td><strong>{{ $assignment->user?->name ?? 'حساب حذف‌شده' }}</strong><small>{{ $assignment->user?->mobile ?? '—' }}</small></td>
                        <td>{{ $assignment->product?->title ?? 'محصول حذف‌شده' }}</td>
                        <td><strong>{{ number_format($assignment->score) }} امتیاز</strong><small>{{ collect($assignment->reason_codes ?? [])->map(fn($reason) => $reasonLabels[$reason] ?? $reason)->implode('، ') ?: '—' }}</small></td>
                        <td>{{ number_format((int) ($assignment->coupon?->value ?? 0) / 10) }} تومان</td>
                        <td><small>ارائه: {{ $assignment->offered_at?->format('Y/m/d H:i') }}</small><small>پایان: {{ $assignment->expires_at?->format('Y/m/d H:i') }}</small></td>
                        <td>
                            @if($state === 'offered')
                                <form method="post" action="{{ route('admin.store-coupons.assignments.revoke', $assignment->id) }}" onsubmit="return confirm('این پیشنهاد اختصاصی و کد آن باطل شود؟')">
                                    @csrf
                                    <button class="coupon-danger" type="submit">ابطال پیشنهاد</button>
                                </form>
                            @else
                                <span class="coupon-muted">بدون عملیات</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="coupon-empty">هنوز پیشنهادی با این فیلتر ثبت نشده است.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="coupon-pagination">{{ $assignments->links() }}</div>
    </section>

    @if($canManageGlobalCoupons)
    <section class="coupon-panel">
        <div class="coupon-panel-head"><div><h2>ساخت کد تخفیف عمومی</h2><p>برای کمپین‌ها یا استفاده عمومی؛ کدهای هوشمند اختصاصی از این فرم قابل دستکاری نیستند.</p></div></div>
        <form method="post" action="{{ route('admin.store-coupons.store') }}" class="coupon-create-form">
            @csrf
            <label>کد تخفیف<input name="code" value="{{ old('code') }}" required maxlength="40" pattern="[A-Za-z0-9_-]+" placeholder="مثلاً FARAST15"></label>
            <label>نوع تخفیف<select name="type" required><option value="percent" @selected(old('type')==='percent')>درصدی</option><option value="fixed" @selected(old('type')==='fixed')>مبلغ ثابت (ریال)</option></select></label>
            <label>مقدار تخفیف<input type="number" name="value" min="1" max="999999999999" value="{{ old('value') }}" required></label>
            <label>حداقل مبلغ سبد (ریال)<input type="number" name="min_subtotal_rials" min="0" value="{{ old('min_subtotal_rials') }}"></label>
            <label>حداکثر دفعات مصرف<input type="number" name="max_uses" min="1" value="{{ old('max_uses') }}" placeholder="خالی = نامحدود"></label>
            <label>شروع اعتبار<input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}"></label>
            <label>پایان اعتبار<input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}"></label>
            <label class="coupon-wide">توضیحات<input name="description" maxlength="255" value="{{ old('description') }}" placeholder="توضیح داخلی یا عنوان کمپین"></label>
            <button type="submit">ایجاد کد تخفیف</button>
        </form>
    </section>
    @endif

    @if($canManageGlobalCoupons)
    <section class="coupon-panel">
        <div class="coupon-panel-head"><div><h2>کدهای عمومی اخیر</h2><p>کدهای عمومی با سابقه مصرف و ظرفیت قابل مشاهده هستند.</p></div></div>
        <div class="coupon-table-wrap">
            <table class="coupon-table">
                <thead><tr><th>کد</th><th>نوع و مقدار</th><th>مصرف</th><th>اعتبار</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                <tbody>
                @forelse($genericCoupons as $coupon)
                    <tr>
                        <td><strong class="coupon-code">{{ $coupon->code }}</strong><small>{{ $coupon->description }}</small></td>
                        <td>{{ $coupon->type === 'percent' ? number_format($coupon->value).'٪' : number_format((int) floor($coupon->value / 10)).' تومان' }}</td>
                        <td>{{ number_format($coupon->used_count) }} / {{ $coupon->max_uses === null ? '∞' : number_format($coupon->max_uses) }}</td>
                        <td><small>{{ $coupon->starts_at?->format('Y/m/d H:i') ?? 'بدون شروع' }}</small><small>{{ $coupon->ends_at?->format('Y/m/d H:i') ?? 'بدون پایان' }}</small></td>
                        <td><span class="coupon-state {{ $coupon->is_active ? 'state-offered' : 'state-revoked' }}">{{ $coupon->is_active ? 'فعال' : 'غیرفعال' }}</span></td>
                        <td>
                            <form method="post" action="{{ route('admin.store-coupons.toggle', $coupon->id) }}" onsubmit="return confirm('وضعیت این کد تغییر کند؟')">
                                @csrf @method('PATCH')
                                <button class="coupon-toggle" type="submit">{{ $coupon->is_active ? 'غیرفعال‌سازی' : 'فعال‌سازی' }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="coupon-empty">کد عمومی ثبت نشده است.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
    @else
    <section class="coupon-panel"><p>مدیریت کدهای عمومی و تنظیمات سراسری فقط در دامنه مدیریتی سراسری فعال است؛ پیشنهادهای اختصاصی در محدوده مجاز شما نمایش داده می‌شوند.</p></section>
    @endif
</section>
@endsection

@push('styles')
<style>
.coupon-console{max-width:1500px;margin:auto;padding:24px;color:#17243b}.coupon-hero{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap;margin-bottom:22px}.coupon-eyebrow{font-size:.72rem;font-weight:800;color:#2563eb}.coupon-hero h1{margin:8px 0;font-size:1.55rem}.coupon-hero p,.coupon-panel-head p{margin:0;color:#64748b;font-size:.8rem;line-height:1.9}.coupon-back,.coupon-filters a{color:#315b9a;text-decoration:none;font-size:.78rem;font-weight:700}.coupon-flash,.coupon-errors{padding:12px 15px;border-radius:12px;margin:0 0 15px;font-size:.8rem}.coupon-flash{background:#ecfdf5;color:#047857}.coupon-errors{background:#fff1f2;color:#be123c}.coupon-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:18px}.coupon-stats article,.coupon-panel{background:#fff;border:1px solid #e4eaf2;border-radius:15px}.coupon-stats article{padding:14px;display:grid;gap:7px}.coupon-stats span,.coupon-stats small{font-size:.68rem;color:#64748b}.coupon-stats strong{font-size:1.25rem;color:#14233b}.coupon-panel{padding:18px;margin-bottom:16px}.coupon-panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:15px}.coupon-panel h2{margin:0 0 5px;font-size:1rem}.coupon-chip{padding:6px 10px;border-radius:30px;background:#eff6ff;color:#1d4ed8;font-size:.68rem;font-weight:700}.coupon-filters{display:grid;grid-template-columns:minmax(220px,1fr) 180px auto auto;gap:8px;margin-bottom:14px}.coupon-filters input,.coupon-filters select,.coupon-create-form input,.coupon-create-form select{width:100%;min-width:0;border:1px solid #dbe3ee;border-radius:9px;padding:10px 11px;font:inherit;font-size:.76rem;background:#fff;color:#17243b}.coupon-filters button,.coupon-create-form>button{border:0;border-radius:9px;background:#2456a6;color:#fff;padding:10px 16px;font:inherit;font-size:.76rem;font-weight:800;cursor:pointer}.coupon-table-wrap{overflow:auto}.coupon-table{width:100%;border-collapse:collapse;min-width:900px}.coupon-table th,.coupon-table td{text-align:right;padding:12px 10px;border-bottom:1px solid #edf1f6;font-size:.74rem;vertical-align:middle}.coupon-table th{background:#f8fafc;color:#64748b;font-weight:800}.coupon-table td strong,.coupon-table td small{display:block}.coupon-table td small{font-size:.66rem;color:#64748b;margin-top:4px}.coupon-code{font-family:ui-monospace,monospace;letter-spacing:.04em;color:#1d4ed8}.coupon-state{display:inline-block;margin-top:6px;padding:4px 7px;border-radius:7px;background:#f1f5f9;color:#475569;font-size:.65rem}.state-offered{background:#eff6ff;color:#1d4ed8}.state-redeemed{background:#ecfdf5;color:#047857}.state-expired{background:#fff7ed;color:#c2410c}.state-revoked{background:#fef2f2;color:#b91c1c}.coupon-danger,.coupon-toggle{border:1px solid #dbe3ee;border-radius:8px;padding:7px 9px;background:#fff;color:#475569;font:inherit;font-size:.68rem;cursor:pointer}.coupon-danger{color:#b91c1c;border-color:#fecaca}.coupon-muted,.coupon-empty{color:#94a3b8;font-size:.72rem}.coupon-empty{text-align:center!important;padding:30px!important}.coupon-pagination{margin-top:14px}.coupon-create-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.coupon-create-form label{display:grid;gap:6px;font-size:.72rem;color:#475569;font-weight:700}.coupon-create-form .coupon-wide{grid-column:span 3}.coupon-create-form>button{align-self:end;min-height:40px}@media(max-width:1050px){.coupon-stats{grid-template-columns:repeat(3,minmax(0,1fr))}.coupon-create-form{grid-template-columns:repeat(2,minmax(0,1fr))}.coupon-create-form .coupon-wide{grid-column:span 2}}@media(max-width:600px){.coupon-console{padding:14px}.coupon-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.coupon-filters{grid-template-columns:1fr 1fr}.coupon-filters input{grid-column:1/-1}.coupon-create-form{grid-template-columns:1fr}.coupon-create-form .coupon-wide{grid-column:auto}.coupon-create-form>button{width:100%}.coupon-panel{padding:13px}}
</style>
@endpush
