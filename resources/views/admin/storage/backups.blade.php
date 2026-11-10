@extends('layouts.admin')

@section('admin-content')
<div class="storage-detail" dir="rtl">
    <div class="storage-detail-card">
        <h1>تاریخچه پشتیبان‌گیری</h1>
        <p class="muted">گزارش اجرای بکاپ‌ها، مقصد، حجم، checksum و خطاها</p>
        <div style="overflow:auto"><table style="width:100%;border-collapse:collapse"><thead><tr><th>نوع بکاپ</th><th>مقصد</th><th>وضعیت</th><th>حجم</th><th>Checksum</th><th>شروع</th><th>پایان</th><th>خطا</th></tr></thead><tbody>
        @forelse($backups as $backup)
            <tr>
                <td>{{ $backup->backup_type }}</td><td>{{ $backup->volume?->name ?: 'ثبت نشده' }}</td>
                <td>{{ $backup->status }}</td><td>{{ \Illuminate\Support\Number::fileSize($backup->size_bytes) }}</td>
                <td><code>{{ $backup->checksum ?: '—' }}</code></td><td>{{ optional($backup->started_at)->format('Y-m-d H:i') ?: '—' }}</td>
                <td>{{ optional($backup->completed_at)->format('Y-m-d H:i') ?: '—' }}</td><td>{{ $backup->error_message ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="8">هنوز عملیات بکاپی ثبت نشده است. زمان‌بندی و موتور اجرای بکاپ در مرحله اتصال به مقصدها فعال خواهد شد.</td></tr>
        @endforelse
        </tbody></table></div>
        {{ $backups->links() }}
    </div>
</div>
@endsection