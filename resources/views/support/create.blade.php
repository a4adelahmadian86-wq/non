@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=20261018create2">

<div class="tk-page" dir="rtl">
    <nav class="tk-breadcrumb">
        <div class="tk-breadcrumb-inner">
            <a href="{{ route('support.create.form') }}" class="tk-crumb-strong">ایجاد تیکت</a>
            <span class="tk-crumb-sep"></span>
            <a href="{{ route('dashboard') }}" class="tk-crumb-icon" title="داشبورد"><i class="fa-solid fa-house"></i></a>
            <span class="tk-crumb-dash">-</span>
            <a href="{{ route('support') }}">تیکت ها</a>
            <span class="tk-crumb-dash">-</span>
            <a href="{{ route('support.create.form') }}">ایجاد تیکت</a>
        </div>
    </nav>

    <main class="tk-main">
        <article class="tk-card">
            <div class="tk-card-head">
                <hgroup>
                    <h1>ایجاد تیکت</h1>
                    <p>سؤالی دارید یا به کمک نیاز دارید؟ همین حالا تیکت ارسال کنید. ما کنارتون هستیم!</p>
                </hgroup>
                <a href="{{ route('support') }}" class="tk-btn-primary">تیکت های من</a>
            </div>

            <div class="tk-divider"></div>

            <form method="post" action="{{ route('support.create') }}" enctype="multipart/form-data" class="tk-create-form" id="createTicketForm">
                @csrf

                <div class="tk-row-2">
                    <div class="tk-field-block">
                        <p class="tk-label">دپارتمان</p>
                        <select name="department" id="department" required class="tk-select">
                            <option value="" disabled @selected(!old('department'))>دپارتمان را انتخاب کنید</option>
                            <option value="support" @selected(old('department')==='support')>بخش پشتیبانی</option>
                            <option value="sales" @selected(old('department')==='sales')>بخش فروش</option>
                            <option value="billing" @selected(old('department')==='billing')>بخش مالی</option>
                            <option value="complaint" @selected(old('department')==='complaint')>بخش شکایات</option>
                        </select>
                        @error('department')<small class="tk-error">{{ $message }}</small>@enderror
                    </div>
                    <div class="tk-field-block">
                        <p class="tk-label">اولویت</p>
                        <select name="priority" id="priority" required class="tk-select">
                            <option value="" disabled @selected(!old('priority'))>یک مورد را انتخاب کنید</option>
                            <option value="low" @selected(old('priority')==='low')>کم</option>
                            <option value="normal" @selected(old('priority', 'normal')==='normal')>متوسط</option>
                            <option value="high" @selected(old('priority')==='high')>زیاد</option>
                        </select>
                        @error('priority')<small class="tk-error">{{ $message }}</small>@enderror
                    </div>
                </div>

                {{-- حوزه موضوع: خدمات / فروشگاه (اجباری) --}}
                <div class="tk-field-block">
                    <p class="tk-label">حوزه موضوع <span class="tk-req">*</span></p>
                    <div class="tk-chips" id="topicAreaChips">
                        <label class="tk-chip">
                            <input type="radio" name="topic_area" value="services" required @checked(old('topic_area')==='services')>
                            <span>خدمات</span>
                        </label>
                        <label class="tk-chip">
                            <input type="radio" name="topic_area" value="store" required @checked(old('topic_area')==='store')>
                            <span>فروشگاه</span>
                        </label>
                    </div>
                    @error('topic_area')<small class="tk-error">{{ $message }}</small>@enderror
                </div>

                {{-- زیر‌بخش خدمات --}}
                <div class="tk-field-block" id="servicesBlock" hidden>
                    <p class="tk-label">نوع خدمت <span class="tk-req">*</span></p>
                    <div class="tk-chips tk-chips-wrap" id="serviceChips">
                        @foreach($services as $key => $label)
                            <label class="tk-chip">
                                <input type="radio" name="related_service" value="{{ $key }}" @checked(old('related_service')===$key)>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('related_service')<small class="tk-error">{{ $message }}</small>@enderror
                </div>

                {{-- زیر‌بخش فروشگاه --}}
                <div class="tk-field-block" id="storeBlock" hidden>
                    <p class="tk-label">محصول مرتبط <span class="tk-req">*</span></p>

                    @if($purchasedProducts->isNotEmpty())
                        <p class="tk-hint">آخرین محصولات خریداری‌شده شما:</p>
                        <div class="tk-chips tk-chips-wrap" id="purchasedChips">
                            @foreach($purchasedProducts as $p)
                                <label class="tk-chip">
                                    <input type="radio" name="related_product_id" value="{{ $p->id }}" @checked((string)old('related_product_id')===(string)$p->id)>
                                    <span>{{ \Illuminate\Support\Str::limit($p->title, 28) }}</span>
                                </label>
                            @endforeach
                        </div>
                    @else
                        <p class="tk-hint">هنوز محصولی خریداری نکرده‌اید. از فهرست زیر یک محصول را جستجو و انتخاب کنید:</p>
                        <div class="tk-product-search">
                            <input type="search" id="productSearch" placeholder="جستجوی محصول..." autocomplete="off">
                        </div>
                        <div class="tk-chips tk-chips-wrap" id="catalogChips">
                            @forelse($catalogProducts as $p)
                                <label class="tk-chip tk-product-chip" data-title="{{ e(mb_strtolower($p->title)) }}">
                                    <input type="radio" name="related_product_id" value="{{ $p->id }}" @checked((string)old('related_product_id')===(string)$p->id)>
                                    <span>{{ \Illuminate\Support\Str::limit($p->title, 28) }}</span>
                                </label>
                            @empty
                                <p class="tk-hint">محصولی در فروشگاه ثبت نشده است.</p>
                            @endforelse
                        </div>
                    @endif

                    @if($purchasedProducts->isNotEmpty() && $catalogProducts->isNotEmpty())
                        <details class="tk-more-products">
                            <summary>انتخاب از سایر محصولات فروشگاه</summary>
                            <div class="tk-product-search" style="margin-top:10px">
                                <input type="search" id="productSearchExtra" placeholder="جستجوی محصول..." autocomplete="off">
                            </div>
                            <div class="tk-chips tk-chips-wrap" id="catalogChipsExtra">
                                @foreach($catalogProducts as $p)
                                    <label class="tk-chip tk-product-chip" data-title="{{ e(mb_strtolower($p->title)) }}">
                                        <input type="radio" name="related_product_id" value="{{ $p->id }}" @checked((string)old('related_product_id')===(string)$p->id)>
                                        <span>{{ \Illuminate\Support\Str::limit($p->title, 28) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </details>
                    @endif

                    @error('related_product_id')<small class="tk-error">{{ $message }}</small>@enderror
                </div>

                <div class="tk-field-block">
                    <p class="tk-label">عنوان تیکت</p>
                    <input type="text" name="subject" required maxlength="160" value="{{ old('subject') }}" placeholder="عنوان تیکت را وارد کنید" class="tk-input">
                    @error('subject')<small class="tk-error">{{ $message }}</small>@enderror
                </div>

                <div class="tk-field-block">
                    <p class="tk-label">شرح پیام</p>
                    <textarea name="body" required maxlength="5000" rows="6" placeholder="پیام خود را تایپ کنید." class="tk-textarea">{{ old('body') }}</textarea>
                    @error('body')<small class="tk-error">{{ $message }}</small>@enderror
                </div>

                <div class="tk-file-row">
                    <input type="file" name="attachment" id="ticketFile" accept=".txt,.jpg,.jpeg,.pdf,.png,text/plain,image/jpeg,image/png,application/pdf" class="tk-file-input" hidden>
                    <p class="tk-file-label grow">افزودن فایل</p>
                    <span class="tk-file-sep"></span>
                    <button type="button" class="tk-file-pick" id="pickFileBtn">انتخاب فایل</button>
                </div>
                <p class="tk-hint" id="fileNameHint">فرمت‌های مجاز: TXT، JPG، JPEG، PDF، PNG</p>

                <div class="tk-divider-soft"></div>

                <button type="submit" class="tk-btn-primary tk-submit" id="submitTicketBtn">ثبت تیکت</button>
            </form>
        </article>
    </main>
</div>

@push('scripts')
<script>
(() => {
  const servicesBlock = document.getElementById('servicesBlock');
  const storeBlock = document.getElementById('storeBlock');
  const form = document.getElementById('createTicketForm');

  function syncTopic() {
    const val = form?.querySelector('input[name="topic_area"]:checked')?.value;
    if (servicesBlock) servicesBlock.hidden = val !== 'services';
    if (storeBlock) storeBlock.hidden = val !== 'store';

    // disable hidden required radios to avoid form validation issues
    form?.querySelectorAll('input[name="related_service"]').forEach((el) => {
      el.required = val === 'services';
      if (val !== 'services') el.checked = false;
    });
    form?.querySelectorAll('input[name="related_product_id"]').forEach((el) => {
      el.required = val === 'store';
      if (val !== 'store') el.checked = false;
    });
  }

  form?.querySelectorAll('input[name="topic_area"]').forEach((el) => {
    el.addEventListener('change', syncTopic);
  });
  syncTopic();

  // chip active style
  form?.querySelectorAll('.tk-chip input').forEach((input) => {
    input.addEventListener('change', () => {
      const name = input.name;
      form.querySelectorAll(`input[name="${name}"]`).forEach((i) => {
        i.closest('.tk-chip')?.classList.toggle('is-on', i.checked);
      });
    });
    if (input.checked) input.closest('.tk-chip')?.classList.add('is-on');
  });

  function bindSearch(inputId, containerId) {
    const input = document.getElementById(inputId);
    const box = document.getElementById(containerId);
    if (!input || !box) return;
    input.addEventListener('input', () => {
      const q = (input.value || '').trim().toLowerCase();
      box.querySelectorAll('.tk-product-chip').forEach((chip) => {
        const t = chip.getAttribute('data-title') || '';
        chip.style.display = !q || t.includes(q) ? '' : 'none';
      });
    });
  }
  bindSearch('productSearch', 'catalogChips');
  bindSearch('productSearchExtra', 'catalogChipsExtra');

  const fileInput = document.getElementById('ticketFile');
  const pickBtn = document.getElementById('pickFileBtn');
  const fileHint = document.getElementById('fileNameHint');
  pickBtn?.addEventListener('click', () => fileInput?.click());
  document.querySelector('.tk-file-row')?.addEventListener('click', (e) => {
    if (e.target === pickBtn) return;
    fileInput?.click();
  });
  fileInput?.addEventListener('change', () => {
    if (fileInput.files?.[0] && fileHint) {
      fileHint.textContent = 'فایل انتخاب‌شده: ' + fileInput.files[0].name;
    }
  });
})();
</script>
@endpush
@endsection
