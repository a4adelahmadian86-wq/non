@extends('emails.layout')

@section('content')
    <h1>ایمیل آزمایشی فراست</h1>
    <p>اگر این پیام را دریافت کرده‌اید، پیکربندی SMTP و سیستم ایمیل به‌درستی کار می‌کند.</p>
    <div class="meta">
        <div>زمان ارسال: {{ now()->format('Y/m/d H:i') }}</div>
        <div>محیط: {{ config('app.env') }}</div>
    </div>
@endsection
