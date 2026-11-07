@extends('layouts.app')

@section('content')
<div class="container" dir="rtl">
    <h1>مدیریت پلن و سهمیه</h1>

    @if(session('status'))
        <div role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('admin.plans.save') }}">
        @csrf
        <label>کد پلن <input name="code" maxlength="120" required></label>
        <label>نام پلن <input name="name" maxlength="180" required></label>
        <label>قیمت (ریال) <input name="price" type="number" min="0" value="0" required></label>
        <label>واحد پول <input name="currency" value="IRR" maxlength="8" required></label>
        <label>دوره
            <select name="billing_interval">
                <option value="">بدون انقضای دوره‌ای</option>
                <option value="monthly">ماهانه</option>
                <option value="yearly">سالانه</option>
                <option value="one_time">یک‌باره</option>
            </select>
        </label>

        <fieldset>
            <legend>سهمیه‌ها</legend>
            <label>صفحات تایپ <input name="quotas[typing_pages]" type="number" min="0" value="0"></label>
            <label>درخواست هوش مصنوعی <input name="quotas[ai_requests]" type="number" min="0" value="0"></label>
            <label>صفحات OCR <input name="quotas[ocr_pages]" type="number" min="0" value="0"></label>
            <label>دقایق گفتار <input name="quotas[voice_minutes]" type="number" min="0" value="0"></label>
            <label>تعداد خروجی <input name="quotas[export_count]" type="number" min="0" value="0"></label>
        </fieldset>

        <input type="hidden" name="allow_payg" value="0">
        <label><input type="checkbox" name="allow_payg" value="1" checked> پرداخت به‌ازای مصرف مجاز</label>
        <input type="hidden" name="allow_overage" value="0">
        <label><input type="checkbox" name="allow_overage" value="1"> عبور از سهمیه مجاز</label>
        <input type="hidden" name="postpaid" value="0">
        <label><input type="checkbox" name="postpaid" value="1"> پس‌پرداخت</label>
        <input type="hidden" name="active" value="0">
        <label><input type="checkbox" name="active" value="1" checked> فعال</label>
        <button type="submit">ذخیره پلن تجاری</button>
    </form>

    <h2>کاتالوگ اصلی پلن‌ها</h2>
    <ul>
        @foreach($plans as $p)
            <li>
                {{ $p->code }} — {{ $p->name }} — {{ number_format((int)$p->price) }} {{ $p->currency }}
                · {{ $p->billing_interval ?: 'بدون دوره' }}
                · {{ $p->active ? 'فعال' : 'غیرفعال' }}
            </li>
        @endforeach
    </ul>
</div>
@endsection
