@extends('layouts.app')
@section('content')
<div class="farast-control-page" dir="rtl"><header><div><span class="eyebrow">RBAC</span><h1>نقش‌ها و مجوزها</h1><p>مجوزها سمت سرور ذخیره و برای نقش‌های سازمانی قابل کنترل هستند.</p></div><a href="{{ route('admin.index') }}">مرکز عملیات</a></header>
@foreach($roles as $role)<section class="control-card role-card"><div class="provider-head"><strong>{{ $role }}</strong><span>{{ $role==='admin'?'Full access':'قابل تنظیم' }}</span></div>
@if($role!=='admin')<form method="post" action="{{ route('admin.access.roles.update',$role) }}">@csrf<div class="permission-grid">@foreach($permissions as $p)<label><input type="checkbox" name="permissions[]" value="{{ $p->key }}" {{ $rolePermissions->where('role',$role)->contains('permission_id',$p->id)?'checked':'' }}><span>{{ $p->label }}</span><small>{{ $p->key }}</small></label>@endforeach</div><button type="submit">ذخیره مجوزهای {{ $role }}</button></form>@endif</section>@endforeach
</div>
@endsection