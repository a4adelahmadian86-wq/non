@extends('emails.layout')

@section('content')
    <h1>پرداخت با موفقیت ثبت شد</h1>
    <p>سلام {{ $name }}،</p>
    <p>سفارش شما با موفقیت پرداخت شد و سند آماده خروجی است.</p>
    <div class="meta">
        <div>شماره سفارش: #{{ $orderId }}</div>
        <div>مبلغ: {{ number_format($amount) }} ریال</div>
        <div>زمان: {{ $paidAt }}</div>
    </div>
    <p><a class="btn" href="{{ $editorUrl }}">بازگشت به ویرایشگر</a></p>
@endsection
