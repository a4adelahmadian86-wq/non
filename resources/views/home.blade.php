@extends('layouts.app')

@php
    $title = 'فراست | چه کاری می‌خواهید انجام دهید؟';
    $isAuthenticated = auth()->check();
@endphp

@push('head')
<meta name="description" content="فراست ابتدا هدف شما را می‌فهمد، فقط سؤال‌های لازم را می‌پرسد و سپس پروژه را در محیط کاری مناسب باز می‌کند.">
<link rel="canonical" href="{{ url('/') }}">
@endpush

@section('content')
<div class="farast-home" dir="rtl" data-authenticated="{{ $isAuthenticated ? '1' : '0' }}">
    <section class="home-hero" aria-labelledby="home-title">
        <div class="home-hero-copy">
            <span class="home-kicker"><i class="fa-solid fa-sparkles" aria-hidden="true"></i> FARAST</span>
            <h1 id="home-title">چه کاری می‌خواهید انجام دهید؟</h1>
            <p>فقط مسیر را بگویید. فراست نیاز را ساختاربندی می‌کند، ابزار و workflow مناسب را انتخاب می‌کند و شما را مستقیم وارد محیط کار می‌کند.</p>
        </div>
        <div class="home-hero-rule" aria-hidden="true"></div>
    </section>

    <main class="home-discovery" id="homeDiscovery">
        <section class="discovery-panel" aria-labelledby="discovery-title">
            <div class="discovery-heading">
                <span class="home-kicker">شروع هوشمند</span>
                <h2 id="discovery-title">از کجا شروع می‌کنید؟</h2>
                <p id="discovery-help">یکی را انتخاب کنید؛ سؤال بعدی فقط وقتی نمایش داده می‌شود که روی تصمیم بعدی اثر داشته باشد.</p>
            </div>

            <div class="intent-grid" id="intentGrid">
                <button class="intent-card" type="button" data-intent="file">
                    <span class="intent-icon"><i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i></span>
                    <span><strong>فایل دارم</strong><small>PDF، تصویر یا اسکن دارم و می‌خواهم روی آن کار کنم.</small></span>
                </button>
                <button class="intent-card" type="button" data-intent="zero">
                    <span class="intent-icon"><i class="fa-solid fa-pen-ruler" aria-hidden="true"></i></span>
                    <span><strong>از صفر شروع می‌کنم</strong><small>می‌خواهم یک متن، مقاله، کتاب، رزومه، قرارداد یا فرم بسازم.</small></span>
                </button>
                <button class="intent-card" type="button" data-intent="service">
                    <span class="intent-icon"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i></span>
                    <span><strong>دنبال یک سرویس هستم</strong><small>OCR، تبدیل صوت به متن، ترجمه، ویرایش یا خروجی می‌خواهم.</small></span>
                </button>
                <button class="intent-card intent-card-muted" type="button" data-intent="unknown">
                    <span class="intent-icon"><i class="fa-solid fa-circle-question" aria-hidden="true"></i></span>
                    <span><strong>هنوز مطمئن نیستم</strong><small>با یک سؤال کوتاه مسیر مناسب را پیدا می‌کنیم.</small></span>
                </button>
            </div>

            <div class="discovery-step" id="discoveryStep" hidden>
                <button class="discovery-back" type="button" id="discoveryBack"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> بازگشت</button>
                <div class="step-content" id="stepContent"></div>
            </div>

            <div class="discovery-status" id="discoveryStatus" role="status" aria-live="polite"></div>
        </section>

        @if($isAuthenticated && $recentProjects->isNotEmpty())
        <section class="home-recent" aria-labelledby="recent-title">
            <div>
                <span class="home-kicker">ادامه کار</span>
                <h2 id="recent-title">پروژه‌های اخیر</h2>
            </div>
            <div class="recent-list">
                @foreach($recentProjects as $project)
                <a class="recent-item" href="{{ route('editor', ['project' => $project->id]) }}">
                    <span class="recent-file"><i class="fa-regular fa-file-lines" aria-hidden="true"></i></span>
                    <span><strong>{{ $project->name }}</strong><small>{{ $project->context['template'] ?? $project->template_code ?? 'سند' }}</small></span>
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                </a>
                @endforeach
            </div>
        </section>
        @endif
    </main>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/home.js') }}" defer></script>
@endpush
