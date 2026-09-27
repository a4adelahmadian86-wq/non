<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject ?? 'فراست' }}</title>
    <style>
        body { margin: 0; padding: 0; background: #0b1220; font-family: Tahoma, 'Segoe UI', Arial, sans-serif; color: #e8eefc; direction: rtl; }
        .wrap { max-width: 600px; margin: 0 auto; padding: 28px 16px; }
        .card { background: linear-gradient(180deg, #121a2f 0%, #0e1628 100%); border: 1px solid #243352; border-radius: 18px; overflow: hidden; box-shadow: 0 18px 50px rgba(0,0,0,.35); }
        .header { padding: 28px 28px 18px; border-bottom: 1px solid #243352; }
        .brand { display: flex; align-items: center; gap: 12px; }
        .logo { width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); display: flex; align-items: center; justify-content: center; font-weight: 800; color: #fff; font-size: 18px; }
        .brand b { display: block; font-size: 18px; color: #fff; }
        .brand small { color: #93a4c7; font-size: 12px; }
        .body { padding: 28px; line-height: 1.9; font-size: 15px; color: #d7e0f5; }
        .body h1 { margin: 0 0 12px; font-size: 22px; color: #fff; }
        .body p { margin: 0 0 14px; }
        .otp { display: inline-block; letter-spacing: 8px; font-size: 28px; font-weight: 800; color: #fff; background: #1a2744; border: 1px solid #35508a; border-radius: 12px; padding: 14px 22px; margin: 10px 0 18px; }
        .btn { display: inline-block; background: linear-gradient(135deg, #3b82f6, #6366f1); color: #fff !important; text-decoration: none; padding: 12px 22px; border-radius: 12px; font-weight: 700; margin-top: 8px; }
        .meta { background: #152038; border: 1px solid #2a3d66; border-radius: 12px; padding: 14px 16px; margin: 16px 0; }
        .meta div { margin: 4px 0; color: #b8c7e6; font-size: 13px; }
        .footer { padding: 18px 28px 26px; color: #8091b5; font-size: 12px; border-top: 1px solid #243352; }
        .muted { color: #93a4c7; font-size: 13px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="header">
            <div class="brand">
                <div class="logo">ف</div>
                <div>
                    <b>FARAST</b>
                    <small>فراست | تایپ و خدمات هوشمند</small>
                </div>
            </div>
        </div>
        <div class="body">
            @yield('content')
        </div>
        <div class="footer">
            <div>این پیام به‌صورت خودکار از سامانه فراست ارسال شده است.</div>
            <div style="margin-top:6px">اگر شما این درخواست را انجام نداده‌اید، کافی است آن را نادیده بگیرید.</div>
        </div>
    </div>
</div>
</body>
</html>
