<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>محتوای خوانشگر آماده نیست | فراست</title>
<style>
:root{color-scheme:light;--navy:#10233f;--blue:#2563eb;--muted:#64748b;--line:#e2e8f0}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f4f7fb;color:var(--navy);font-family:Tahoma,Arial,sans-serif}
main{width:min(100%,620px);padding:clamp(28px,6vw,48px);border:1px solid var(--line);border-radius:24px;background:#fff;box-shadow:0 24px 70px rgba(15,35,65,.09);text-align:center}
.mark{width:58px;height:58px;margin:0 auto 22px;display:grid;place-items:center;border-radius:18px;background:#fff7ed;color:#c2410c;font-size:28px;font-weight:700}
h1{margin:0 0 14px;font-size:clamp(22px,4vw,28px)}
p{margin:0 auto 18px;max-width:480px;color:var(--muted);font-size:15px;line-height:2}
.detail{margin:18px auto 24px;padding:14px 16px;border:1px solid var(--line);border-radius:14px;background:#f8fafc;text-align:right;line-height:2}
a{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 22px;border-radius:12px;background:var(--blue);color:#fff;text-decoration:none;font-weight:700}
small{display:block;margin-top:20px;color:#94a3b8;font-size:12px}
</style>
</head>
<body>
<main>
<div class="mark" aria-hidden="true">!</div>
<h1>محتوای این خوانشگر هنوز آماده نیست</h1>
@if ($unavailableReason === 'sample_file_missing')
<p>رکورد پیش‌نمایش در سامانه وجود دارد، اما فایل نمونه‌ای که باید نمایش داده شود در فضای ذخیره‌سازی پیدا نشد.</p>
<div class="detail"><strong>علت دقیق:</strong> فایل PDF یا فایل پیش‌نمایش ثبت‌شده برای این محصول موجود نیست یا مسیر ذخیره‌سازی آن قابل دسترسی نیست.<br><strong>اقدام لازم:</strong> فایل نمونه باید دوباره در بخش مدیریت محصول بارگذاری یا از نسخه پشتیبان بازیابی شود. تا آن زمان خوانشگر نمی‌تواند آن را نمایش دهد.</div>
@elseif ($unavailableReason === 'sample_not_configured')
<p>برای این محصول هنوز هیچ پیش‌نمایش عمومی ثبت نشده است.</p>
<div class="detail"><strong>علت دقیق:</strong> محصول فایل نمونه قابل نمایش ندارد؛ این وضعیت به معنی خرابی موتور PDF.js نیست.<br><strong>اقدام لازم:</strong> یک فایل نمونه و رکورد پیش‌نمایش آن باید برای محصول تعریف شود.</div>
@elseif ($unavailableReason === 'storage_unavailable')
<p>سامانه به فضای ذخیره‌سازی فایل نمونه دسترسی پیدا نکرد؛ بنابراین نمی‌تواند با اطمینان بگوید فایل حذف شده است یا فقط مخزن موقتاً در دسترس نیست.</p>
<div class="detail"><strong>علت دقیق:</strong> بررسی وجود فایل در فضای ذخیره‌سازی با خطا مواجه شد؛ این وضعیت با «فایل پیدا نشد» متفاوت است.<br><strong>اقدام لازم:</strong> اتصال دیسک یا Volume مربوط به محصول و گزارش ثبت‌شده در لاگ سرور باید بررسی شود.</div>
@elseif ($unavailableReason === 'document_not_ready')
<p>دسترسی شما به محصول ثبت شده، اما نسخه قابل مطالعه هنوز آماده نشده است.</p>
<div class="detail"><strong>علت دقیق:</strong> پردازش سند کامل نشده یا داده‌های صفحه‌بندی خوانشگر آماده نیستند.<br><strong>اقدام لازم:</strong> وضعیت پردازش سند و خروجی صفحات آن باید در خط پردازش اسناد بررسی شود.</div>
@else
<p>در حال حاضر هیچ محتوای قابل مطالعه‌ای برای این محصول در دسترس نیست.</p>
<div class="detail"><strong>علت دقیق:</strong> هیچ فایل پیش‌نمایش قابل دسترسی یا نسخه آماده خوانش پیدا نشد. این خطا از موتور نمایش PDF تفکیک شده است.</div>
@endif
<a href="{{ route('store.product', $product->slug) }}">بازگشت به صفحه محصول</a>
<small>شناسه محصول: {{ $product->id }} · کد وضعیت: READER_CONTENT_UNAVAILABLE</small>
</main>
</body>
</html>
