# استقرار

1. PHP 8.2+، Composer، MySQL و در صورت نیاز Redis نصب شود.
2. `composer install --no-dev --optimize-autoloader`
3. `.env.example` به `.env` کپی و APP_KEY ساخته شود.
4. کلید Gemini، Kavenegar و مشخصات دیتابیس فقط در environment تنظیم شوند.
5. `ADMIN_INITIAL_PASSWORD` را قبل از `php artisan migrate --seed` تنظیم کنید؛ رمز اولیه داخل repository نگهداری نمی‌شود.
6. `php artisan migrate --seed`
7. document root وب‌سرور روی `public/` قرار گیرد و اجرای PHP از خارج آن مسدود باشد.
8. scheduler لاراول برای `farast:purge-files` هر دقیقه اجرا شود تا برنامه زمان‌بندی روزانه را اجرا کند.
9. queue worker برای پردازش‌های سنگین اجرا شود.
10. HTTPS، HSTS، backup رمزنگاری‌شده دیتابیس و محدودیت دسترسی به storage/app/private فعال باشد.

## Files host
در این نسخه فایل ورودی در دیسک خصوصی نگهداری می‌شود. برای انتقال به هاست دانلود فایلز، adapter اختصاصی FilesHost را به Storage اضافه کنید و لینک خروجی را فقط با URL امضاشده و TTL کوتاه ارائه دهید. کلید هاست دانلود هرگز در client قرار نگیرد.

## نکته امنیتی مهم
جلوگیری مطلق از کپی متن در مرورگر ممکن نیست؛ کاربر دارای کنترل کامل روی دستگاه خود می‌تواند DevTools یا ابزارهای ضبط صفحه استفاده کند. این پروژه کپی عادی، cut، context menu و میانبرهای رایج را در ویرایشگر می‌بندد و متن خام را از API عمومی ارائه نمی‌کند. امنیت واقعی باید با کنترل دسترسی سمت سرور و محدود کردن export پیاده شود.

## استقرار تایپ صوتی زنده

سرویس WebSocket در پوشهٔ `voice-stream/` یک سرویس مستقل است. برای استریم زنده، متغیرهای زیر باید در محیط Laravel تنظیم شوند:

- `VOICE_STREAM_URL=wss://<دامنه-سرویس-صوتی>`
- `VOICE_GATEWAY_SECRET`: راز تصادفی قوی؛ مقدار آن باید دقیقاً در سرویس صوتی هم تنظیم شود.
- `APP_KEY`: فقط در Laravel نگهداری شود و مقدار دقیق آن در محیط سرویس صوتی برای اعتبارسنجی توکن کوتاه‌عمر تنظیم شود.

در سرویس `voice-stream` نیز `VOICE_STREAM_HOST=0.0.0.0`، `VOICE_STREAM_APP_URL=https://<دامنه-Laravel>` و `VOICE_STREAM_ORIGINS=https://<دامنه-Laravel>` تنظیم شوند. `VOICE_GATEWAY_SECRET` و `APP_KEY` باید با مقادیر Laravel یکسان باشند. هرگز این مقادیر را در کد یا مخزن قرار ندهید.

اگر فقط `FARAST_VOICE_ASR_URL` و توکن سرویس خودمیزبان تنظیم شده باشند، دکمهٔ «آنلاین» از مسیر HTTP رونویسی قطعه‌ای استفاده می‌کند و به WebSocket مستقل نیاز ندارد. توکن مفقود عمداً باعث fallback خاموش به موتور خارجی نمی‌شود.
