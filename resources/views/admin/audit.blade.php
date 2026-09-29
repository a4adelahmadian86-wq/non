@extends('layouts.app')
@section('content')
<div class="farast-control-page" dir="rtl"><header><div><span class="eyebrow">AUDIT</span><h1>لاگ ممیزی</h1><p>عملیات حساس مدیریتی و یکپارچه‌سازی‌ها با actor، IP و metadata ثبت می‌شوند.</p></div><a href="{{ route('admin.index') }}">مرکز عملیات</a></header>
<section class="control-card"><div class="table-wrap"><table><thead><tr><th>زمان</th><th>Actor</th><th>Action</th><th>IP</th><th>Metadata</th></tr></thead><tbody>@foreach($logs as $l)<tr><td>{{ $l->created_at }}</td><td>{{ $l->actor_name ?: $l->actor_mobile ?: 'سیستم' }}</td><td><code>{{ $l->action }}</code></td><td>{{ $l->ip ?: '—' }}</td><td><code>{{ json_encode($l->meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</code></td></tr>@endforeach</tbody></table></div>{{ $logs->links() }}</section>
</div>
@endsection