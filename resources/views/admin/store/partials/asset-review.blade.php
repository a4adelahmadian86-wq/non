@if(is_array($assetReview ?? null))
  <div class="product-asset-review">
    <b>{{ $label ?? 'گزارش ناظر فایل' }}</b>
    <p>{{ $assetReview['summary'] ?? 'تحلیل ساختاری انجام شد.' }}</p>
    <small>اسکن اصلی: {{ data_get($assetReview, 'inspection.upstream_scan_status') === 'clean' ? 'تأیید شد' : 'نامشخص' }} · اسکن ثانویه: {{ data_get($assetReview, 'inspection.antivirus_status') === 'clean' ? 'فعال و سالم' : 'در این سرویس فعال نیست' }} · نتیجه: {{ $assetReview['security_gate'] ?? 'نامشخص' }} · {{ $assetReview['reviewed_at'] ?? '' }}</small>
    @if(!empty($assetReview['findings']) && is_array($assetReview['findings']))
      <ul class="product-asset-review-list">
        @foreach(array_slice($assetReview['findings'], 0, 4) as $finding)
          <li>
            <strong>{{ $finding['title'] ?? 'نکته قابل بررسی' }}</strong>
            <span>{{ $finding['detail'] ?? '' }}</span>
            @if(!empty($finding['recommendation']))<small>پیشنهاد: {{ $finding['recommendation'] }}</small>@endif
          </li>
        @endforeach
      </ul>
    @endif
    @if(!empty($assetReview['warnings']) && is_array($assetReview['warnings']))
      <ul class="product-asset-review-warnings">
        @foreach(array_slice($assetReview['warnings'], 0, 4) as $warning)
          <li>{{ $warning }}</li>
        @endforeach
      </ul>
    @endif
  </div>
@endif
