@extends('emails.layout')

@section('content')
    <h1>خوش آمدید به فراست</h1>
    <p>سلام {{ $name }}،</p>
    <p>حساب شما با موفقیت ساخته شد. از این لحظه می‌توانید از ویرایشگر حرفه‌ای، OCR، تایپ صوتی و خروجی Word/PDF استفاده کنید.</p>
    <div class="meta">
        <div>شماره موبایل: {{ $mobile }}</div>
        @if(!empty($email))
            <div>ایمیل: {{ $email }}</div>
        @endif
    </div>
    <p><a class="btn" href="{{ $editorUrl }}">ورود به ویرایشگر</a></p>
@endsection
