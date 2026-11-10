@extends('layouts.admin')

@section('admin-content')
<style>.backup-btn{padding:10px 15px;border:0;border-radius:10px;background:#245b9d;color:#fff;cursor:pointer;font-weight:600}.backup-note{color:#718096;font-size:13px}.backup-table{width:100%;border-collapse:collapse}.backup-table th,.backup-table td{text-align:right;padding:12px;border-bottom:1px solid #e8ecf2;font-size:13px;white-space:nowrap}.backup-table th{background:#f7f8fa;color:#697789}</style>
<div class="storage-detail" dir="rtl">
    <div class="storage-detail-card">
        <h1>تاریخچه پشتیبان‌گیری</h1>
        <p class="muted">گزارش اجرای بکاپ‌ها، مقصد، حجم، checksum و خطاها</p>
        <form method="post" action="{{ route('admin.storage.backups.run') }}" style="margin:14px 0">@csrf<button type="submit" class="backup-btn">اجرای بکاپ فوری</button></form>
        <p class="backup-note">اجرای دستی در صف اختصاصی بکاپ قرار می‌گیرد. زمان‌بندی خودکار هفتگی نیز از طریق زمان‌بند لاراول اجرا می‌شود؛ Worker صف backups باید فعال باشد.</p>
        <div style="overflow:auto"><table class="backup-table"><thead><tr><th>نوع بکاپ</th><th>مقصد</th><th>وضعیت</th><th>حجم</th><th>Checksum</th><th>شروع</th><th>پایان</th><th>خطا</th></tr></thead><tbody>
        @forelse($backups as $backup)
            <tr>
                <td>{{ $backup->backup_type }}</td><td>{{ $backup->volume?->name ?: 'ثبت نشده' }}</td>
                <td>{{ $backup->status }}</td><td>{{ \Illuminate\Support\Number::fileSize($backup->size_bytes) }}</td>
                <td><code>{{ $backup->checksum ?: '—' }}</code></td><td>{{ optional($backup->started_at)->format('Y-m-d H:i') ?: '—' }}</td>
                <td>{{ optional($backup->completed_at)->format('Y-m-d H:i') ?: '—' }}</td><td>{{ $backup->error_message ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="8">هنوز نسخه پشتیبانی ثبت نشده است. پس از اتصال حداقل یک هاست سالم در درایو بکاپ خارجی، اجرای زمان‌بندی‌شده و دستی امکان‌پذیر خواهد بود.</td></tr>
        @endforelse
        </tbody></table></div>
        {{ $backups->links() }}
    </div>
</div>
@endsection