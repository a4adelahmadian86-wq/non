<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>خطای موقت | فراست</title>
<style>
:root{color-scheme:light;--navy:#10233f;--blue:#2563eb;--muted:#64748b;--line:#e2e8f0}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f4f7fb;color:var(--navy);font-family:Tahoma,Arial,sans-serif}
main{width:min(100%,560px);padding:clamp(28px,6vw,48px);border:1px solid var(--line);border-radius:24px;background:#fff;box-shadow:0 24px 70px rgba(15,35,65,.09);text-align:center}
.mark{width:58px;height:58px;margin:0 auto 22px;display:grid;place-items:center;border-radius:18px;background:#eff6ff;color:var(--blue);font-size:28px;font-weight:700}
h1{margin:0 0 14px;font-size:clamp(22px,4vw,28px)}
p{margin:0 auto 24px;max-width:420px;color:var(--muted);font-size:15px;line-height:2}
a{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 22px;border-radius:12px;background:var(--blue);color:#fff;text-decoration:none;font-weight:700}
small{display:block;margin-top:20px;color:#94a3b8;font-size:12px}
</style>
</head>
<body>
<main>
<div class="mark" aria-hidden="true">!</div>
<h1>در حال حاضر امکان نمایش این صفحه وجود ندارد</h1>
<p>یک مشکل فنی موقت رخ داده است. اطلاعات لازم برای بررسی ثبت شده است. لطفاً چند لحظه دیگر دوباره تلاش کنید.</p>
<a href="{{ url('/') }}">بازگشت به صفحه اصلی</a>
@if (!empty($requestId))
<small>شناسه پیگیری: {{ $requestId }}</small>
@endif
</main>
</body>
</html>
