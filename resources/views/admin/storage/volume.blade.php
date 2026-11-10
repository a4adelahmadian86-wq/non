@extends('layouts.admin')

@section('admin-content')
<style>
.storage-detail{display:grid;gap:18px}.storage-detail-card{padding:22px;border:1px solid var(--admin-border,#e5e9f0);border-radius:16px;background:var(--admin-card,#fff)}.storage-detail-head{display:flex;justify-content:space-between;gap:12px;align-items:center}.storage-detail table{width:100%;border-collapse:collapse}.storage-detail th,.storage-detail td{text-align:right;padding:12px;border-bottom:1px solid #e8ecf2;font-size:13px}.storage-detail th{color:#697789;background:#f7f8fa}.storage-detail .muted{color:#7b8798}.storage-detail .btn{padding:9px 13px;border-radius:9px;background:#245b9d;color:#fff;text-decoration:none;border:0;cursor:pointer}
</style>
<div class="storage-detail" dir="rtl">
    <div class="storage-detail-card">
        <a href="{{ route('admin.storage.index') }}">← بازگشت به درایوها</a>
        <div class="storage-detail-head"><div><h1>{{ $volume->name }}</h1><p class="muted">{{ $volume->provider ?: $volume->driver }} · {{ $volume->region ?: 'منطقه نامشخص' }}</p></div><span>{{ $volume->status }}</span></div>
        <p>شناسه: #{{ $volume->id }} · درایو: {{ $volume->drive_key }} · آخرین بررسی: {{ optional($volume->last_checked_at)->format('Y-m-d H:i') ?: 'انجام نشده' }}</p>
        <p>ظرفیت: {{ $volume->capacity_bytes ? \Illuminate\Support\Number::fileSize($volume->capacity_bytes) : 'نامشخص' }} · مصرف ثبت‌شده: {{ \Illuminate\Support\Number::fileSize($volume->used_bytes) }} · فایل‌ها: {{ number_format($volume->files()->where('status', 'active')->count()) }}</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="post" action="{{ route('admin.storage.verify',$volume) }}">@csrf<button class="btn">آزمون اتصال</button></form>
            <form method="post" action="{{ route('admin.storage.toggle',$volume) }}">@csrf<button class="btn">{{ $volume->is_active ? 'غیرفعال‌سازی' : 'فعال‌سازی' }}</button></form>
            <form method="post" action="{{ route('admin.storage.destroy',$volume) }}" onsubmit="return confirm('این اتصال حذف شود؟')">@csrf @method('DELETE')<button class="btn">حذف اتصال</button></form>
        </div>
    </div>
    <div class="storage-detail-card">
        <h2>فایل‌های ثبت‌شده روی این هاست</h2>
        <div style="overflow:auto"><table><thead><tr><th>نام فایل</th><th>حجم</th><th>نوع</th><th>منبع</th><th>شناسه مرتبط</th><th>وضعیت</th><th>تاریخ</th></tr></thead><tbody>
        @forelse($files as $file)<tr><td>{{ $file->original_name }}</td><td>{{ \Illuminate\Support\Number::fileSize($file->size_bytes) }}</td><td>{{ $file->classification }}</td><td>{{ $file->source_module ?: '—' }} / {{ $file->source_type ?: '—' }}</td><td>{{ $file->product_id ? 'محصول #'.$file->product_id : ($file->source_id ? 'مرجع #'.$file->source_id : '—') }}</td><td>{{ $file->status }}</td><td>{{ $file->created_at->format('Y-m-d H:i') }}</td></tr>@empty<tr><td colspan="7">هنوز فایلی از طریق هسته مدیریت ذخیره‌سازی در این فضا ثبت نشده است.</td></tr>@endforelse
        </tbody></table></div>
        {{ $files->links() }}
    </div>
</div>
@endsection