@extends('layouts.admin')
@section('admin-content')
<section class="store-page" dir="rtl">
  <div class="store-container" data-product-intake
    data-preflight-url="{{ route('admin.store.intake.files.preflight', $session) }}"
    data-confirm-url="{{ route('admin.store.intake.files.confirm', [$session, '__FILE__']) }}"
    data-cancel-url="{{ route('admin.store.intake.files.cancel', [$session, '__FILE__']) }}"
    data-status-url="{{ route('admin.store.intake.files.status', [$session, '__FILE__']) }}" data-details-url="{{ route('admin.store.intake.details', $session) }}" data-existing-files="{{ e($session->files->map(fn ($file) => ['id' => $file->id, 'name' => $file->original_name, 'relative_path' => $file->relative_path, 'size_bytes' => $file->size_bytes, 'status' => $file->status, 'is_primary' => $file->is_primary, 'supervisor_status' => $file->supervisor_status, 'security_gate' => data_get($file->supervisor_review_json, 'security_gate')])->toJson()) }}">
    <div class="store-section-head">
      <div><span class="eyebrow">مرحله دوم · فایل‌ها</span><h1>انتخاب و بازرسی فایل‌ها</h1>
        <p>نوع انتخاب‌شده: <strong>{{ $session->product_type === 'course' ? 'دوره آموزشی' : 'فایل دیجیتال' }}</strong>
        · مهلت نشست: {{ $session->expires_at?->format('Y/m/d H:i') }}</p>
      </div>
      <a class="store-outline-btn" href="{{ route('admin.store.intake.create') }}">شروع دوباره</a>
    </div>
    <div class="store-panel" style="padding:20px">
      <div id="intake-dropzone" tabindex="0" role="button" aria-label="انتخاب یا رها کردن فایل و پوشه" style="border:2px dashed var(--border-color,#aaa);border-radius:14px;padding:30px;text-align:center">
        <strong>فایل‌ها یا پوشه را اینجا رها کنید</strong>
        <p>یا از انتخاب‌گر فایل و پوشه استفاده کنید. ساختار پوشه تا حد امکان حفظ می‌شود.</p>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
          <label class="store-primary-btn">انتخاب فایل‌ها<input id="intake-file-input" type="file" multiple hidden></label>
          <label class="store-outline-btn">انتخاب پوشه<input id="intake-folder-input" type="file" webkitdirectory directory multiple hidden></label>
        </div>
      </div>
      <div id="intake-progress" role="status" aria-live="polite" style="margin-top:14px"></div>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin:18px 0">
        <strong>تعداد فایل‌ها: <span id="intake-file-count">{{ $session->files->whereIn('status', ['inspected', 'uploaded'])->count() }}</span></strong>
        <strong>حجم کل: <span id="intake-total-size">0</span></strong>
        <strong>تعداد پوشه‌ها: <span id="intake-folder-count">0</span></strong>
      </div>
      <div id="intake-file-list" style="display:grid;gap:12px">
        @foreach($session->files->whereIn('status', ['inspected', 'uploaded']) as $file)
          <article class="store-panel" style="padding:14px">
            <strong>{{ $file->relative_path ?: $file->original_name }}</strong>
            <div>{{ number_format($file->size_bytes) }} بایت · {{ $file->mime }}</div>
            <div>وضعیت: {{ $file->status === 'uploaded' ? 'منتقل‌شده به ذخیره‌سازی' : ($file->status === 'inspected' ? 'بازرسی‌شده؛ منتظر تأیید شما' : $file->status) }}</div>
            <div>ناظر هوشمند: {{ $file->supervisor_status }}</div>
            @if(data_get($file->supervisor_review_json, 'manual_review.approved'))
              <p>تأیید دستی مدیر ثبت شده است؛ انتشار نهایی همچنان در صف تصمیم مدیر قرار می‌گیرد.</p>
            @elseif($file->supervisor_status === 'manual_review_required')
              <p>بررسی خودکار کامل نشده است؛ انتشار این فایل بدون تصمیم دستی مدیر مجاز نیست.</p>
            @endif
            @if($canManageIntakeReview && $file->supervisor_status === 'manual_review_required')
              <form method="post" action="{{ route('admin.store.intake.files.manual-review', [$session, $file]) }}" style="display:grid;gap:8px">
                @csrf
                <label>یادداشت بررسی دستی مدیر (حداقل ۲۰ نویسه)
                  <textarea name="manager_notes" minlength="20" maxlength="2000" required placeholder="فایل را بررسی کنید و دلیل تأیید دستی را ثبت کنید"></textarea>
                </label>
                <button type="submit" class="store-primary-btn">ثبت تأیید دستی فایل</button>
              </form>
            @endif
            @if($file->status === 'inspected')
              <button type="button" class="store-primary-btn" data-intake-confirm-existing="{{ $file->id }}">تأیید گزارش و انتقال به ذخیره‌سازی</button>
            @endif
          </article>
        @endforeach
      </div>
      <div style="margin-top:22px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <a class="store-outline-btn" href="{{ route('admin.store.index') }}">ذخیره و خروج</a>
        <button type="button" class="store-primary-btn" id="intake-continue" disabled>ادامه به مشخصات محصول</button>
        <small id="intake-continue-note">ابتدا فایل‌ها را تأیید کنید و منتظر پایان بررسی ناظر هوشمند بمانید.</small>
      </div>
    </div>
  </div>
</section>
<script src="{{ asset('js/product-intake-session.js') }}" defer></script>
@endsection
