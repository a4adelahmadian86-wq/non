@extends('emails.layout')

@section('content')
    <h1>{{ $headline }}</h1>
    <p>سلام {{ $name }}،</p>
    @if(!empty($ticketCode))
        <p>شماره تیکت: <strong>{{ $ticketCode }}</strong></p>
    @endif
    <p>موضوع: «{{ $subject }}»</p>
    <div class="meta">
        <div>وضعیت: {{ $status }}</div>
        @if(!empty($messageBody))
            <div style="margin-top:8px;white-space:pre-wrap">{{ $messageBody }}</div>
        @endif
    </div>
    <p><a class="btn" href="{{ $supportUrl }}">مشاهده تیکت</a></p>
    <p style="font-size:12px;color:#64748b;margin-top:16px">این پیام از سامانه پشتیبانی فراست ارسال شده است.</p>
@endsection
