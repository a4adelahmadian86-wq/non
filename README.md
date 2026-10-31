# FARAST — AI Typing Platform

پلتفرم تایپ آنلاین فارسی با OCR، تایپ صوتی، ویرایشگر حرفه‌ای، قیمت‌گذاری سروری، احراز OTP و پنل مدیریت.

## معماری
- Laravel 12 / PHP 8.2+
- PostgreSQL یا MySQL
- Redis برای قفل نشست، rate limit و صف
- ذخیره موقت فایل با TTL حداکثر ۱۴ روز
- Gemini به‌صورت provider قابل تعویض
- خروجی DOCX و PDF
- RTL و B Nazanin 16px به‌صورت پیش‌فرض

## اصول امنیتی
- رمزها و API keyها فقط در environment و secret manager؛ هرگز داخل repository
- OTP یک‌بارمصرف، دارای انقضا، محدودیت تلاش و rate limit
- سهم رایگان هفتگی به‌صورت تراکنشی و server-side
- قفل همزمانی حساب با heartbeat و session lease
- فایل‌ها private و دارای URL امضاشده کوتاه‌مدت
- متن و فایل اصلی از client به‌عنوان منبع حقیقت قیمت پذیرفته نمی‌شود
- قیمت فقط از PricingRuleهای فعال سمت سرور محاسبه می‌شود
- export تنها مسیر مجاز برای خروجی گرفتن از متن تایپ‌شده است
- audit log برای عملیات مالی، مدیریت، احراز هویت و export

## وضعیت
این repository از صفر ایجاد شده و skeleton تولیدی آن مرحله‌به‌مرحله تکمیل می‌شود.

## اتصال دستیار صوتی فراست

برای استفاده از تشخیص گفتار و صدای فارسی از سرویس Render، این متغیرها را فقط در فایل محیطی محلی Laravel یا Secret Manager تنظیم کنید؛ مقدار توکن را در مخزن یا گفتگو قرار ندهید:

```dotenv
FARAST_VOICE_ASR_URL=https://voice-typing-r5r7.onrender.com/v1/transcribe
FARAST_VOICE_TTS_URL=https://voice-typing-r5r7.onrender.com/v1/synthesize
FARAST_VOICE_TOKEN=<همان توکن تنظیم‌شده روی سرویس صوتی>
FARAST_VOICE_LOCAL_SPEECH_ENABLED=false
```

اگر حالت گفتار محلی و خصوصی را برای چت حفظ می‌کنید، به‌جای خاموش‌کردن آن، فقط با رضایت صریح خودتان ارسال فایل ضبط‌شده به سرویس صوتی بیرونی را فعال کنید:

```dotenv
FARAST_VOICE_LOCAL_SPEECH_ENABLED=true
FARAST_VOICE_REMOTE_BATCH_ENABLED=true
```

گزینهٔ دوم فقط مسیر ضبط و ارسال صدا به سرویس ASR/TTS را مجاز می‌کند؛ مسیر چت همچنان به درگاه خصوصی مدل زبانی نیاز دارد و به سرویس ابری عمومی برنمی‌گردد. در حالت عادی، وقتی درگاه زنده آماده نباشد، میکروفون فقط پس از لمس کاربر ضبط می‌کند و با لمس دوباره فایل را برای رونویسی ارسال می‌کند. توکن‌های جداگانهٔ `FARAST_VOICE_ASR_TOKEN` و `FARAST_VOICE_TTS_TOKEN` در صورت تنظیم، بر توکن مشترک اولویت دارند.

