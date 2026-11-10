@extends('layouts.admin')

@section('admin-content')
<style>
.storage-page{display:grid;gap:22px;color:var(--admin-text,#172033)}.storage-hero{padding:26px;border-radius:22px;background:linear-gradient(120deg,#111c35,#243e68);color:#fff;display:flex;justify-content:space-between;gap:20px;align-items:center}.storage-hero h1{font-size:25px;margin:0 0 8px}.storage-hero p{margin:0;color:#d3def0}.storage-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.storage-drive{background:var(--admin-card,#fff);border:1px solid var(--admin-border,#e5e9f0);border-radius:18px;padding:20px;display:grid;gap:14px}.storage-drive-head{display:flex;align-items:center;justify-content:space-between}.storage-drive h2{font-size:17px;margin:0}.storage-drive small,.storage-muted{color:#7b8798}.storage-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.storage-metric{padding:12px;border-radius:12px;background:var(--admin-soft,#f5f7fb)}.storage-metric b{display:block;font-size:18px}.storage-progress{height:9px;background:#e9edf4;border-radius:20px;overflow:hidden}.storage-progress span{display:block;height:100%;background:#3485d8;border-radius:20px}.storage-volumes{display:grid;gap:8px}.storage-volume{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px;border:1px solid var(--admin-border,#e5e9f0);border-radius:12px;text-decoration:none;color:inherit}.storage-volume:hover{border-color:#6b9fe3}.storage-status{font-size:12px;border-radius:30px;padding:4px 9px;background:#f1f3f6;color:#596579}.storage-status.healthy{background:#e8f7ee;color:#167447}.storage-add{background:var(--admin-card,#fff);border:1px solid var(--admin-border,#e5e9f0);border-radius:18px;padding:22px}.storage-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.storage-form label{display:grid;gap:7px;font-size:13px}.storage-form input,.storage-form select,.storage-form textarea{width:100%;padding:11px;border:1px solid #dce2eb;border-radius:10px;background:var(--admin-input,#fff);color:inherit}.storage-form .wide{grid-column:1/-1}.storage-btn{border:0;border-radius:10px;padding:11px 16px;background:#245b9d;color:#fff;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px}.storage-actions{display:flex;gap:8px;flex-wrap:wrap}.storage-alert{padding:12px 15px;border-radius:12px;background:#fff5df;color:#805500}.storage-success{padding:12px 15px;border-radius:12px;background:#e9f8ef;color:#176b40}@media(max-width:850px){.storage-grid{grid-template-columns:1fr}.storage-form{grid-template-columns:1fr}.storage-metrics{grid-template-columns:repeat(3,1fr)}.storage-hero{display:block}}
</style>
<div class="storage-page">
    <section class="storage-hero">
        <div><h1>مرکز مدیریت فضای ذخیره‌سازی</h1><p>مدیریت درایوها، اتصال سرورها، ظرفیت، فایل‌ها و زیرساخت پشتیبان‌گیری</p></div>
        <div><span class="storage-status healthy">۴ درایو منطقی</span></div>
    </section>
    @if(session('status'))<div class="storage-success">{{ session('status') }}</div>@endif
    @if(session('warning'))<div class="storage-alert">{{ session('warning') }}</div>@endif
    @if($errors->any())<div class="storage-alert">{{ $errors->first() }}</div>@endif

    <section class="storage-grid">
        @foreach($drives as $drive)
            @php($percent = $drive['capacity'] > 0 ? min(100, round($drive['used'] / $drive['capacity'] * 100)) : 0)
            <article class="storage-drive">
                <div class="storage-drive-head"><div><h2>{{ $drive['title'] }}</h2><small>{{ $drive['region'] }} · {{ $drive['kind'] === 'backup' ? 'پشتیبان‌گیری' : 'ذخیره‌سازی عملیاتی' }}</small></div><span class="storage-status">{{ $drive['healthy'] }} از {{ $drive['volumes']->count() }} سالم</span></div>
                <div class="storage-metrics">
                    <div class="storage-metric"><small>ظرفیت کل</small><b>{{ $drive['capacity'] ? \Illuminate\Support\Number::fileSize($drive['capacity']) : 'نامشخص' }}</b></div>
                    <div class="storage-metric"><small>مصرف‌شده</small><b>{{ \Illuminate\Support\Number::fileSize($drive['used']) }}</b></div>
                    <div class="storage-metric"><small>تعداد فایل</small><b>{{ number_format($drive['files']) }}</b></div>
                </div>
                <div class="storage-progress"><span style="width:{{ $percent }}%"></span></div>
                <div class="storage-muted">فضای آزاد: {{ $drive['capacity'] ? \Illuminate\Support\Number::fileSize(max(0,$drive['capacity']-$drive['used'])) : 'ظرفیت اعلام نشده' }}</div>
                <div class="storage-volumes">
                    @forelse($drive['volumes'] as $volume)
                        <a class="storage-volume" href="{{ route('admin.storage.volume', $volume) }}">
                            <span><strong>{{ $volume->name }}</strong><br><small>{{ $volume->provider ?: $volume->driver }} · {{ number_format($volume->files_count) }} فایل</small></span>
                            <span class="storage-status {{ $volume->status === 'healthy' ? 'healthy' : '' }}">{{ $volume->status === 'healthy' ? 'سالم' : ($volume->status === 'error' ? 'خطا' : 'بررسی‌نشده') }}</span>
                        </a>
                    @empty
                        <div class="storage-muted">هنوز هاستی به این درایو اضافه نشده است.</div>
                    @endforelse
                </div>
            </article>
        @endforeach
    </section>

    <section class="storage-add">
        <h2>افزودن فضای ذخیره‌سازی</h2>
        <p class="storage-muted">برای اتصال S3-compatible، اطلاعات دسترسی را به‌صورت JSON وارد کنید. کلیدها در پایگاه داده رمزنگاری می‌شوند و در صفحه نمایش داده نخواهند شد.</p>
        <form method="post" action="{{ route('admin.storage.store') }}" class="storage-form">
            @csrf
            <label>درایو مقصد<select name="drive_key" required>@foreach($drives as $drive)<option value="{{ $drive['key'] }}">{{ $drive['title'] }}</option>@endforeach</select></label>
            <label>نام نمایشی هاست<input name="name" required maxlength="120" placeholder="مثلاً فضای ابری شماره ۱"></label>
            <label>نوع اتصال<select name="driver"><option value="s3">S3 Compatible</option><option value="local">فضای محلی برنامه (آزمایشی)</option></select></label>
            <label>نام ارائه‌دهنده<input name="provider" placeholder="نام شرکت هاستینگ"></label>
            <label>منطقه / کشور<input name="region" placeholder="مثلاً eu-central-1 یا ایران"></label>
            <label>آدرس Endpoint<input name="endpoint" type="url" placeholder="https://s3.example.com"></label>
            <label>نام Bucket<input name="bucket"></label>
            <label>ظرفیت اختصاص‌یافته (بایت)<input name="capacity_bytes" type="number" min="1" placeholder="در صورت مشخص بودن"></label>
            <label>حداکثر تعداد فایل<input name="file_limit" type="number" min="1"></label>
            <label>حداکثر حجم هر فایل (بایت)<input name="max_file_bytes" type="number" min="1"></label>
            <label>اولویت استفاده<input name="priority" type="number" min="1" max="10000" value="100"></label>
            <label class="wide">اطلاعات API / کلید اتصال (JSON)<textarea name="credentials_json" rows="5" placeholder='{"key":"ACCESS_KEY","secret":"SECRET_KEY","bucket":"bucket-name","region":"eu-central-1","endpoint":"https://s3.example.com"}'></textarea></label>
            <div class="wide"><button class="storage-btn" type="submit"><i class="fa-solid fa-plug"></i> بررسی اتصال و افزودن هاست</button></div>
        </form>
    </section>
</div>
@endsection