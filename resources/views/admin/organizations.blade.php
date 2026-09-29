@extends('layouts.app')
@section('content')
<div class="farast-control-page" dir="rtl"><header><div><span class="eyebrow">ORGANIZATIONS / TEAMS</span><h1>سازمان‌ها و تیم‌ها</h1><p>ساختار سازمانی additive است و داده کاربران فعلی را حذف نمی‌کند.</p></div><a href="{{ route('admin.index') }}">مرکز عملیات</a></header>
<section class="control-card"><form class="inline-form" method="post" action="{{ route('admin.organizations.store') }}">@csrf<input name="name" placeholder="نام سازمان" required><input name="slug" placeholder="slug" required><button>ایجاد سازمان</button></form></section>
<section class="control-card"><div class="table-wrap"><table><thead><tr><th>سازمان</th><th>وضعیت</th><th>کاربران</th><th>تیم‌ها</th></tr></thead><tbody>@foreach($organizations as $o)<tr><td>{{ $o->name }}<small>{{ $o->slug }}</small></td><td>{{ $o->status }}</td><td>{{ $o->users_count }}</td><td>{{ $o->teams_count }}</td></tr>@endforeach</tbody></table></div>{{ $organizations->links() }}</section>
</div>
@endsection