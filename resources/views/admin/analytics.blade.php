@extends('layouts.app')
@section('content')
<div class="farast-control-page" dir="rtl"><header><div><span class="eyebrow">REAL DATA ANALYTICS</span><h1>تحلیل و سلامت سرویس</h1><p>شاخص‌ها مستقیماً از دیتابیس خوانده می‌شوند؛ نمودار ساختگی تولید نشده است.</p></div><a href="{{ route('admin.index') }}">مرکز عملیات</a></header>
<section class="control-metrics">@foreach($stats as $k=>$v)<article><small>{{ $k }}</small><strong>{{ is_numeric($v)?number_format($v):$v }}</strong></article>@endforeach</section>
<section class="control-card"><h2>وضعیت Providerهای صوتی</h2><div class="table-wrap"><table><thead><tr><th>Provider</th><th>مدل</th><th>فعال</th><th>سلامت</th><th>مصرف</th><th>Latency</th></tr></thead><tbody>@foreach($providers as $p)<tr><td>{{ $p->provider }}</td><td>{{ $p->model }}</td><td>{{ $p->enabled?'بله':'خیر' }}</td><td>{{ $p->healthy?'OK':'FAIL' }}</td><td>{{ number_format($p->quota_used_seconds) }} / {{ $p->quota_limit_seconds===null?'∞':number_format($p->quota_limit_seconds) }}</td><td>{{ $p->last_latency_ms?number_format($p->last_latency_ms).' ms':'—' }}</td></tr>@endforeach</tbody></table></div></section>
</div>
@endsection