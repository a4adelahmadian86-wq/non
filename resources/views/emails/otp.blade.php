@extends('emails.layout')

@section('content')
    <h1>کد تأیید ورود</h1>
    <p>کد یک‌بارمصرف شما برای ورود به فراست:</p>
    <div class="otp">{{ $code }}</div>
    <p class="muted">این کد تا ۳ دقیقه معتبر است. آن را با کسی به اشتراک نگذارید.</p>
@endsection
