@extends('layouts.app')
@section('content')
<section class="store-page" dir="rtl">
  <div class="store-container">
    <div class="store-section-head">
      <div>
        <span class="eyebrow">تأیید قیمت محصول</span>
        <h1>پیشنهادهای قیمت من</h1>
        <p>هیچ تغییری تا زمان پذیرش شما روی قیمت محصول اعمال نمی‌شود. با رد پیشنهاد، قیمت فعلی بدون تغییر می‌ماند.</p>
      </div>
      <a class="store-outline-btn" href="{{ route('admin.store.index') }}">بازگشت به فروشگاه</a>
    </div>

    @if(session('status'))<div class="store-alert success">{{ session('status') }}</div>@endif
    @if($errors->has('pricing'))<div class="store-alert danger">{{ $errors->first('pricing') }}</div>@endif

    <div class="store-table-wrap">
      <table class="store-table">
        <thead>
          <tr>
            <th>محصول</th>
            <th>قیمت فعلی هنگام پیشنهاد</th>
            <th>قیمت پایه پیشنهادی</th>
            <th>قیمت کمپین پیشنهادی</th>
            <th>حداقل حاشیه سود</th>
            <th>وضعیت</th>
            <th>تصمیم</th>
          </tr>
        </thead>
        <tbody>
          @forelse($proposals as $proposal)
            <tr>
              <td>
                <strong>{{ $proposal->product?->title ?? 'محصول حذف‌شده' }}</strong>
                <small>{{ $proposal->created_at?->format('Y/m/d H:i') }}</small>
              </td>
              <td>{{ number_format((int) data_get($proposal->snapshot_before, 'price_rials', $proposal->base_price_rials)) }} ریال</td>
              <td><strong>{{ number_format((int) $proposal->base_price_rials) }} ریال</strong></td>
              <td>
                @if($proposal->campaign_price_rials !== null)
                  <strong>{{ number_format((int) $proposal->campaign_price_rials) }} ریال</strong>
                  <small>قیمت کمپین با حفظ کف حاشیه سود</small>
                @else
                  <small>پیشنهاد کمپین امنی وجود ندارد؛ فقط قیمت پایه قابل تأیید است.</small>
                @endif
              </td>
              <td>{{ number_format((int) $proposal->minimum_margin_rials) }} ریال</td>
              <td>
                @php($statusLabels = ['pending' => 'در انتظار تصمیم', 'accepted' => 'پذیرفته‌شده', 'rejected' => 'ردشده', 'stale' => 'منقضی‌شده به علت تغییر قیمت', 'superseded' => 'جایگزین‌شده'])
                <span class="store-status-pill">{{ $statusLabels[$proposal->status] ?? $proposal->status }}</span>
              </td>
              <td>
                @if($proposal->status === 'pending')
                  <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <form method="POST" action="{{ route('store.price-proposals.accept', $proposal) }}">
                      @csrf
                      <button class="store-primary-btn" type="submit">{{ $proposal->campaign_price_rials !== null ? 'پذیرش و اعمال قیمت کمپین' : ((int) $proposal->base_price_rials > (int) data_get($proposal->snapshot_before, 'price_rials', 0) ? 'پذیرش و اعمال قیمت پایه' : 'تأیید قیمت پایه') }}</button>
                    </form>
                    <form method="POST" action="{{ route('store.price-proposals.reject', $proposal) }}">
                      @csrf
                      <button class="store-outline-btn" type="submit">رد پیشنهاد</button>
                    </form>
                  </div>
                @else
                  <small>{{ $proposal->decided_at?->format('Y/m/d H:i') }}</small>
                  @if($proposal->decision_note)<small>{{ $proposal->decision_note }}</small>@endif
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="7">در حال حاضر پیشنهادی برای تأیید ندارید.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="store-pagination">{{ $proposals->links() }}</div>
  </div>
</section>
@endsection
