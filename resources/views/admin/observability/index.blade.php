@extends('layouts.admin')

@section('admin-content')
<div class="farast-control-page" dir="rtl">
    <header>
        <div>
            <span class="eyebrow">OPERATIONS / OBSERVABILITY</span>
            <h1>سلامت و مشاهده‌پذیری سامانه</h1>
            <p>وضعیت لحظه‌ای اجزای قابل بررسی از داخل برنامه؛ این صفحه جایگزین پایش زیرساخت میزبان و هشدار بیرونی نیست.</p>
        </div>
        <a href="{{ route('admin.index') }}">مرکز عملیات</a>
    </header>

    <section class="control-card">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <strong>وضعیت کلی:
                    @switch($health['status'])
                        @case('healthy') سالم @break
                        @case('critical') بحرانی @break
                        @default نیازمند بررسی
                    @endswitch
                </strong>
                <p>محیط: {{ $health['environment'] }} · زمان بررسی: {{ $health['checked_at'] }}</p>
            </div>
            <span>Observability: {{ $health['observability_enabled'] ? 'فعال' : 'غیرفعال' }}</span>
        </div>
    </section>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach($health['components'] as $key => $component)
            <section class="control-card">
                <div class="flex items-center justify-between gap-3">
                    <h2>
                        @switch($key)
                            @case('database') پایگاه داده @break
                            @case('queue') صف و پردازش پس‌زمینه @break
                            @case('storage') ذخیره‌سازی @break
                            @case('product_services') سرویس‌های پردازش محصول و صوت @break
                            @default ثبت لاگ
                        @endswitch
                    </h2>
                    <strong>{{ $component['status'] }}</strong>
                </div>
                @if(!empty($component['message']))
                    <p>{{ $component['message'] }}</p>
                @endif
                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                    @foreach($component as $field => $value)
                        @continue(in_array($field, ['status', 'message'], true) || is_array($value))
                        <dt class="text-slate-500">{{ $field }}</dt>
                        <dd class="break-all">{{ is_bool($value) ? ($value ? 'بله' : 'خیر') : ($value ?? 'نامشخص') }}</dd>
                    @endforeach
                </dl>
                @if(is_array($component['volumes'] ?? null))
                    <p class="mt-3 text-sm">Volumeها: {{ $component['volumes']['healthy'] }} سالم از {{ $component['volumes']['total'] }} · غیرسالم/تأییدنشده: {{ $component['volumes']['unhealthy_or_unverified'] }}</p>
                @endif

                @if(is_array($component['services'] ?? null))
                    <div class="mt-3 space-y-2 text-sm">
                        @foreach($component['services'] as $serviceKey => $service)
                            <div class="flex items-center justify-between gap-3">
                                <span>{{ $serviceKey === 'supervisor' ? 'ناظر پردازش محصول' : 'پردازش صوت اسناد' }}</span>
                                <span>{{ $service['status'] ?? 'unknown' }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach
    </div>

    <section class="control-card mt-4">
        <h2>محدودیت‌های این نما</h2>
        <ul class="list-disc space-y-1 pr-5">
            <li>این صفحه فقط وضعیت قابل مشاهده از فرایند Laravel و دیتابیس را گزارش می‌کند؛ سلامت واقعی میزبان، CPU، حافظه و شبکه باید از مانیتورینگ زیرساخت دریافت شود.</li>
            <li>شمارش خطاها از روی فایل لاگ انجام نمی‌شود؛ برای نرخ خطا و هشدار عملیاتی به مقصد مرکزی Metrics/Alerting نیاز است.</li>
            <li>مقادیر صف و Storage شاخص‌های لحظه بررسی هستند و جایگزین آزمون بازیابی یا بررسی Provider نیستند.</li>
        </ul>
    </section>
</div>
@endsection
