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

## درگاه چندموتوره هوش مصنوعی Runflare (اختیاری)

کد اولیهٔ درگاه سبک در مخزن [Voice-typing، شاخهٔ runflare-ai-gateway](https://github.com/a4adelahmadian86-wq/Voice-typing/tree/runflare-ai-gateway/runflare-gateway) آماده شده است. این سرویس مدل بزرگ را داخل پلن رایگان Runflare بارگذاری نمی‌کند؛ درخواست‌ها را به ارائه‌دهندگان قابل‌تعویض می‌فرستد و در صورت خرابی، مسیر پشتیبان را امتحان می‌کند.

پس از ایجاد سرویس و بررسی واقعی endpointها، این متغیرها را فقط در environment سمت سرور Laravel تنظیم کنید:

- `FARAST_VOICE_ASR_URL=https://<دامنه-Runflare>/v1/transcribe`
- `FARAST_VOICE_TTS_URL=https://<دامنه-Runflare>/v1/synthesize`
- `FARAST_VOICE_ASR_TOKEN` و `FARAST_VOICE_TTS_TOKEN`: هر دو برابر `FARAST_GATEWAY_TOKEN` همان سرویس Runflare باشند.
- `FARAST_AI_GATEWAY_URL=https://<دامنه-Runflare>`
- `FARAST_AI_GATEWAY_TOKEN`: برابر `FARAST_GATEWAY_TOKEN` باشد.

درگاه AI برای عملیات متنی ویرایشگر ابتدا Gemini و سپس Groq را امتحان می‌کند؛ در صورت تنظیم، مسیر قدیمی مستقیم Gemini در Laravel نیز fallback باقی می‌ماند. تا وقتی متغیرهای `FARAST_AI_GATEWAY_URL` و `FARAST_AI_GATEWAY_TOKEN` خالی‌اند، رفتار فعلی تغییر نمی‌کند. پس از تغییر environment، کش تنظیمات Laravel را پاک کنید.

**حریم خصوصی:** سهمیه رایگان Gemini طبق شرایط فعلی ممکن است از محتوای ارسالی برای بهبود محصولات Google استفاده کند. پیش از ارسال متن محرمانه یا اسناد کاربران، شرایط جاری ارائه‌دهنده و سیاست پردازش/رضایت کاربر را بررسی کنید؛ صرفاً داشتن یک API key به معنای مناسب بودن آن برای داده محرمانه نیست.

سرویس رایگان Runflare پس از سه ماه حذف می‌شود؛ این درگاه stateless است و مدل یا داده کاربر روی دیسک نگه نمی‌دارد. بااین‌حال، ساخت سرویس جایگزین و ثبت مجدد secretها پس از انقضا همچنان باید در پنل Runflare انجام شود؛ اسکریپت مخزن مرحلهٔ deploy کد را ساده می‌کند، نه ایجاد خودکار پروژه حذف‌شده را.
