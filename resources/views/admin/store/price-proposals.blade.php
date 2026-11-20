@extends('layouts.admin')
@section('admin-content')
<section class="store-page" dir="rtl">
  <div class="store-container">
    <div class="store-section-head">
      <div>
        <span class="eyebrow">قیمت‌گذاری شفاف</span>
        <h1>پیشنهاد قیمت محصولات</h1>
        <p>قیمت پایه پیشنهادی حداقل مبلغ لازم برای پوشش بهای تمام‌شده و حاشیه سود است؛ اگر قیمت فعلی بالاتر باشد حفظ می‌شود. قیمت کمپین فقط وقتی پیشنهاد می‌شود که حاشیه سود امن وجود داشته باشد.</p>
      </div>
      <a class="store-outline-btn" href="{{ route('admin.store.index') }}">بازگشت به فروشگاه</a>
    </div>

    @if(session('status'))<div class="store-alert success">{{ session('status') }}</div>@endif
    @if($errors->has('pricing'))<div class="store-alert danger">{{ $errors->first('pricing') }}</div>@endif

    <div class="product-pipeline-panel">
      <div class="product-pipeline-heading">
        <div><strong>قاعده پیشنهاد کمپین</strong><small>قیمت کمپین برابر کف مجاز با حفظ حداقل حاشیه سود است؛ این مبلغ تا تأیید بارگذار اعمال نمی‌شود.</small></div>
      </div>
      <p>قیمت مرجع نمایشی فقط پس از پذیرش و برابر قیمت واقعی پیش از تغییر ثبت می‌شود. هیچ قیمت قبلی یا تخفیف ساختگی ساخته نمی‌شود.</p>
    </div>

    <div class="store-table-wrap">
      <table class="store-table">
        <thead>
          <tr>
            <th>محصول</th>
            <th>قیمت فعلی</th>
            <th>قیمت پایه پیشنهادی</th>
            <th>بهای تمام‌شده</th>
            <th>حداقل حاشیه سود</th>
            <th>کف قیمت کمپین</th>
            <th>بارگذار</th>
            <th>اقدام</th>
          </tr>
        </thead>
        <tbody>
          @forelse($products as $product)
            @php($preview = $previews[$product->id] ?? [])
            <tr>
              <td>
                <strong>{{ $product->title }}</strong>
                <small>{{ $product->type === 'course' ? 'دوره آموزشی' : 'محصول دیجیتال' }} · {{ $product->category?->name }}</small>
              </td>
              <td>{{ number_format((int) ($preview['current_price_rials'] ?? $product->price_rials)) }} ریال</td>
              <td><strong>{{ number_format((int) ($preview['base_price_rials'] ?? $product->price_rials)) }} ریال</strong></td>
              <td>{{ isset($preview['cost_price_rials']) ? number_format($preview['cost_price_rials']).' ریال' : 'ثبت نشده' }}</td>
              <td>{{ number_format((int) ($preview['minimum_margin_rials'] ?? 0)) }} ریال</td>
              <td>
                @if(!empty($preview['campaign_price_rials']))
                  <strong>{{ number_format($preview['campaign_price_rials']) }} ریال</strong>
                  <small>کاهش امن: {{ number_format($preview['maximum_discount_rials']) }} ریال</small>
                @else
                  <small>پیشنهاد کمپین امن در دسترس نیست</small>
                @endif
              </td>
              <td>{{ $preview['uploader_name'] ?? (!empty($preview['uploader_id']) ? 'کاربر #'.(int) $preview['uploader_id'] : 'نامشخص') }}</td>
              <td>
                @if(!empty($preview['can_propose']))
                  <form method="POST" action="{{ route('admin.store.price-proposals.create', $product) }}">
                    @csrf
                    <button class="store-primary-btn" type="submit">ارسال برای تأیید بارگذار</button>
                  </form>
                @else
                  <small>قیمت امن در محدوده مجاز قابل محاسبه نیست یا مالک بارگذاری نامشخص است</small>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="8">محصول قیمت‌گذاری‌شده‌ای برای پیشنهاد وجود ندارد.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="store-pagination">{{ $products->links() }}</div>
  </div>
</section>
@endsection
