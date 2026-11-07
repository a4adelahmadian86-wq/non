@extends('layouts.app')
@section('content')
<div class="container" dir="rtl">
    <h1>گردش‌کار و اتوماسیون</h1>
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($canManageWorkflows)
    <div class="card mb-4">
        <div class="card-body">
            <h2>ساخت گردش‌کار</h2>
            <form method="post" action="{{ route('platform.workflows.save') }}">
                @csrf
                <div class="mb-3">
                    <label for="workflow-name">نام گردش‌کار</label>
                    <input id="workflow-name" class="form-control" name="name" maxlength="160" required>
                </div>
                <div class="mb-3">
                    <label for="workflow-trigger">رویداد آغاز</label>
                    <select id="workflow-trigger" class="form-select" name="trigger_type" required>
                        <option value="document.saved">پس از ذخیره سند</option>
                        <option value="ticket.created">پس از ثبت تیکت</option>
                        <option value="order.paid">پس از پرداخت موفق سفارش</option>
                        <option value="order.deposit_paid">پس از پرداخت پیش‌پرداخت تایپ</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="workflow-action">اقدام</label>
                    <select id="workflow-action" class="form-select" name="steps[0][action]">
                        <option value="notify">ارسال اعلان به صاحب گردش‌کار</option>
                        <option value="create_follow_up">ساخت وظیفه پیگیری CRM</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="workflow-title">عنوان اعلان / وظیفه</label>
                    <input id="workflow-title" class="form-control" name="steps[0][title]" maxlength="180">
                </div>
                <div class="mb-3">
                    <label for="workflow-message">متن اعلان</label>
                    <textarea id="workflow-message" class="form-control" name="steps[0][message]" rows="2" maxlength="5000"></textarea>
                </div>
                <button class="btn btn-primary" type="submit">ذخیره گردش‌کار</button>
            </form>
        </div>
    </div>
    @endif
    <h2>گردش‌کارهای من</h2>
    <ul class="list-group">
        @forelse($items as $w)
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span>{{ $w->name }} — {{ $w->enabled ? 'فعال' : 'خاموش' }} ({{ $w->trigger_type }})</span>
                @if($canManageWorkflows)<form method="post" action="{{ route('platform.workflows.run', $w->id) }}">@csrf<button class="btn btn-outline-primary btn-sm" type="submit">اجرای دستی</button></form>@else<span>فقط مشاهده</span>@endif
            </li>
        @empty
            <li class="list-group-item">هنوز گردش‌کاری ثبت نشده است.</li>
        @endforelse
    </ul>
</div>
@endsection
