@extends('layouts.app')
@section('content')
<div class="farast-admin farast-admin-workspace" dir="rtl">
    @include('admin.partials.sidebar')
    <main class="farast-admin-main">
        @yield('admin-content')
    </main>
</div>
@endsection
