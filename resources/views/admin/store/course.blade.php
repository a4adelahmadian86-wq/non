@extends('layouts.admin')
@section('admin-content')
<section class="store-page" dir="rtl">
  <div class="store-container course-admin">
    <div class="store-section-head">
      <div><span class="eyebrow">مرکز مدیریت دوره</span><h1>ساختار و محتوای دوره</h1><p>{{ $product->title }} · مدیریت حرفه‌ای فصل‌ها، جلسات و منابع</p></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><a class="store-outline-btn" href="{{ route('admin.store.edit',$product) }}">اطلاعات محصول</a><a class="store-outline-btn" href="{{ route('admin.store.index') }}">همه محصولات</a></div>
    </div>
    @if(session('status'))<div class="store-alert success" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="store-alert error" role="alert">{{ $errors->first() }}</div>@endif

    <form method="post" action="{{ route('admin.store.course.update',$product) }}" class="store-panel course-settings">
      @csrf @method('PUT')
      <div class="course-panel-title"><div><span>تنظیمات اصلی</span><h2>مشخصات آموزشی</h2></div><span class="course-pill">گام ۱</span></div>
      <div class="course-fields">
        <label>زیرعنوان دوره<input name="subtitle" maxlength="220" value="{{ old('subtitle',$course->subtitle) }}" placeholder="مثلاً از مقدماتی تا اجرای پروژه واقعی"></label>
        <label>نام مدرس<input name="instructor_name" maxlength="160" value="{{ old('instructor_name',$course->instructor_name) }}" placeholder="نام مدرس یا تیم آموزشی"></label>
        <label>سطح دوره<select name="level">@foreach(['beginner'=>'مقدماتی','intermediate'=>'متوسط','advanced'=>'پیشرفته','all'=>'همه سطوح'] as $v=>$l)<option value="{{ $v }}" @selected(old('level',$course->level)===$v)>{{ $l }}</option>@endforeach</select></label>
        <label>زبان<select name="language"><option value="fa" @selected(old('language',$course->language)==='fa')>فارسی</option><option value="en" @selected(old('language',$course->language)==='en')>انگلیسی</option><option value="ar" @selected(old('language',$course->language)==='ar')>عربی</option></select></label>
        <label>مدت کل (دقیقه)<input type="number" min="0" name="duration_minutes" value="{{ old('duration_minutes',$course->duration_minutes) }}" placeholder="مثلاً ۴۸۰"></label>
        <label>مدت اعتبار دسترسی (روز)<input type="number" min="1" name="access_duration_days" value="{{ old('access_duration_days',$course->access_duration_days) }}" placeholder="خالی = بدون انقضا"></label>
        <label>حداقل تکمیل برای گواهی (%)<input type="number" min="1" max="100" name="certificate_min_completion_percent" value="{{ old('certificate_min_completion_percent',$course->certificate_min_completion_percent) }}"></label>
      </div>
      <div class="course-toggles">
        <label><input type="checkbox" name="certificate_enabled" value="1" @checked(old('certificate_enabled',$course->certificate_enabled))> صدور گواهی پایان دوره</label>
        <label><input type="checkbox" name="drip_enabled" value="1" @checked(old('drip_enabled',$course->drip_enabled))> انتشار تدریجی جلسات بر اساس زمان ثبت‌نام</label>
      </div>
      <button class="store-primary-btn" type="submit">ذخیره تنظیمات دوره</button>
    </form>

    <div class="course-curriculum-head"><div><span class="eyebrow">CURRICULUM BUILDER</span><h2>برنامه آموزشی</h2><p>هر فصل می‌تواند چندین جلسه و هر جلسه منابع مستقل داشته باشد.</p></div><div class="course-counts"><b>{{ $course->sections->count() }}</b><span>فصل</span><b>{{ $course->sections->sum(fn($s)=>$s->lessons->count()) }}</b><span>جلسه</span></div></div>

    <form method="post" action="{{ route('admin.store.course.sections.store',$product) }}" class="store-panel course-add-section">
      @csrf
      <strong>افزودن فصل جدید</strong>
      <div class="course-inline-fields"><input name="title" required maxlength="180" placeholder="عنوان فصل؛ مثلاً مبانی و شروع کار"><input name="description" maxlength="2000" placeholder="توضیح کوتاه فصل (اختیاری)"><input type="number" min="0" name="sort_order" placeholder="ترتیب" value="{{ $course->sections->count() }}"></div>
      <button type="submit" class="store-primary-btn">افزودن فصل</button>
    </form>

    @forelse($course->sections as $section)
      <article class="store-panel course-section-card">
        <div class="course-section-top">
          <div class="course-section-number">{{ $loop->iteration }}</div>
          <div class="course-section-meta"><h3>{{ $section->title }}</h3><span>{{ $section->lessons->count() }} جلسه · ترتیب {{ $section->sort_order }}</span></div>
          <form method="post" action="{{ route('admin.store.course.sections.delete',[$product,$section]) }}" onsubmit="return confirm('این فصل و تمام جلسات و منابع آن حذف شوند؟')">@csrf @method('DELETE')<button class="course-danger-link" type="submit">حذف فصل</button></form>
        </div>
        <form method="post" action="{{ route('admin.store.course.sections.update',[$product,$section]) }}" class="course-section-edit">
          @csrf @method('PUT')
          <input name="title" value="{{ $section->title }}" required maxlength="180" aria-label="عنوان فصل">
          <input name="description" value="{{ $section->description }}" maxlength="2000" placeholder="توضیح فصل" aria-label="توضیح فصل">
          <input type="number" name="sort_order" value="{{ $section->sort_order }}" min="0" aria-label="ترتیب فصل">
          <button type="submit" class="store-outline-btn">ذخیره فصل</button>
        </form>

        <div class="course-lessons">
          @foreach($section->lessons as $lesson)
            <details class="course-lesson" @if($errors->any()) open @endif>
              <summary><span class="course-lesson-icon"><i class="fa-solid fa-{{ $lesson->lesson_type==='video'?'play':($lesson->lesson_type==='article'?'file-lines':'paperclip') }}"></i></span><span class="course-lesson-title">{{ $lesson->title }}</span><span class="course-lesson-meta">{{ $lesson->lesson_type }} · {{ $lesson->is_published?'منتشر':'پیش‌نویس' }} @if($lesson->is_preview) · نمونه رایگان @endif</span><i class="fa-solid fa-chevron-down"></i></summary>
              <div class="course-lesson-body">
                <form method="post" action="{{ route('admin.store.course.lessons.update',[$product,$lesson]) }}" class="course-lesson-form">
                  @csrf @method('PUT')
                  <label>عنوان جلسه<input name="title" required maxlength="180" value="{{ $lesson->title }}"></label>
                  <label>نوع محتوا<select name="lesson_type">@foreach(['video'=>'ویدئو','audio'=>'صوت','article'=>'متن آموزشی','file'=>'فایل','live'=>'جلسه زنده'] as $v=>$l)<option value="{{ $v }}" @selected($lesson->lesson_type===$v)>{{ $l }}</option>@endforeach</select></label>
                  <label>لینک ویدئو / صوت<input type="url" name="video_url" maxlength="2048" value="{{ $lesson->video_url }}" placeholder="https://..."></label>
                  <label>مدت (ثانیه)<input type="number" name="duration_seconds" min="0" value="{{ $lesson->duration_seconds }}"></label>
                  <label>انتشار پس از (روز)<input type="number" name="drip_after_days" min="0" value="{{ $lesson->drip_after_days }}" placeholder="اختیاری"></label>
                  <label>ترتیب جلسه<input type="number" name="sort_order" min="0" value="{{ $lesson->sort_order }}"></label>
                  <label class="course-wide">خلاصه جلسه<textarea name="summary" rows="2" maxlength="2000">{{ $lesson->summary }}</textarea></label>
                  <label class="course-wide">محتوای متنی / توضیحات<textarea name="content" rows="4" maxlength="50000">{{ $lesson->content }}</textarea></label>
                  <div class="course-toggles course-wide"><label><input type="checkbox" name="is_preview" value="1" @checked($lesson->is_preview)> قابل مشاهده به‌عنوان جلسه نمونه</label><label><input type="checkbox" name="is_published" value="1" @checked($lesson->is_published)> جلسه منتشر شود</label></div>
                  <button class="store-primary-btn" type="submit">ذخیره جلسه</button>
                </form>
                <div class="course-resources"><strong>منابع این جلسه</strong>
                  @forelse($lesson->resources as $resource)
                    @php($fileReview = data_get($product->metadata, 'ai_supervisor.asset_reviews.file:'.$resource->id))
                    <div class="course-resource-row">
                      <span><i class="fa-solid fa-paperclip"></i> {{ $resource->original_name }} <small>{{ number_format($resource->size_bytes/1024,1) }} KB · {{ $resource->scan_status==='clean'?'اسکن سالم':'وضعیت بررسی نامشخص' }}</small></span>
                      <div class="course-resource-actions">
                        <form method="post" action="{{ route('admin.store.ai-supervisor.files.review',[$product,$resource]) }}">@csrf<button type="submit" class="store-outline-btn" @disabled(!data_get($product->metadata,'ai_supervisor.enabled',false) || !config('services.product_supervisor.url') || !config('services.product_supervisor.token') || $resource->scan_status !== 'clean' || $resource->size_bytes > 25 * 1024 * 1024)>بررسی هوشمند</button></form>
                        <form method="post" action="{{ route('admin.store.course.resources.delete',[$product,$lesson,$resource]) }}" onsubmit="return confirm('منبع حذف شود؟')">@csrf @method('DELETE')<button type="submit" class="course-danger-link">حذف</button></form>
                      </div>
                    </div>
                    @if($fileReview && hash_equals((string) ($fileReview['sha256'] ?? ''), (string) $resource->sha256))
                      <details class="course-resource-review">
                        <summary>گزارش ناظر · {{ $fileReview['security_gate'] ?? 'وضعیت نامشخص' }}</summary>
                        <p>{{ $fileReview['summary'] ?? 'گزارش ساختاری ثبت شد.' }}</p>
                        @foreach(($fileReview['findings'] ?? []) as $finding)
                          <div><strong>{{ $finding['title'] ?? 'یافته ناظر' }}</strong><p>{{ $finding['detail'] ?? '' }}</p>@if(!empty($finding['recommendation']))<small>پیشنهاد: {{ $finding['recommendation'] }}</small>@endif</div>
                        @endforeach
                        @foreach(($fileReview['warnings'] ?? []) as $warning)
                          <p class="course-resource-warning">هشدار: {{ $warning }}</p>
                        @endforeach
                      </details>
                    @endif
                  @empty <p>هنوز منبعی برای این جلسه ثبت نشده است.</p> @endforelse
                  <form method="post" action="{{ route('admin.store.course.resources.upload',[$product,$lesson]) }}" enctype="multipart/form-data" class="course-resource-upload">@csrf<input type="file" name="file" required accept=".pdf,.zip,.jpg,.jpeg,.png,.webp,.mp4,.webm,.mp3,.m4a,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt"><button type="submit" class="store-outline-btn">بارگذاری منبع امن</button></form>
                  <small>حداکثر ۲۵۶ مگابایت؛ فایل پس از اسکن ضدبدافزار در فضای خصوصی ثبت می‌شود.</small>
                </div>
                <form method="post" action="{{ route('admin.store.course.lessons.delete',[$product,$lesson]) }}" onsubmit="return confirm('جلسه و تمام منابع آن حذف شوند؟')">@csrf @method('DELETE')<button type="submit" class="course-danger-link">حذف جلسه</button></form>
              </div>
            </details>
          @endforeach
        </div>

        <form method="post" action="{{ route('admin.store.course.lessons.store',[$product,$section]) }}" class="course-add-lesson">
          @csrf<strong>افزودن جلسه به این فصل</strong>
          <div class="course-inline-fields"><input name="title" required maxlength="180" placeholder="عنوان جلسه"><select name="lesson_type"><option value="video">ویدئو</option><option value="audio">صوت</option><option value="article">متن آموزشی</option><option value="file">فایل</option><option value="live">جلسه زنده</option></select><input type="url" name="video_url" placeholder="لینک ویدئو (اختیاری)"><input type="number" min="0" name="duration_seconds" placeholder="مدت (ثانیه)" value="0"></div>
          <div class="course-lesson-form">
            <label>خلاصه جلسه<input name="summary" maxlength="2000" placeholder="توضیح کوتاه برای دانشجو"></label>
            <label>انتشار پس از (روز)<input type="number" name="drip_after_days" min="0" placeholder="خالی = بلافاصله"></label>
            <label>ترتیب جلسه<input type="number" name="sort_order" min="0" value="0"></label>
            <label class="course-wide">محتوای متنی اولیه<textarea name="content" rows="3" maxlength="50000" placeholder="متن آموزشی یا توضیحات جلسه (اختیاری)"></textarea></label>
          </div>
          <div class="course-toggles"><label><input type="checkbox" name="is_preview" value="1"> جلسه نمونه رایگان</label><label><input type="checkbox" name="is_published" value="1"> انتشار جلسه</label></div>
          <button type="submit" class="store-outline-btn">افزودن جلسه</button>
        </form>
      </article>
    @empty
      <div class="store-panel course-empty"><i class="fa-solid fa-list-check"></i><h3>هنوز فصلی ساخته نشده است</h3><p>برای شروع، اولین فصل دوره را اضافه کنید؛ سپس جلسات و منابع هر فصل را بسازید.</p></div>
    @endforelse
  </div>
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20261107course1">
<style>
.course-admin{max-width:1120px}.course-admin .store-section-head p,.course-curriculum-head p{color:var(--st-muted);font-size:.8rem;margin:5px 0 0}.course-admin .store-panel{padding:22px}.course-panel-title,.course-curriculum-head,.course-section-top{display:flex;align-items:center;justify-content:space-between;gap:14px}.course-panel-title span:first-child{color:var(--st-blue);font-size:.7rem;font-weight:800}.course-panel-title h2,.course-curriculum-head h2{margin:3px 0;font-size:1.1rem}.course-pill{background:#edf4ff;color:var(--st-blue);border-radius:99px;padding:6px 12px;font-size:.72rem;font-weight:800}.course-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:18px 0}.course-fields label,.course-lesson-form label{display:grid;gap:7px;font-size:.78rem;font-weight:700;color:#40536d}.course-fields input,.course-fields select,.course-inline-fields input,.course-inline-fields select,.course-section-edit input,.course-lesson-form input,.course-lesson-form select,.course-lesson-form textarea{width:100%;min-width:0;padding:11px 12px;border:1px solid var(--st-line);border-radius:10px;background:#fff;color:var(--st-ink);font:inherit;font-size:.8rem;box-sizing:border-box}.course-toggles{display:flex;gap:18px;flex-wrap:wrap;margin:12px 0 18px}.course-toggles label{display:flex;align-items:center;gap:8px;font-size:.78rem;color:#4c607a}.course-curriculum-head{margin:28px 0 14px}.course-counts{display:flex;align-items:center;gap:6px;color:var(--st-muted);font-size:.72rem}.course-counts b{background:#edf4ff;color:var(--st-blue);padding:5px 9px;border-radius:8px;margin-right:8px}.course-add-section,.course-add-lesson{display:grid;gap:12px}.course-add-section>strong,.course-add-lesson>strong{font-size:.84rem}.course-inline-fields{display:grid;grid-template-columns:2fr 2fr .7fr;gap:9px}.course-add-lesson .course-inline-fields{grid-template-columns:1.5fr 1fr 2fr 1fr}.course-section-card{margin-top:14px}.course-section-number{width:38px;height:38px;border-radius:12px;background:#edf4ff;color:var(--st-blue);display:grid;place-items:center;font-weight:900}.course-section-meta{flex:1}.course-section-meta h3{margin:0;font-size:.92rem}.course-section-meta span{font-size:.7rem;color:var(--st-muted)}.course-danger-link{border:0;background:none;color:#bf3434;font:inherit;font-size:.75rem;cursor:pointer}.course-section-edit{display:grid;grid-template-columns:1.3fr 2fr .6fr auto;gap:8px;margin:16px 0}.course-lessons{display:grid;gap:8px}.course-lesson{border:1px solid var(--st-line);border-radius:12px;overflow:hidden}.course-lesson summary{display:flex;align-items:center;gap:10px;padding:12px;cursor:pointer;list-style:none}.course-lesson summary::-webkit-details-marker{display:none}.course-lesson-icon{width:30px;height:30px;border-radius:9px;background:#f1f5fa;display:grid;place-items:center;color:#526a88}.course-lesson-title{font-size:.78rem;font-weight:800;flex:1}.course-lesson-meta{font-size:.68rem;color:var(--st-muted)}.course-lesson-body{padding:14px;border-top:1px solid var(--st-line);display:grid;gap:14px}.course-lesson-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.course-wide{grid-column:1/-1}.course-resources{background:#f8fafd;border:1px solid var(--st-line);border-radius:12px;padding:14px;display:grid;gap:9px}.course-resources>strong{font-size:.8rem}.course-resource-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--st-line);font-size:.74rem}.course-resource-row small{display:block;color:var(--st-muted);font-size:.66rem}.course-resource-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.course-resource-actions form{margin:0}.course-resource-review{padding:10px;border:1px solid var(--st-line);border-radius:10px;background:#fff}.course-resource-review summary{cursor:pointer;font-size:.72rem;font-weight:800}.course-resource-review>p,.course-resource-review>div{font-size:.7rem;line-height:1.8;margin:8px 0}.course-resource-review small,.course-resource-warning{color:var(--st-muted);font-size:.66rem}.course-resource-upload{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.course-resources>p,.course-resources>small{color:var(--st-muted);font-size:.7rem}.course-add-lesson{border-top:1px dashed var(--st-line);padding-top:16px;margin-top:18px}.course-empty{text-align:center;padding:42px}.course-empty i{font-size:1.8rem;color:var(--st-blue)}.course-empty h3{margin:10px 0 5px}.course-empty p{font-size:.78rem;color:var(--st-muted)}@media(max-width:760px){.course-fields,.course-lesson-form{grid-template-columns:1fr}.course-inline-fields,.course-add-lesson .course-inline-fields,.course-section-edit{grid-template-columns:1fr}.course-curriculum-head{align-items:flex-start;flex-direction:column}.course-lesson summary{flex-wrap:wrap}.course-lesson-meta{width:100%;padding-right:40px}.course-admin .store-panel{padding:15px}}
</style>
@endpush
