@if(isset($workflow))
  <section class="store-workflow-card" aria-label="وضعیت تکمیل محصول">
    <div class="store-workflow-head">
      <div><span>وضعیت آمادگی انتشار</span><strong>{{ $workflow['can_publish'] ? 'آماده انتشار' : 'در حال تکمیل' }}</strong></div>
      <div class="store-workflow-score"><b>{{ $workflow['percentage'] }}٪</b><small>{{ $workflow['completed_count'] }} از {{ $workflow['required_count'] }} مرحله ضروری</small></div>
    </div>
    <div class="store-workflow-track"><span style="width:{{ $workflow['percentage'] }}%"></span></div>
    <div class="store-workflow-checks">
      @foreach($workflow['checks'] as $check)
        <div class="store-workflow-check {{ $check['done'] ? 'is-done' : (($check['optional'] ?? false) ? 'is-optional' : 'is-pending') }}">
          <i class="fa-solid {{ $check['done'] ? 'fa-circle-check' : (($check['optional'] ?? false) ? 'fa-circle-minus' : 'fa-circle-exclamation') }}"></i>
          <span>{{ $check['label'] }}</span>
          @if($check['optional'] ?? false)<small>پیشنهادی</small>@endif
        </div>
      @endforeach
    </div>
    @unless($workflow['can_publish'])
      <p class="store-workflow-note"><i class="fa-solid fa-lightbulb"></i> مراحل ضروری را تکمیل کنید؛ وضعیت انتشار در سرور نیز دوباره کنترل می‌شود.</p>
    @endunless
  </section>
@endif