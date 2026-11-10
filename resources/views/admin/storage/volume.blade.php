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
        <p>ظرفیت: {{ $volume->capacity_bytes ? \Illuminate\Support\Number::fileSize($volume->capacity_bytes) : 'نامشخص' }} · مصرف ثبت‌شده: {{ \Illuminate\Support\Number::fileSize($volume->used_bytes) }} · اشیای سرویس‌دهنده: {{ number_format($volume->object_count ?? $volume->files()->count()) }} · فایل‌های ثبت‌شده در سامانه: {{ number_format($volume->files()->count()) }}</p>
        <p>سقف تعداد فایل: {{ $volume->file_limit ? number_format($volume->file_limit) : 'اعلام نشده' }} · حداکثر حجم فایل: {{ $volume->max_file_bytes ? \Illuminate\Support\Number::fileSize($volume->max_file_bytes) : 'اعلام نشده' }} · اولویت تخصیص: {{ $volume->priority }}</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="post" action="{{ route('admin.storage.verify',$volume) }}">@csrf<button class="btn">آزمون اتصال</button></form>
            <form method="post" action="{{ route('admin.storage.toggle',$volume) }}">@csrf<button class="btn">{{ $volume->is_active ? 'غیرفعال‌سازی' : 'فعال‌سازی' }}</button></form>
            <form method="post" action="{{ route('admin.storage.destroy',$volume) }}" onsubmit="return confirm('این اتصال حذف شود؟')">@csrf @method('DELETE')<button class="btn">حذف اتصال</button></form>
        </div>
    </div>
    @if($volume->drive_key === 'foreign_backup' || $volume->drive_key === 'iran_backup')
    <div class="storage-detail-card">
        <h2>نسخه‌های پشتیبان این هاست</h2>
        <div style="overflow:auto"><table><thead><tr><th>شناسه</th><th>نوع</th><th>کلید نسخه</th><th>حجم</th><th>SHA-256</th><th>وضعیت</th><th>شروع</th><th>پایان</th></tr></thead><tbody>
        @forelse($backups as $backup)
            <tr><td>#{{ $backup->id }}</td><td>{{ $backup->backup_type }}</td><td><code>{{ $backup->object_key ?: '—' }}</code></td><td>{{ \Illuminate\Support\Number::fileSize($backup->size_bytes) }}</td><td><code>{{ $backup->checksum ?: '—' }}</code></td><td>{{ $backup->status }}</td><td>{{ optional($backup->started_at)->format('Y-m-d H:i') ?: '—' }}</td><td>{{ optional($backup->completed_at)->format('Y-m-d H:i') ?: '—' }}</td></tr>
        @empty<tr><td colspan="8">هنوز نسخه پشتیبانی روی این هاست ثبت نشده است.</td></tr>@endforelse
        </tbody></table></div>
    </div>
    @endif
    <div class="storage-detail-card">
        <h2>فایل‌های ثبت‌شده روی این هاست</h2>
        <div style="overflow:auto"><table><thead><tr><th>نام فایل</th><th>کلید فایل</th><th>حجم</th><th>نوع</th><th>MIME</th><th>منبع</th><th>شناسه مرتبط</th><th>SHA-256</th><th>وضعیت</th><th>تاریخ</th></tr></thead><tbody>
        @forelse($files as $file)<tr><td><a href="{{ route('admin.storage.files.download', $file) }}">{{ $file->original_name }}</a></td><td><code>{{ $file->file_key }}</code></td><td>{{ \Illuminate\Support\Number::fileSize($file->size_bytes) }}</td><td>{{ $file->classification }}</td><td>{{ $file->mime ?: '—' }}</td><td>{{ $file->source_module ?: '—' }}</td><td>@if($file->product_id)<a href="{{ route('admin.store.edit', $file->product_id) }}">محصول #{{ $file->product_id }}</a>@elseif($file->source_id){{ $file->source_module }} #{{ $file->source_id }}@else—@endif</td><td><code>{{ $file->checksum ?: '—' }}</code></td><td>{{ $file->status }}</td><td>{{ $file->created_at->format('Y-m-d H:i') }}</td></tr>@empty<tr><td colspan="10">هنوز فایلی از طریق هسته مدیریت ذخیره‌سازی در این فضا ثبت نشده است.</td></tr>@endforelse
        </tbody></table></div>
        {{ $files->links() }}
    </div>
</div>
@endsection