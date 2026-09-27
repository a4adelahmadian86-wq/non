@extends('emails.layout')

@section('content')
    <h1>پاسخ جدید به تیکت پشتیبانی</h1>
    <p>سلام {{ $name }}،</p>
    <p>به تیکت «{{ $subject }}» پاسخ جدیدی ثبت شده است.</p>
    <div class="meta">
        <div>وضعیت: {{ $status }}</div>
        <div>خلاصه پاسخ:</div>
        <div style="margin-top:8px;white-space:pre-wrap">{{ $messageBody }}</div>
    </div>
    <p><a class="btn" href="{{ $supportUrl }}">مشاهده تیکت</a></p>
@endsection
