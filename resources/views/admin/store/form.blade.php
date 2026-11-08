@extends('layouts.admin')
@section('admin-content')
@php($isEdit = $product->exists)
<section class="store-page" dir="rtl">
  <div class="store-container product-editor">
    <div class="store-section-head">
      <div><span class="eyebrow">مرکز مدیریت محصولات</span><h1>{{ $isEdit ? 'ویرایش محصول' : 'ایجاد محصول جدید' }}</h1><p>اطلاعات فروش، محتوا و نوع دسترسی را از یک نقطه مدیریت کنید.</p></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><a class="store-outline-btn" href="{{ route('admin.store.index') }}">فهرست محصولات</a>@if($isEdit)<a class="store-outline-btn" href="{{ route('store.product',$product->slug) }}" target="_blank" rel="noopener">پیش‌نمایش فروشگاه</a>@endif</div>
    </div>
    @if(session('status'))<div class="store-alert success" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="store-alert error" role="alert">{{ $errors->first() }}</div>@endif
    @if($isEdit)
      <section class="store-panel product-readiness" aria-labelledby="product-readiness-title">
        <div class="product-readiness-head">
          <div><span>کنترل پیش از انتشار</span><h2 id="product-readiness-title">آمادگی محصول برای فروش</h2></div>
          <strong class="{{ $publicationReady ? 'is-ready' : 'is-pending' }}">{{ $publicationReady ? 'آماده انتشار' : 'نیازمند تکمیل' }}</strong>
        </div>
        <div class="product-readiness-list">
          @foreach($readiness as $item)
            <div class="{{ $item['ready'] ? 'is-ready' : ($item['required'] ? 'is-pending' : 'is-optional') }}">
              <i class="fa-solid {{ $item['ready'] ? 'fa-circle-check' : ($item['required'] ? 'fa-circle-exclamation' : 'fa-circle-minus') }}" aria-hidden="true"></i>
              <span>{{ $item['label'] }}</span>
              <small>{{ $item['ready'] ? 'تکمیل شده' : ($item['required'] ? 'الزامی' : 'اختیاری') }}</small>
            </div>
          @endforeach
        </div>
        <p>این بررسی وضعیت واقعی اطلاعات ذخیره‌شده را نشان می‌دهد. کنترل نهایی انتشار همچنان در سرور انجام می‌شود.</p>
      </section>
    @endif

    <div class="product-workflow {{ $isEdit ? 'has-media' : '' }}" aria-label="مراحل آماده‌سازی محصول">
      <a href="#product-identity"><span>۱</span><b>مشخصات</b><small>هویت و دسته‌بندی</small></a>
      <a href="#product-pricing"><span>۲</span><b>قیمت‌گذاری</b><small>قیمت و انتشار</small></a>
      <a href="#product-content"><span>۳</span><b>محتوا</b><small>معرفی و توضیحات</small></a>
      @if($isEdit)<a href="#product-media"><span>۴</span><b>رسانه و فایل</b><small>کاور و دارایی‌ها</small></a>@endif
      <a href="#product-discovery"><span>{{ $isEdit ? '۵' : '۴' }}</span><b>نمایش</b><small>سئو و فروشگاه</small></a>
      <a href="#product-ai-supervisor"><span>{{ $isEdit ? '۶' : '۵' }}</span><b>ناظر هوشمند</b><small>کنترل کیفیت محصول</small></a>
    </div>
    <form method="post" action="{{ $isEdit ? route('admin.store.update',$product) : route('admin.store.store') }}" class="store-panel product-editor-form" id="productEditorForm">
      @csrf @if($isEdit) @method('PUT') @endif
      <div class="product-editor-heading" id="product-identity"><div><span>گام اول · هویت محصول</span><h2>مشخصات و دسته‌بندی</h2></div><span class="product-editor-step">اطلاعات ضروری</span></div>
      <div class="product-editor-grid">
        <label class="product-field product-field-wide">نام محصول / دوره *<input name="title" data-product-title value="{{ old('title',$product->title) }}" required maxlength="220" placeholder="عنوان دقیق و قابل فهم برای مشتری"></label>
        <label class="product-field">نوع محصول *@if($isEdit)<input type="hidden" name="type" value="{{ $product->type }}">@endif<select name="type" required @disabled($isEdit)><option value="digital_file" @selected(old('type',$product->type)==='digital_file')>محصول فایل دانلودی</option><option value="course" @selected(old('type',$product->type)==='course')>دوره آموزشی</option></select><small>{{ $isEdit ? 'نوع محصول پس از ایجاد ثابت است تا سوابق و دسترسی‌ها مخلوط نشوند.' : 'نوع محصول تعیین می‌کند چه ابزارهایی در مرحله بعد نمایش داده شود.' }}</small></label>
        <label class="product-field">شناسه محصول (SKU) *<input name="sku" value="{{ old('sku',$product->sku) }}" required maxlength="80" placeholder="مثلاً COURSE-001"></label>
        <label class="product-field">نشانی اختصاصی (Slug)<input name="slug" data-product-slug value="{{ old('slug',$product->slug) }}" maxlength="240" placeholder="خودکار از عنوان ساخته می‌شود"></label>
        <label class="product-field">دسته‌بندی *<select name="category_id" required><option value="">انتخاب دسته</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id',$product->category_id)==$category->id)>{{ $category->name }}</option>@endforeach</select></label>
        <div class="product-editor-subhead" id="product-pricing"><span>گام دوم</span><strong>قیمت‌گذاری و وضعیت فروش</strong><small>قیمت‌ها در پایگاه داده به ریال ذخیره می‌شوند.</small></div>
        <label class="product-field">قیمت فروش (ریال) *<input type="number" name="price_rials" value="{{ old('price_rials',$product->price_rials ?? 0) }}" min="0" max="999999999999" required inputmode="numeric"><small class="product-price-preview" data-price-preview>معادل ۰ تومان</small></label>
        <label class="product-field">قیمت قبل از تخفیف (ریال)<input type="number" name="compare_at_price_rials" value="{{ old('compare_at_price_rials',$product->compare_at_price_rials) }}" min="0" max="999999999999" inputmode="numeric"><small>برای نمایش تخفیف، باید از قیمت فروش بیشتر باشد.</small></label>
        @if($isEdit)
          <label class="product-field">وضعیت انتشار *<select name="status" required>@foreach(['draft'=>'پیش‌نویس','published'=>'منتشرشده','archived'=>'بایگانی'] as $value=>$label)<option value="{{ $value }}" @selected(old('status',$product->status)===$value)>{{ $label }}</option>@endforeach</select><small>انتشار فقط پس از تکمیل الزامات محصول و بررسی فایل اصلی یا جلسات دوره ممکن است.</small></label>
        @else
          <input type="hidden" name="status" value="draft"><div class="product-draft-note"><i class="fa-solid fa-shield-halved"></i><span><b>شروع امن با پیش‌نویس</b><small>محصول ابتدا خصوصی ساخته می‌شود؛ پس از بارگذاری محتوا و بررسی نهایی می‌توانید آن را منتشر کنید.</small></span></div>
        @endif
        <label class="product-field">نسخه / ویرایش<input name="version" value="{{ old('version',$product->version) }}" maxlength="60" placeholder="مثلاً 1.0"></label>
        <label class="product-field">نوع مجوز استفاده<input name="license_type" value="{{ old('license_type',$product->license_type) }}" maxlength="60" placeholder="مثلاً شخصی / استاندارد"></label>
        <label class="product-field">ترتیب نمایش<input type="number" name="sort_order" value="{{ old('sort_order',$product->sort_order ?? 0) }}" min="0"></label>
        <label class="product-field">تعداد صفحات نمونه<input type="number" name="preview_pages" value="{{ old('preview_pages',$product->preview_pages ?? 3) }}" min="0" max="50"></label>
        <label class="product-field product-field-check"><input type="checkbox" name="featured" value="1" @checked(old('featured',$product->featured))> نمایش به‌عنوان محصول ویژه</label>
      </div>
      <div class="product-editor-subhead" id="product-content"><span>گام سوم</span><strong>محتوا و معرفی محصول</strong><small>به مشتری کمک کنید پیش از خرید ارزش محصول را دقیق بشناسد.</small></div>
      <label class="product-field product-field-wide">معرفی کوتاه<textarea name="short_description" data-product-short-description rows="2" maxlength="2000" placeholder="در یک یا دو جمله بگویید این محصول چه ارزشی برای مشتری ایجاد می‌کند.">{{ old('short_description',$product->short_description) }}</textarea></label>
      <label class="product-field product-field-wide">توضیحات کامل<textarea name="description" rows="6" maxlength="20000" placeholder="شرح کامل محتوا، کاربردها، پیش‌نیازها و آنچه خریدار دریافت می‌کند.">{{ old('description',$product->description) }}</textarea></label>


      <div class="product-editor-subhead" id="product-discovery"><span>گام {{ $isEdit ? "پنجم" : "چهارم" }}</span><strong>نمایش در فروشگاه و موتورهای جست‌وجو</strong><small>در صورت خالی بودن، عنوان و توضیحات اصلی می‌توانند مبنای نمایش قرار گیرند.</small></div>
      <label class="product-field product-field-wide">عنوان سئو<input name="seo_title" data-seo-title value="{{ old('seo_title',$product->seo_title) }}" maxlength="220" placeholder="عنوان مناسب برای موتورهای جستجو"></label>
      <label class="product-field product-field-wide">توضیحات سئو<textarea name="seo_description" data-seo-description rows="2" maxlength="320" placeholder="خلاصه جذاب و دقیق برای نتیجه جستجو">{{ old('seo_description',$product->seo_description) }}</textarea></label>
      <section class="product-search-preview" aria-label="پیش‌نمایش نتیجه جست‌وجو">
        <span>پیش‌نمایش زنده نتیجه جست‌وجو</span>
        <strong data-seo-preview-title>{{ old('seo_title', $product->seo_title ?: $product->title ?: 'عنوان محصول شما') }}</strong>
        <small data-seo-preview-url>{{ url('/store/product') }}/{{ old('slug', $product->slug ?: 'product-url') }}</small>
        <p data-seo-preview-description>{{ old('seo_description', $product->seo_description ?: $product->short_description ?: 'توضیحات محصول شما در این قسمت نمایش داده می‌شود.') }}</p>
      </section>
      <div class="product-editor-grid product-discovery-links">
        <label class="product-field product-field-wide">برچسب‌های محصول
          <select name="tag_ids[]" multiple size="5" aria-describedby="product-tags-help">
            @foreach($tags as $tag)<option value="{{ $tag->id }}" @selected(in_array($tag->id, old('tag_ids', $product->tags->pluck('id')->all())))>{{ $tag->name }}</option>@endforeach
          </select>
          <small id="product-tags-help">برای انتخاب چند مورد، در رایانه Ctrl یا در موبایل لمس چندگانه را استفاده کنید. برچسب‌ها برای دسته‌بندی و کشف بهتر محصولات استفاده می‌شوند.</small>
        </label>
        <label class="product-field product-field-wide">محصولات مرتبط
          <select name="related_product_ids[]" multiple size="5" aria-describedby="product-related-help">
            @foreach($relatedProducts as $relatedProduct)<option value="{{ $relatedProduct->id }}" @selected(in_array($relatedProduct->id, old('related_product_ids', $product->related->pluck('id')->all())))>{{ $relatedProduct->title }} · {{ $relatedProduct->sku }} · {{ $relatedProduct->type === 'course' ? 'دوره' : 'فایل' }}</option>@endforeach
          </select>
          <small id="product-related-help">حداکثر ۱۲ محصول مرتبط؛ محصول جاری از فهرست انتخاب حذف شده است.</small>
        </label>
      </div>
      <section class="product-ai-supervisor" id="product-ai-supervisor" aria-labelledby="product-ai-supervisor-title">
        <div class="product-ai-supervisor-head">
          <div class="product-ai-mark"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>
          <div><span>گام {{ $isEdit ? "ششم" : "پنجم" }} · کنترل کیفیت هوشمند</span><h2 id="product-ai-supervisor-title">هوش مصنوعی ناظر محصول</h2><p>برای هر محصول، سیاست نظارت مستقل تعریف کنید؛ تنظیمات در کنار خود محصول ذخیره می‌شوند.</p></div>
          <label class="product-ai-switch"><input type="checkbox" name="ai_supervisor[enabled]" value="1" @checked(old('ai_supervisor.enabled', data_get($product->metadata, 'ai_supervisor.enabled', false)))><span></span><b>فعال‌سازی ناظر</b></label>
        </div>
        <div class="product-ai-supervisor-body">
          <div class="product-ai-notice"><i class="fa-solid fa-circle-info"></i><span><b>پیکربندی مستقل از موتور هوش فعلی</b><small>این ناظر از سرویس مستقل مخصوص کنترل کیفیت محصول استفاده می‌کند و هیچ وابستگی به موتور یا رندر فعلی سایت ندارد.</small></span></div>
          @if($isEdit)
            @php($lastSupervisorReview = data_get($product->metadata, 'ai_supervisor.last_review'))
            <div class="product-ai-runtime">
              <div class="product-ai-runtime-head">
                <div><span>موتور ناظر مستقل</span><strong>بررسی کیفیت محصول</strong><small>این عملیات فقط یک گزارش پیشنهاد می‌دهد و هیچ‌گاه محصول را خودکار منتشر یا ویرایش نمی‌کند.</small><small>{{ config('services.product_supervisor.url') && config('services.product_supervisor.token') ? 'اتصال سرویس پیکربندی شده است.' : 'اتصال سرویس مستقل هنوز تکمیل نشده است.' }}</small></div>
                <button type="submit" form="productSupervisorReviewForm" class="store-primary-btn" @disabled(!data_get($product->metadata, 'ai_supervisor.enabled', false) || !config('services.product_supervisor.url') || !config('services.product_supervisor.token'))>اجرای بررسی هوشمند</button>
              </div>
              @if($lastSupervisorReview && $supervisorReviewFresh)
                <div class="product-ai-result">
                  <div class="product-ai-result-top">
                    <strong class="verdict-{{ $lastSupervisorReview['verdict'] ?? 'needs_changes' }}">{{ ['approved'=>'بدون ایراد مهم','needs_changes'=>'نیازمند اصلاح','blocked'=>'دارای مانع جدی'][$lastSupervisorReview['verdict'] ?? 'needs_changes'] ?? 'نیازمند بازبینی' }}</strong>
                    <span>امتیاز کیفیت: {{ (int) ($lastSupervisorReview['score'] ?? 0) }} از ۱۰۰</span>
                  </div>
                  <p>{{ $lastSupervisorReview['summary'] ?? 'گزارش ناظر ثبت شد.' }}</p>
                  @if(!empty($lastSupervisorReview['findings']))
                    <div class="product-ai-findings">
                      @foreach($lastSupervisorReview['findings'] as $finding)
                        <article class="severity-{{ $finding['severity'] ?? 'info' }}">
                          <div><b>{{ $finding['title'] ?? 'نکته بررسی' }}</b><span>{{ ['critical'=>'بحرانی','warning'=>'هشدار','info'=>'اطلاع'][$finding['severity'] ?? 'info'] ?? 'اطلاع' }}</span></div>
                          <p>{{ $finding['detail'] ?? '' }}</p>
                          <small>اقدام پیشنهادی: {{ $finding['recommendation'] ?? 'بازبینی توسط مدیر' }}</small>
                        </article>
                      @endforeach
                    </div>
                  @endif
                  <small class="product-ai-result-meta">مدل: {{ $lastSupervisorReview['model'] ?? '—' }} · شناسه بررسی: {{ $lastSupervisorReview['review_id'] ?? '—' }} · زمان: {{ $lastSupervisorReview['generated_at'] ?? '—' }}</small>
                  <small class="product-ai-result-meta">نتیجه ناظر جایگزین تصمیم مدیر نیست و انتشار محصول همچنان از کنترل‌های سمت سرور عبور می‌کند.</small>
                </div>
              @elseif($lastSupervisorReview)
                <p class="product-ai-no-result">گزارش قبلی به دلیل تغییر مشخصات، رسانه یا محتوای دوره دیگر معتبر نیست. برای تصمیم‌گیری، بررسی تازه اجرا کنید.</p>
              @else
                <p class="product-ai-no-result">هنوز گزارشی برای این محصول ثبت نشده است. ابتدا ناظر را فعال و سپس بررسی را اجرا کنید.</p>
              @endif
            </div>
          @else
            <div class="product-ai-runtime"><p class="product-ai-no-result">پس از ایجاد پیش‌نویس، موتور ناظر مستقل از صفحه ویرایش محصول قابل اجرا خواهد بود.</p></div>
          @endif
          <div class="product-editor-grid">
            <label class="product-field">رفتار ناظر
              <select name="ai_supervisor[mode]">
                <option value="suggest" @selected(old('ai_supervisor.mode', data_get($product->metadata, 'ai_supervisor.mode', 'suggest')) === 'suggest')>پیشنهاد و هشدار؛ تصمیم با مدیر</option>
                <option value="review_gate" @selected(old('ai_supervisor.mode', data_get($product->metadata, 'ai_supervisor.mode', 'suggest')) === 'review_gate')>نیازمند تأیید ناظر پیش از انتشار</option>
              </select>
              <small>این انتخاب، سیاست مورد انتظار را ذخیره می‌کند؛ تا زمان اتصال سرویس ناظر اختصاصی، هیچ تصمیم خودکاری درباره انتشار اعمال نمی‌شود.</small>
            </label>
            <label class="product-field product-field-wide">دستورالعمل اختصاصی ناظر
              <textarea name="ai_supervisor[instructions]" rows="3" maxlength="4000" placeholder="مثلاً ادعاهای آموزشی را با محتوای واقعی دوره تطبیق بده و از وعده‌های قطعی درآمد خودداری کن.">{{ old('ai_supervisor.instructions', data_get($product->metadata, 'ai_supervisor.instructions', '')) }}</textarea>
              <small>قواعد اختصاصی این محصول؛ دستورالعمل عمومی سامانه را جایگزین نمی‌کند.</small>
            </label>
          </div>
          <div class="product-ai-checks-title"><strong>دامنه بررسی ناظر</strong><small>مواردی را که باید در ارزیابی این محصول بررسی شوند انتخاب کنید.</small></div>
          <div class="product-ai-checks">
            @php($aiChecks = ['content'=>'کیفیت محتوا و کامل‌بودن معرفی','pricing'=>'منطق قیمت‌گذاری و تخفیف','seo'=>'عنوان، توضیحات و سئوی محصول','media'=>'کاور، تصاویر و پیش‌نمایش‌ها','policy'=>'حقوق استفاده، ادعاها و سیاست‌ها','course'=>'ساختار آموزشی و جلسات دوره'])
            @foreach($aiChecks as $key => $label)
              <label><input type="checkbox" name="ai_supervisor[checks][{{ $key }}]" value="1" @checked(old('ai_supervisor.checks.'.$key, data_get($product->metadata, 'ai_supervisor.checks.'.$key, true)))><span>{{ $label }}</span></label>
            @endforeach
          </div>
          <div class="product-ai-footer"><i class="fa-solid fa-lock"></i><span>تنظیمات ناظر در metadata همین محصول نگهداری می‌شود و به سایر محصولات نشت نمی‌کند.</span></div>
        </div>
      </section>

      <div class="product-editor-actions"><button type="submit" class="store-primary-btn" id="productSaveButton">{{ $isEdit ? 'ذخیره اطلاعات محصول' : 'ایجاد پیش‌نویس و ادامه تکمیل' }}</button><span>اطلاعات ذخیره‌شده در تمام بخش‌های فروشگاه و دوره استفاده می‌شود.</span></div>
    </form>
    @if($isEdit)
      <form id="productSupervisorReviewForm" method="post" action="{{ route('admin.store.ai-supervisor.review', $product) }}">@csrf</form>
    @endif

    @if($isEdit)
      @php($assetReviews = (array) data_get($product->metadata, 'ai_supervisor.asset_reviews', []))
      <div class="store-panel product-files-panel" id="product-media">
        <div class="product-editor-heading"><div><span>رسانه محصول</span><h2>تصویر کاور</h2></div><span class="product-editor-step">حداکثر ۸ مگابایت</span></div>
        @if($product->cover_path)<img src="{{ asset($product->cover_path) }}" alt="کاور {{ $product->title }}" style="display:block;width:min(320px,100%);aspect-ratio:16/9;object-fit:cover;border-radius:12px;margin:12px 0">@else<p>هنوز کاوری ثبت نشده است.</p>@endif
        <form method="post" action="{{ route('admin.store.cover.upload',$product) }}" enctype="multipart/form-data" class="product-file-upload" data-upload-form>@csrf<input type="file" name="cover" accept=".jpg,.jpeg,.png,.webp" required><button type="submit" class="store-outline-btn">بارگذاری کاور و اسکن امنیتی</button><small class="product-selected-file" data-file-name>فایلی انتخاب نشده است.</small></form>
        @if($product->cover_path)
          <form method="post" action="{{ route('admin.store.ai-supervisor.cover.review',$product) }}" class="product-file-upload">@csrf<button type="submit" class="store-outline-btn" @disabled(!data_get($product->metadata,'ai_supervisor.enabled',false) || !config('services.product_supervisor.url') || !config('services.product_supervisor.token'))>تحلیل هوشمند تصویر کاور</button></form>
          @include('admin.store.partials.asset-review', ['assetReview' => $assetReviews['cover'] ?? null, 'label' => 'گزارش ناظر کاور'])
        @endif
        <small class="product-help">فرمت‌های JPG، PNG و WebP · حداکثر ۸ مگابایت</small>
      </div>
      <div class="store-panel product-files-panel product-gallery-panel">
        <div class="product-editor-heading"><div><span>گالری تصاویر</span><h2>تصاویر تکمیلی محصول</h2></div><span class="product-editor-step">{{ $product->images->count() }} تصویر</span></div>
        <p>تصاویر گالری در صفحه عمومی محصول نمایش داده می‌شوند. همه تصاویر پیش از انتشار اسکن امنیتی می‌شوند.</p>
        <div class="product-gallery-grid">
          @forelse($product->images as $image)
            <div class="product-gallery-item">
              <img src="{{ asset($image->path) }}" alt="{{ $image->alt ?: $product->title }}" loading="lazy">
              <div><small>{{ $image->alt ?: 'بدون متن جایگزین' }}</small>
                <form method="post" action="{{ route('admin.store.ai-supervisor.images.review',[$product,$image]) }}">@csrf<button type="submit" class="store-outline-btn" @disabled(!data_get($product->metadata,'ai_supervisor.enabled',false) || !config('services.product_supervisor.url') || !config('services.product_supervisor.token'))>تحلیل تصویر</button></form>
                <form method="post" action="{{ route('admin.store.images.delete',[$product,$image]) }}" onsubmit="return confirm('این تصویر از گالری حذف شود؟')">@csrf @method('DELETE')<button type="submit" class="product-danger">حذف</button></form>
              </div>
              @include('admin.store.partials.asset-review', ['assetReview' => $assetReviews['image:'.$image->id] ?? null, 'label' => 'گزارش ناظر تصویر'])
            </div>
          @empty
            <div class="course-empty">هنوز تصویری به گالری اضافه نشده است.</div>
          @endforelse
        </div>
        <form method="post" action="{{ route('admin.store.images.upload',$product) }}" enctype="multipart/form-data" class="product-file-upload" data-upload-form>
          @csrf
          <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp" required>
          <input type="text" name="alt" maxlength="180" placeholder="متن جایگزین تصویر (اختیاری)">
          <small class="product-selected-file" data-file-name>فایلی انتخاب نشده است.</small>
          <button type="submit" class="store-outline-btn">افزودن تصویر و اسکن امنیتی</button>
        </form>
        <small class="product-help">JPG، PNG یا WebP · حداکثر ۸ مگابایت</small>
      </div>
    @endif

    @if($isEdit && $product->type==='course')
      <div class="store-panel product-next-step"><div><strong>مرحله بعد: طراحی برنامه آموزشی</strong><p>فصل‌ها، جلسات، ویدئوها، فایل‌های ضمیمه و تنظیمات گواهی را مدیریت کنید.</p></div><a class="store-primary-btn" href="{{ route('admin.store.course.edit',$product) }}">ورود به مدیریت دوره <i class="fa-solid fa-arrow-left"></i></a></div>
    @elseif($isEdit)
      <div class="store-panel product-files-panel">
        <div class="product-editor-heading"><div><span>محتوای قابل دریافت</span><h2>فایل‌های محصول</h2></div><span class="product-editor-step">اسکن امنیتی الزامی</span></div>
        <p>فایل‌ها در فضای خصوصی نگهداری می‌شوند. فقط فایل اصلیِ اسکن‌شده و فعال پس از خرید قابل دریافت خواهد بود.</p>
        <div class="product-file-list">@forelse($product->files->where('purpose','product_download') as $file)
          @php($fileReview = $assetReviews['file:'.$file->id] ?? null)
          @php($documentState = $file->document?->processing_status ?? 'pending')
          @php($documentStateLabels = ['pending'=>'در انتظار مطالعه','processing'=>'در حال مطالعه','awaiting_provider'=>'در انتظار اتصال موتور مطالعه','extracted'=>'استخراج انجام شد؛ تحلیل معنایی ناقص','completed'=>'مطالعه و تحلیل تکمیل شد','partial'=>'پردازش ناقص','failed'=>'خطا در مطالعه','stale'=>'نسخه فایل تغییر کرده است'])
          <div class="product-file-row"><span><i class="fa-solid fa-file-arrow-down"></i><b>{{ $file->original_name }}</b><small>{{ number_format($file->size_bytes/1024,1) }} KB · {{ $file->scan_status==='clean'?'بررسی داخلی گذرانده':'نیازمند بررسی مجدد' }} @if($file->is_primary) · فایل اصلی @endif</small><small>مطالعه محتوا: {{ $documentStateLabels[$documentState] ?? 'وضعیت نامشخص' }}</small>
            @if(data_get($file->document?->structure_json, 'ai_review.summary'))
              <small class="product-asset-review-inline">{{ data_get($file->document?->structure_json, 'ai_review.summary') }}</small>
            @endif
            @if(data_get($file->document?->structure_json, 'sample.selected_pages'))
              <small>فایل نمونه یک‌بار ساخته شده · صفحات {{ implode('، ', data_get($file->document?->structure_json, 'sample.selected_pages', [])) }}</small>
            @endif
            @if(data_get($file->document?->structure_json, 'audio.status'))
              <small>نسخه صوتی: {{ ['completed'=>'ساخته و ذخیره شد','awaiting_semantic_review'=>'در انتظار تکمیل مطالعه محتوایی','awaiting_provider'=>'در انتظار موتور تبدیل متن به صدا','partial'=>'تولید صوت ناقص','failed'=>'خطا در تولید صوت'][data_get($file->document?->structure_json, 'audio.status')] ?? 'در حال آماده‌سازی' }}</small>
            @endif
          </span>
          @if($fileReview && hash_equals((string) ($fileReview['sha256'] ?? ''), (string) $file->sha256))
            @include('admin.store.partials.asset-review', ['assetReview' => $fileReview, 'label' => 'گزارش ناظر فایل'])
          @endif
          <form method="post" action="{{ route('admin.store.ai-supervisor.files.review',[$product,$file]) }}">@csrf<button type="submit" class="store-outline-btn" @disabled(!data_get($product->metadata,'ai_supervisor.enabled',false) || !config('services.product_supervisor.url') || !config('services.product_supervisor.token'))>بررسی محتوای فایل</button></form>
          <form method="post" action="{{ route('admin.store.files.delete',[$product,$file]) }}" onsubmit="return confirm('فایل حذف شود؟')">@csrf @method('DELETE')<button type="submit" class="product-danger">حذف</button></form></div>
        @empty <div class="course-empty">هنوز فایلی بارگذاری نشده است.</div>@endforelse</div>
        <section class="product-ingestion" data-product-ingestion
          data-preflight-url="{{ route('admin.store.files.preflight',$product) }}"
          data-confirm-url-template="{{ route('admin.store.files.confirm',[$product,'__INGESTION__']) }}"
          data-cancel-url-template="{{ route('admin.store.files.cancel',[$product,'__INGESTION__']) }}">
          <input type="hidden" data-ingestion-csrf value="{{ csrf_token() }}">
          <input type="file" data-ingestion-input multiple hidden accept=".pdf,.zip,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.md,.mp3,.wav,.ogg,.flac,.m4a,.mp4,.webm,.mov">
          <div class="product-ingestion-dropzone" data-ingestion-pick tabindex="0" role="button" aria-label="انتخاب یا رها کردن فایل محصول">
            <span class="product-ingestion-icon"><i class="fa-solid fa-cloud-arrow-up"></i></span>
            <strong>فایل را اینجا رها کنید یا برای انتخاب کلیک کنید</strong>
            <span>می‌توانید فایل را روی هر قسمت از همین صفحه بکشید و رها کنید.</span>
            <small>PDF، Word، Excel، PowerPoint، تصویر، صوت، ویدئو، ZIP و متن · حداکثر ۵۰ مگابایت برای هر فایل</small>
          </div>
          <label class="product-ingestion-primary"><input type="checkbox" data-ingestion-primary checked> اولین فایل، فایل اصلی محصول باشد</label>
          <div class="product-ingestion-queue" data-ingestion-queue aria-live="polite"></div>
          <div class="product-ingestion-global-drop" data-ingestion-global hidden><span><i class="fa-solid fa-cloud-arrow-down"></i></span><b>فایل را رها کنید تا بررسی اولیه آغاز شود</b><small>هنوز چیزی ذخیره یا منتشر نمی‌شود.</small></div>
          <div class="product-ingestion-modal" data-ingestion-modal hidden role="dialog" aria-modal="true" aria-labelledby="ingestion-modal-title">
            <div class="product-ingestion-dialog">
              <div class="product-ingestion-modal-head"><span class="product-ingestion-icon"><i class="fa-solid fa-shield-halved"></i></span><div><small>بررسی داخلی فراست</small><h3 id="ingestion-modal-title">گزارش بررسی فایل</h3><p data-ingestion-filename></p></div><button type="button" data-ingestion-close aria-label="بستن پنجره">×</button></div>
              <div class="product-ingestion-timeline" data-ingestion-timeline>
                <div data-step="received" class="is-done"><i>✓</i><span>دریافت موقت فایل</span></div>
                <div data-step="inspect"><i>2</i><span>بررسی ساختار و امنیت</span></div>
                <div data-step="review"><i>3</i><span>نمایش گزارش برای تأیید شما</span></div>
                <div data-step="store"><i>4</i><span>ذخیره‌سازی نهایی</span></div>
              </div>
              <div class="product-ingestion-report" data-ingestion-report><p>در حال بررسی فایل در محیط سایت...</p></div>
              <p class="product-ingestion-security-note">این اسکنر داخلی فایل را اجرا نمی‌کند و سرویس ضدبدافزار ثالثی به کار نمی‌گیرد. نتیجه، بررسی ایستای ساختار و نشانه‌های خطر است و تضمین کشف همه بدافزارهای ناشناخته نیست.</p>
              <div class="product-ingestion-actions"><button type="button" class="store-outline-btn" data-ingestion-cancel>رد فایل</button><button type="button" class="store-primary-btn" data-ingestion-confirm disabled>تأیید و ذخیره فایل</button></div>
            </div>
          </div>
        </section>
        <small class="product-help">فایل ابتدا در قرنطینه خصوصی بررسی می‌شود. فقط پس از مشاهده گزارش و تأیید شما به فهرست فایل‌های محصول منتقل خواهد شد.</small>
      </div>
      <div class="store-panel product-files-panel">
        <h2>پیش‌نمایش عمومی محصول</h2><p>تصاویر یا صفحات نمونه برای آشنایی خریدار؛ فایل‌ها پیش از ذخیره اسکن می‌شوند.</p>
        <div class="store-preview-grid">@forelse($product->previews as $preview)<div>@if($preview->kind==='pdf')<div class="store-preview-pdf">PDF · {{ $preview->page_number }}</div>@else<img src="{{ route('store.preview',$preview) }}" alt="" loading="lazy">@endif<form method="post" action="{{ route('admin.store.previews.delete',[$product,$preview]) }}">@csrf @method('DELETE')<button class="product-danger" type="submit">حذف</button></form></div>@empty<div class="course-empty">هنوز پیش‌نمایشی اضافه نشده است.</div>@endforelse</div>
        <form method="post" action="{{ route('admin.store.previews.upload',$product) }}" enctype="multipart/form-data" class="product-file-upload" data-upload-form>@csrf<input type="file" name="preview" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf" required><small class="product-selected-file" data-file-name>فایلی انتخاب نشده است.</small><input type="number" name="page_number" min="1" max="500" placeholder="شماره صفحه"><button type="submit" class="store-outline-btn">افزودن پیش‌نمایش</button></form>
      </div>
    @endif

    <div class="store-panel product-category-panel"><h2>مدیریت سریع دسته‌بندی</h2><p class="product-inline-feedback" data-inline-feedback role="status" aria-live="polite"></p><form method="post" action="{{ route('admin.store.categories.store') }}" data-inline-create="category">@csrf<input name="name" required maxlength="160" placeholder="نام دسته جدید"><input name="slug" maxlength="180" placeholder="نشانی (اختیاری)"><button type="submit" class="store-outline-btn">افزودن دسته</button></form></div>
    <div class="store-panel product-category-panel product-tag-panel"><h2>مدیریت سریع برچسب‌ها</h2><p class="product-inline-feedback" data-inline-feedback role="status" aria-live="polite"></p><form method="post" action="{{ route('admin.store.tags.store') }}" data-inline-create="tag">@csrf<input name="name" required maxlength="100" placeholder="نام برچسب جدید"><input name="slug" maxlength="120" placeholder="نشانی (اختیاری)"><button type="submit" class="store-outline-btn">افزودن برچسب</button></form><small class="product-help">پس از ذخیره، برچسب در فهرست برچسب‌های قابل انتخاب محصول قرار می‌گیرد.</small></div>
  </div>
</section>
@endsection
@push('scripts')
<script src="{{ asset('js/product-ingestion.js') }}" defer></script>
@endpush
@push('styles')

.product-ingestion{display:grid;gap:10px;margin-top:14px}
.product-ingestion-dropzone{min-height:178px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:24px;border:2px dashed #9db7d8;border-radius:18px;background:linear-gradient(145deg,#f7faff,#fff);text-align:center;cursor:pointer;transition:border-color .2s,background .2s,transform .2s}
.product-ingestion-dropzone:hover,.product-ingestion-dropzone:focus-visible{border-color:#3476cf;background:#f0f6ff;outline:none}
.product-ingestion-dropzone.is-dragging{border-color:#2868c8;background:#eaf3ff;transform:scale(.995)}
.product-ingestion-icon{width:48px;height:48px;display:grid;place-items:center;border-radius:15px;background:#e8f1ff;color:#2868c8;font-size:1.25rem}
.product-ingestion-dropzone strong{font-size:.86rem;color:var(--st-ink)}
.product-ingestion-dropzone>span:not(.product-ingestion-icon){font-size:.72rem;color:var(--st-muted)}
.product-ingestion-dropzone small,.product-ingestion-primary{font-size:.66rem;color:var(--st-muted)}
.product-ingestion-primary{display:flex;align-items:center;gap:7px}
.product-ingestion-global-drop{position:fixed;inset:14px;z-index:9998;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;border:3px dashed #3476cf;border-radius:24px;background:rgba(237,245,255,.96);color:#245da7;box-shadow:0 20px 80px rgba(17,48,91,.22);pointer-events:none;animation:ingestionFadeIn .16s ease-out}
.product-ingestion-global-drop[hidden],.product-ingestion-modal[hidden]{display:none}
.product-ingestion-global-drop>span{font-size:3rem}.product-ingestion-global-drop b{font-size:1.1rem}.product-ingestion-global-drop small{font-size:.78rem}
.product-ingestion-modal{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;padding:18px;background:rgba(12,24,42,.58);backdrop-filter:blur(8px);animation:ingestionFadeIn .18s ease-out}
.product-ingestion-dialog{width:min(760px,100%);max-height:min(88vh,900px);overflow:auto;padding:22px;border:1px solid #dce7f4;border-radius:22px;background:#fff;box-shadow:0 28px 90px rgba(0,0,0,.25);animation:ingestionDialogIn .22s cubic-bezier(.2,.8,.2,1)}
.product-ingestion-modal-head{display:flex;align-items:center;gap:13px}.product-ingestion-modal-head>div{flex:1;min-width:0}.product-ingestion-modal-head small{color:#3474c7;font-size:.66rem;font-weight:900}.product-ingestion-modal-head h3{margin:3px 0;font-size:1rem}.product-ingestion-modal-head p{margin:0;color:var(--st-muted);font-size:.72rem;overflow-wrap:anywhere}.product-ingestion-modal-head>button{border:0;background:#f1f4f8;border-radius:10px;width:34px;height:34px;font-size:1.35rem;cursor:pointer}
.product-ingestion-timeline{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:22px 0 16px}
.product-ingestion-timeline>div{display:flex;align-items:center;gap:7px;padding:10px 8px;border:1px solid #e5ebf2;border-radius:12px;color:#8995a4;font-size:.65rem;line-height:1.5}
.product-ingestion-timeline i{width:24px;height:24px;flex:0 0 24px;display:grid;place-items:center;border-radius:50%;background:#edf1f5;font-style:normal;font-weight:900}
.product-ingestion-timeline .is-active{border-color:#8eb2e7;background:#f3f8ff;color:#245da7}.product-ingestion-timeline .is-active i{background:#dceaff;color:#245da7;animation:ingestionPulse 1s infinite}
.product-ingestion-timeline .is-done{border-color:#cde8d8;color:#287449}.product-ingestion-timeline .is-done i{background:#e5f6eb;color:#287449}
.product-ingestion-report{padding:15px;border:1px solid #e5ebf2;border-radius:14px;background:#fbfcfe;font-size:.76rem;line-height:1.9}.product-ingestion-report h4{margin:0 0 10px;font-size:.8rem}.product-ingestion-report p{margin:5px 0}.product-ingestion-report-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.product-ingestion-report-grid>div{padding:9px;border-radius:10px;background:#fff;border:1px solid #e8edf3}.product-ingestion-report-grid small{display:block;color:var(--st-muted);font-size:.62rem}.product-ingestion-report-grid b{font-size:.76rem;overflow-wrap:anywhere}.product-ingestion-archive{max-height:170px;overflow:auto;margin:10px 0 0;padding:9px 28px 9px 9px;border:1px solid #e8edf3;border-radius:10px;background:#fff}.product-ingestion-archive li{font-size:.68rem;overflow-wrap:anywhere}.product-ingestion-warning{padding:9px 11px;margin-top:9px;border-radius:10px;background:#fff6e8;color:#8b5b16;font-size:.68rem}
.product-ingestion-security-note{margin:12px 0;color:var(--st-muted);font-size:.64rem;line-height:1.9}.product-ingestion-actions{display:flex;justify-content:flex-end;gap:9px;padding-top:12px;border-top:1px solid #edf0f4}.product-ingestion-actions button{min-height:42px}.product-ingestion-queue{display:grid;gap:7px}.product-ingestion-queue:empty{display:none}.product-ingestion-queue>div{display:flex;justify-content:space-between;gap:10px;padding:9px 12px;border:1px solid var(--st-line);border-radius:10px;font-size:.68rem}
@keyframes ingestionFadeIn{from{opacity:0}to{opacity:1}}@keyframes ingestionDialogIn{from{opacity:0;transform:translateY(12px) scale(.985)}to{opacity:1;transform:translateY(0) scale(1)}}@keyframes ingestionPulse{50%{box-shadow:0 0 0 5px rgba(52,116,199,.12)}}
@media(max-width:680px){.product-ingestion-dialog{padding:15px;border-radius:17px}.product-ingestion-timeline{grid-template-columns:repeat(2,minmax(0,1fr))}.product-ingestion-report-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.product-ingestion-actions{flex-direction:column-reverse}.product-ingestion-actions button{width:100%}}

<link rel="stylesheet" href="/css/store.css?v=20261107product1">
<style>


.product-readiness{margin:0 0 16px;padding:18px}
.product-readiness-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
.product-readiness-head span{font-size:.66rem;color:var(--st-blue);font-weight:900}
.product-readiness-head h2{font-size:.92rem;margin:4px 0 0}
.product-readiness-head>strong{padding:7px 11px;border-radius:99px;font-size:.68rem}
.product-readiness .is-ready{color:#18764a}.product-readiness .is-pending{color:#a45a12}
.product-readiness-head>strong.is-ready{background:#eaf7ef}.product-readiness-head>strong.is-pending{background:#fff4e7}
.product-readiness-list{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:14px}
.product-readiness-list>div{display:grid;grid-template-columns:auto 1fr;align-items:center;gap:5px 8px;padding:10px;border:1px solid var(--st-line);border-radius:11px;background:#fbfcfe}
.product-readiness-list i{grid-row:span 2;font-size:.9rem}
.product-readiness-list span{font-size:.69rem;font-weight:800;color:var(--st-ink)}
.product-readiness-list small{font-size:.62rem;color:var(--st-muted)}
.product-readiness>p{margin:12px 0 0;color:var(--st-muted);font-size:.65rem}
@media(max-width:760px){.product-readiness-list{grid-template-columns:repeat(2,minmax(0,1fr))}.product-readiness{padding:14px}}

.product-workflow{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:0 0 16px}.product-workflow.has-media{grid-template-columns:repeat(6,minmax(0,1fr))}
.product-workflow a{display:grid;grid-template-columns:34px 1fr;grid-template-rows:auto auto;align-items:center;gap:2px 10px;padding:13px;border:1px solid var(--st-line);border-radius:14px;background:#fff;color:var(--st-ink);text-decoration:none;transition:border-color .18s,box-shadow .18s}
.product-workflow a:hover,.product-workflow a:focus-visible{border-color:#9bb9e7;box-shadow:0 5px 18px rgba(35,91,170,.08);outline:none}
.product-workflow a>span{grid-row:span 2;width:34px;height:34px;display:grid;place-items:center;border-radius:10px;background:#edf4ff;color:#2868c8;font-weight:900}
.product-workflow b{font-size:.75rem}.product-workflow small{font-size:.64rem;color:var(--st-muted)}
.product-editor-subhead{grid-column:1/-1;display:grid;gap:3px;padding:12px 14px;margin-top:4px;border:1px solid #e5edf7;border-radius:12px;background:linear-gradient(100deg,#f7faff,#fff)}
.product-editor-subhead span{color:var(--st-blue);font-size:.65rem;font-weight:900}
.product-editor-subhead strong{font-size:.83rem;color:var(--st-ink)}
.product-editor-subhead small{font-size:.66rem;color:var(--st-muted)}
.product-draft-note{display:flex;align-items:center;gap:11px;padding:12px;border:1px solid #dce9fa;border-radius:11px;background:#f5f9ff;color:#2868c8}
.product-draft-note>i{font-size:1.1rem}.product-draft-note span{display:grid;gap:4px}.product-draft-note b{font-size:.74rem}.product-draft-note small{font-size:.66rem;color:#637a99;line-height:1.8}
.product-price-preview{color:#2868c8!important;font-weight:800!important}
.product-selected-file{flex:1 1 100%;color:var(--st-muted);font-size:.67rem}
.product-editor-form input:focus-visible,.product-editor-form select:focus-visible,.product-editor-form textarea:focus-visible{outline:3px solid rgba(44,105,204,.14);border-color:#83a9e5}
.product-editor-form [aria-invalid=true]{border-color:#c84b4b!important;background:#fffafa!important}
.product-editor-actions{position:sticky;bottom:12px;z-index:5;padding:12px;border:1px solid #dfe8f4;border-radius:14px;background:rgba(255,255,255,.94);backdrop-filter:blur(12px);box-shadow:0 8px 28px rgba(15,35,70,.1)}
.product-editor-actions button{min-height:44px}
@media(max-width:760px){.product-workflow,.product-workflow.has-media{grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.product-workflow a{padding:10px}.product-editor-actions{bottom:6px}.product-editor-actions button{width:100%}}
@media(max-width:420px){.product-workflow a{grid-template-columns:29px 1fr;gap:2px 7px}.product-workflow a>span{width:29px;height:29px}}


.product-ai-supervisor{overflow:hidden;border:1px solid #d8e5f5;border-radius:17px;background:linear-gradient(145deg,#f8fbff,#fff);box-shadow:0 8px 24px rgba(25,64,118,.055)}
.product-ai-supervisor-head{display:flex;align-items:center;gap:13px;padding:18px;border-bottom:1px solid #e5edf7;background:linear-gradient(110deg,#edf5ff,#fbfdff)}
.product-ai-mark{width:44px;height:44px;flex:0 0 44px;display:grid;place-items:center;border-radius:14px;background:#e0edff;color:#2868c8;font-size:1.1rem}
.product-ai-supervisor-head>div:nth-child(2){flex:1;min-width:0}
.product-ai-supervisor-head>div:nth-child(2)>span{color:#3474c7;font-size:.66rem;font-weight:900}
.product-ai-supervisor-head h2{margin:3px 0;font-size:1rem;color:#213b5d}
.product-ai-supervisor-head p{margin:0;color:#71839b;font-size:.7rem;line-height:1.8}
.product-ai-switch{display:flex;align-items:center;gap:8px;white-space:nowrap;color:#38516f;font-size:.7rem;cursor:pointer}
.product-ai-switch input{width:18px;height:18px;accent-color:#2868c8}
.product-ai-supervisor-body{display:grid;gap:17px;padding:18px}
.product-ai-notice{display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid #dce8f8;border-radius:12px;background:#f5f9ff;color:#3775c5}
.product-ai-notice>span{display:grid;gap:3px}
.product-ai-notice b{color:#355477;font-size:.72rem}
.product-ai-notice small{color:#7589a3;font-size:.66rem;line-height:1.8}
.product-ai-checks-title{display:grid;gap:4px}
.product-ai-checks-title strong{font-size:.78rem;color:#344c69}
.product-ai-checks-title small{font-size:.66rem;color:#8291a5}
.product-ai-checks{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}
.product-ai-checks label{display:flex;align-items:center;gap:8px;padding:11px;border:1px solid #e3eaf3;border-radius:10px;background:#fff;color:#4b607c;font-size:.7rem;cursor:pointer}
.product-ai-checks input{width:16px;height:16px;flex:0 0 16px;accent-color:#2868c8}
.product-ai-footer{display:flex;align-items:center;gap:8px;color:#788ba3;font-size:.65rem}
.product-ai-runtime{display:grid;gap:12px;padding:14px;border:1px solid #d9e6f5;border-radius:13px;background:#fff}
.product-ai-runtime-head{display:flex;align-items:center;justify-content:space-between;gap:14px}
.product-ai-runtime-head>div{display:grid;gap:4px}
.product-ai-runtime-head>div>span{font-size:.64rem;color:#3474c7;font-weight:900}
.product-ai-runtime-head>div>strong{font-size:.82rem;color:#29435f}
.product-ai-runtime-head>div>small,.product-ai-result-meta{font-size:.64rem;line-height:1.8;color:#7b8ba0}
.product-ai-runtime-head form{margin:0;flex:0 0 auto}
.product-ai-runtime-head button{white-space:nowrap}
.product-ai-no-result{margin:0;color:#71839b;font-size:.69rem;line-height:1.9}
.product-ai-result{display:grid;gap:10px;padding-top:12px;border-top:1px solid #e7edf5}
.product-ai-result-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
.product-ai-result-top strong{font-size:.72rem;padding:6px 10px;border-radius:8px;background:#f2f6fb}
.product-ai-result-top .verdict-approved{color:#18764a;background:#eaf7ef}
.product-ai-result-top .verdict-needs_changes{color:#9a5b0b;background:#fff4e7}
.product-ai-result-top .verdict-blocked{color:#a52d2d;background:#fff0f0}
.product-ai-result-top span{font-size:.68rem;color:#506782;font-weight:800}
.product-ai-result>p{margin:0;color:#405875;font-size:.72rem;line-height:1.9}
.product-ai-findings{display:grid;gap:8px}
.product-ai-findings article{padding:10px;border:1px solid #e5ebf3;border-radius:10px;background:#fcfdff}
.product-ai-findings article>div{display:flex;justify-content:space-between;gap:8px}
.product-ai-findings b{font-size:.7rem;color:#334b68}
.product-ai-findings article>div span{font-size:.62rem;color:#71839b}
.product-ai-findings p{margin:6px 0;color:#5b6f88;font-size:.67rem;line-height:1.8}
.product-ai-findings small{font-size:.65rem;color:#3474a6;line-height:1.8}
.product-ai-findings .severity-critical{border-color:#f0caca;background:#fffafa}
.product-ai-findings .severity-warning{border-color:#f0dfc2;background:#fffdf8}
@media(max-width:600px){.product-ai-runtime-head{align-items:stretch;flex-direction:column}.product-ai-runtime-head button{width:100%}.product-ai-result-top{align-items:flex-start;flex-direction:column}}

.product-ai-footer i{color:#4a83c9}
@media(max-width:760px){.product-ai-supervisor-head{align-items:flex-start;flex-wrap:wrap}.product-ai-switch{width:100%;padding-inline-start:57px}.product-ai-checks{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:480px){.product-ai-checks{grid-template-columns:1fr}.product-ai-supervisor-head,.product-ai-supervisor-body{padding:13px}}
.product-search-preview{grid-column:1/-1;display:grid;gap:6px;padding:15px 17px;border:1px solid #dfe7f1;border-radius:13px;background:#fff;box-shadow:0 3px 12px rgba(15,35,70,.04)}
.product-search-preview>span{font-size:.64rem;font-weight:900;color:#73849b}.product-search-preview strong{font-size:.9rem;line-height:1.6;color:#1a5bb8}.product-search-preview small{font-size:.65rem;color:#18804c;direction:ltr;text-align:right;overflow-wrap:anywhere}.product-search-preview p{margin:0;font-size:.72rem;line-height:1.8;color:#66768b}.product-inline-feedback{margin:0 0 8px;color:#18764a;font-size:.7rem}.product-inline-feedback:empty{display:none}
.product-gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:10px;margin:14px 0}
.product-gallery-item{overflow:hidden;border:1px solid var(--st-line);border-radius:12px;background:#fff}
.product-gallery-item img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:#f4f6fa}
.product-gallery-item>div{display:flex;align-items:center;justify-content:space-between;gap:6px;padding:8px}
.product-gallery-item small{font-size:.62rem;color:var(--st-muted);overflow-wrap:anywhere}
.product-gallery-item form{margin:0}
.product-gallery-panel{margin-top:16px}.product-file-row{flex-wrap:wrap}.product-asset-review{grid-column:1/-1;flex-basis:100%;padding:9px;border-radius:9px;background:#f5f8fc;border:1px solid #e2eaf4}.product-asset-review b{font-size:.68rem}.product-asset-review p{font-size:.68rem;line-height:1.8;margin:5px 0}.product-asset-review small{font-size:.62rem;color:var(--st-muted)}.product-asset-review-list,.product-asset-review-warnings{margin:7px 0 0;padding-right:18px;font-size:.65rem;line-height:1.7}.product-asset-review-list li{margin:4px 0}.product-asset-review-list span,.product-asset-review-list small{display:block}.product-asset-review-warnings{color:#9b5b17}

.product-discovery-links{padding-top:4px}.product-discovery-links select{min-height:118px}.product-editor{max-width:1080px}.product-editor .store-section-head p{color:var(--st-muted);font-size:.8rem;margin:5px 0}.product-editor-form{padding:24px;display:grid;gap:18px}.product-editor-heading{display:flex;justify-content:space-between;align-items:center;gap:10px;border-bottom:1px solid var(--st-line);padding-bottom:14px}.product-editor-heading span:first-child{color:var(--st-blue);font-size:.7rem;font-weight:800}.product-editor-heading h2{font-size:1.02rem;margin:4px 0 0}.product-editor-step{font-size:.7rem;color:var(--st-muted);background:#f2f6fb;border-radius:99px;padding:7px 11px}.product-editor-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.product-field{display:grid;gap:7px;font-size:.78rem;font-weight:700;color:#40536d}.product-field input,.product-field select,.product-field textarea,.product-category-panel input,.product-file-upload input[type=text],.product-file-upload input[type=number]{width:100%;min-width:0;box-sizing:border-box;padding:11px 12px;border:1px solid var(--st-line);border-radius:10px;background:#fff;color:var(--st-ink);font:inherit;font-size:.8rem}.product-field small,.product-help{font-size:.67rem;color:var(--st-muted);font-weight:400}.product-field-wide{grid-column:1/-1}.product-field-check{display:flex;align-items:center;gap:9px}.product-editor-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.product-editor-actions span,.product-files-panel>p{font-size:.72rem;color:var(--st-muted)}.product-next-step{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:16px}.product-next-step strong{font-size:.88rem}.product-next-step p{margin:5px 0 0;color:var(--st-muted);font-size:.75rem}.product-files-panel{margin-top:16px}.product-files-panel>p{margin:10px 0 14px}.product-file-list{display:grid;gap:8px;margin-bottom:16px}.product-file-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px;border:1px solid var(--st-line);border-radius:10px}.product-file-row>span{display:grid;grid-template-columns:auto 1fr;gap:3px 9px;align-items:center}.product-file-row>span i{grid-row:span 2;color:var(--st-blue)}.product-file-row b{font-size:.75rem}.product-file-row small{font-size:.66rem;color:var(--st-muted)}.product-danger{border:0;background:#fff1f1;color:#b72e2e;padding:7px 11px;border-radius:8px;cursor:pointer;font:inherit;font-size:.7rem}.product-file-upload,.product-category-panel form{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.product-file-upload input[type=file]{max-width:100%;font-size:.72rem}.product-file-upload label{display:flex;align-items:center;gap:6px;font-size:.72rem}.product-help{display:block;margin-top:9px}.product-category-panel{margin-top:16px}.product-category-panel h2{font-size:.9rem}.product-category-panel input{width:auto;min-width:180px}.course-empty{padding:18px;border:1px dashed var(--st-line);border-radius:10px;text-align:center;color:var(--st-muted);font-size:.75rem}@media(max-width:700px){.product-editor-form{padding:15px}.product-editor-grid{grid-template-columns:1fr}.product-field-wide{grid-column:auto}.product-next-step{align-items:flex-start;flex-direction:column}.product-file-row{align-items:flex-start}}
</style>
@endpush

@push('scripts')
<script>
(() => {
  const form = document.getElementById('productEditorForm');
  if (!form) return;
  const price = form.querySelector('[name="price_rials"]');
  const pricePreview = form.querySelector('[data-price-preview]');
  const updatePrice = () => {
    if (!pricePreview || !price) return;
    const raw = Number(price.value || 0);
    const toman = Math.floor(raw / 10);
    const formatter = new Intl.NumberFormat('fa-IR');
    let label = 'معادل ' + formatter.format(toman) + ' تومان';
    const compare = Number(form.querySelector('[name="compare_at_price_rials"]')?.value || 0);
    if (compare > raw && raw >= 0) {
      const saving = Math.floor((compare - raw) / 10);
      const percent = Math.round(((compare - raw) / compare) * 100);
      label += ' · ' + formatter.format(percent) + '٪ تخفیف · ' + formatter.format(saving) + ' تومان صرفه‌جویی';
    }
    pricePreview.textContent = label;
  };
  price?.addEventListener('input', updatePrice);
  form.querySelector('[name="compare_at_price_rials"]')?.addEventListener('input', updatePrice);
  updatePrice();

  document.querySelectorAll('input[type="file"]').forEach(input => {
    input.addEventListener('change', () => {
      const output = input.closest('form')?.querySelector('[data-file-name]');
      if (!output) return;
      const file = input.files?.[0];
      output.textContent = file
        ? file.name + ' · ' + new Intl.NumberFormat('fa-IR').format(Math.ceil(file.size / 1024)) + ' کیلوبایت'
        : 'فایلی انتخاب نشده است.';
    });
  });

  const bindPreview = (selector, outputSelector, fallback) => {
    const input = form.querySelector(selector);
    const output = document.querySelector(outputSelector);
    if (!input || !output) return;
    const render = () => { output.textContent = input.value.trim() || fallback; };
    input.addEventListener('input', render);
    render();
  };
  const titleInput = form.querySelector('[data-product-title]');
  const seoTitleInput = form.querySelector('[data-seo-title]');
  const seoTitleOutput = document.querySelector('[data-seo-preview-title]');
  bindPreview('[data-seo-title]', '[data-seo-preview-title]', titleInput?.value || 'عنوان محصول شما');
  if (titleInput && seoTitleInput && seoTitleOutput) {
    titleInput.addEventListener('input', () => {
      if (!seoTitleInput.value.trim()) seoTitleOutput.textContent = titleInput.value.trim() || 'عنوان محصول شما';
    });
  }
  const shortDescriptionInput = form.querySelector('[data-product-short-description]');
  bindPreview('[data-seo-description]', '[data-seo-preview-description]', shortDescriptionInput?.value || 'توضیحات محصول شما در این قسمت نمایش داده می‌شود.');
  const slugInput = form.querySelector('[data-product-slug]');
  const slugOutput = document.querySelector('[data-seo-preview-url]');
  if (slugInput && slugOutput) {
    const renderSlug = () => { slugOutput.textContent = window.location.origin + '/store/product/' + (slugInput.value.trim() || 'product-url'); };
    slugInput.addEventListener('input', renderSlug);
    renderSlug();
  }
  const shortDescription = form.querySelector('[data-product-short-description]');
  const seoDescription = form.querySelector('[data-seo-description]');
  if (shortDescription && seoDescription) shortDescription.addEventListener('input', () => {
    if (!seoDescription.value.trim()) { const output = document.querySelector('[data-seo-preview-description]'); if (output) output.textContent = shortDescription.value.trim() || 'توضیحات محصول شما در این قسمت نمایش داده می‌شود.'; }
  });
  document.querySelectorAll('[data-inline-create]').forEach(inlineForm => {
    inlineForm.addEventListener('submit', async event => {
      event.preventDefault();
      const button = inlineForm.querySelector('button[type="submit"]');
      const feedback = inlineForm.closest('.product-category-panel')?.querySelector('[data-inline-feedback]');
      if (button?.disabled) return;
      if (button) { button.disabled = true; button.dataset.originalText = button.textContent; button.textContent = 'در حال ثبت…'; }
      if (feedback) feedback.textContent = '';
      try {
        const response = await fetch(inlineForm.action, {method:'POST',body:new FormData(inlineForm),headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message || Object.values(payload.errors || {}).flat()[0] || 'ثبت مورد جدید انجام نشد.');
        const kind = inlineForm.dataset.inlineCreate;
        const select = kind === 'category' ? form.querySelector('[name="category_id"]') : form.querySelector('[name="tag_ids[]"]');
        if (select) { const option = new Option(payload.name,payload.id,true,true); select.add(option); option.selected = true; }
        if (feedback) feedback.textContent = kind === 'category' ? 'دسته ساخته و برای همین محصول انتخاب شد.' : 'برچسب ساخته و به همین محصول اختصاص یافت.';
        inlineForm.reset();
      } catch (error) { if (feedback) feedback.textContent = error.message || 'خطا در ارتباط با سرور.'; }
      finally { if (button) { button.disabled = false; button.textContent = button.dataset.originalText || 'ثبت'; } }
    });
  });
  form.addEventListener('submit', event => {
    const button = document.getElementById('productSaveButton');
    if (button?.dataset.submitting === '1') {
      event.preventDefault();
      return;
    }
    if (!form.reportValidity()) return;
    if (button) {
      button.dataset.submitting = '1';
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      button.textContent = 'در حال ذخیره اطلاعات…';
    }
  });
})();
</script>
@endpush
