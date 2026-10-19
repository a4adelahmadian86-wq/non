<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'اعلان فراست' }}</title>
    <style>
        body { font-family: Tahoma, Vazirmatn, sans-serif; background: #f4f6fb; margin: 0; padding: 24px; color: #1a2332; }
        .card { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 8px 32px rgba(20,40,80,.08); }
        .head { padding: 22px 28px; background: linear-gradient(135deg, #0b1f4a, #1769ff); color: #fff; }
        .head h1 { margin: 0; font-size: 18px; }
        .head span { font-size: 12px; opacity: .85; }
        .body { padding: 24px 28px; line-height: 1.85; font-size: 14px; }
        .type-danger { border-right: 4px solid #e11d48; }
        .type-warning { border-right: 4px solid #f59e0b; }
        .type-success { border-right: 4px solid #10b981; }
        .type-info { border-right: 4px solid #1769ff; }
        .btn { display: inline-block; margin-top: 18px; padding: 10px 20px; background: #1769ff; color: #fff !important; text-decoration: none; border-radius: 10px; font-size: 13px; }
        .foot { padding: 14px 28px; font-size: 11px; color: #8b98aa; border-top: 1px solid #eef1f6; }
    </style>
</head>
<body>
<div class="card type-{{ $type ?? 'info' }}">
    <div class="head">
        <span>فراست · اعلان رسمی</span>
        <h1>{{ $title }}</h1>
    </div>
    <div class="body">
        <p>سلام {{ $name }}،</p>
        <p>{!! nl2br(e($body)) !!}</p>
        @if(!empty($link))
            <a class="btn" href="{{ $link }}">مشاهده جزئیات</a>
        @endif
    </div>
    <div class="foot">این پیام به‌صورت خودکار از سامانه فراست ارسال شده است.</div>
</div>
</body>
</html>
